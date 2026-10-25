<?php

declare(strict_types=1);

namespace Tests\Feature\Downloads;

use App\Services\Downloads\QBittorrentClient;
use RuntimeException;
use App\Models\Movie;
use App\Models\MovieMedia;
use App\Models\User;
use App\Services\Downloads\FileLinkService;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;
use Tests\TestCase;

final class DownloadsTest extends TestCase
{
    use RefreshDatabase;

    private const HASH = 'aabbccddeeff00112233445566778899aabbccdd';
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::forgetDefaults();
        $this->app->instance(ConfigStore::class, new FakeConfigStore());
        $this->app->forgetInstance(SettingsService::class);
        $this->dir = sys_get_temp_dir() . '/javmeta-dl-' . uniqid();
        mkdir($this->dir . '/movie', 0777, true);
        file_put_contents($this->dir . '/movie/video.mp4', '0123456789');
        file_put_contents(sys_get_temp_dir() . '/javmeta-outside-' . basename($this->dir) . '.txt', 'secret');
        $s = app(SettingsService::class);
        $s->set('downloads.qbittorrent_url', 'http://qbt.test:8080');
        $s->set('downloads.qbittorrent_username', 'admin');
        $s->set('downloads.qbittorrent_password', 'pw');
        $s->set('downloads.save_path', $this->dir);
        $this->actingAs(User::factory()->create(['role' => 'super_administrator']));
    }

    protected function tearDown(): void
    {
        SettingsService::forgetDefaults();
        exec('rm -rf ' . escapeshellarg($this->dir) . ' ' . escapeshellarg(sys_get_temp_dir() . '/javmeta-outside-' . basename($this->dir) . '.txt'));
        parent::tearDown();
    }

    private function movieWithMagnet(string $code = 'DLD-001', ?string $url = null): Movie
    {
        $movie = Movie::factory()->create(['display_code' => $code]);
        MovieMedia::query()->create(['movie_id' => $movie->id, 'kind' => 'magnet', 'url' => $url ?? 'magnet:?xt=urn:btih:' . self::HASH . '&dn=x', 'source_slug' => 's', 'crawled_at' => now()]);

        return $movie;
    }

    private function fakeQbt(?array $torrents = null): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            'qbt.test:8080/api/v2/auth/login' => Http::response('Ok.', 200, ['Set-Cookie' => 'SID=abc123; path=/']),
            'qbt.test:8080/api/v2/torrents/add' => Http::response('Ok.'),
            'qbt.test:8080/api/v2/torrents/info*' => Http::response($torrents ?? [['hash' => self::HASH, 'name' => 'video', 'state' => 'downloading', 'progress' => 0.5, 'size' => 1000, 'dlspeed' => 200, 'eta' => 60, 'num_seeds' => 4, 'save_path' => $this->dir, 'tags' => 'DLD-001']]),
            'qbt.test:8080/api/v2/torrents/files*' => Http::response([['name' => 'movie/video.mp4', 'progress' => 1.0]]),
            'qbt.test:8080/api/v2/torrents/*' => Http::response('Ok.'),
        ]);
    }

    public function test_everything_is_refused_while_downloads_are_switched_off(): void
    {
        $this->movieWithMagnet();

        $this->postJson('/ui-me/downloads', ['code' => 'DLD-001'])->assertStatus(422)->assertJsonPath('message', 'Downloads are switched off in Settings.');
        $this->getJson('/ui-me/downloads')->assertStatus(422);
        $this->getJson('/ui-me/downloads/status')->assertOk()->assertJsonPath('data.enabled', false);
    }

    public function test_only_a_magnet_that_javmeta_stored_for_a_known_movie_is_accepted(): void
    {
        app(SettingsService::class)->set('downloads.enabled', true);
        $this->fakeQbt();
        $this->movieWithMagnet('DLD-001');
        $this->movieWithMagnet('DLD-002', 'http://example.test/not-a-magnet');
        Movie::factory()->create(['display_code' => 'DLD-003']);

        $this->postJson('/ui-me/downloads', ['code' => 'NOPE-999'])->assertStatus(422)->assertJsonPath('message', 'Unknown movie.');
        $this->postJson('/ui-me/downloads', ['code' => 'DLD-003'])->assertStatus(422)->assertJsonPath('message', 'JavMeta has no magnet for this movie.');
        $this->postJson('/ui-me/downloads', ['code' => 'DLD-002'])->assertStatus(422);
        Http::assertNotSent(fn($r) => str_contains($r->url(), '/torrents/add'));

        $this->postJson('/ui-me/downloads', ['code' => 'dld-001'])->assertOk()->assertJsonPath('data.hash', self::HASH);
        Http::assertSent(fn($r) => str_contains($r->url(), '/torrents/add') && $r['category'] === 'javmeta' && $r['tags'] === 'DLD-001' && str_starts_with($r['urls'], 'magnet:?xt=urn:btih:' . self::HASH));
        $this->assertSame(1, DB::table('download_items')->count());
    }

    public function test_the_list_shows_progress_speed_eta_and_state_and_pause_resume_remove_work_only_on_our_torrents(): void
    {
        app(SettingsService::class)->set('downloads.enabled', true);
        $this->fakeQbt();

        $item = $this->getJson('/ui-me/downloads')->assertOk()->json('data.items.0');
        $this->assertEquals(['hash' => self::HASH, 'movie_code' => 'DLD-001', 'state' => 'downloading', 'progress' => 50, 'speed' => 200, 'eta' => 60, 'completed' => false], array_intersect_key($item, array_flip(['hash', 'movie_code', 'state', 'progress', 'speed', 'eta', 'completed'])));

        $this->postJson('/ui-me/downloads/' . self::HASH . '/pause')->assertOk();
        $this->postJson('/ui-me/downloads/' . self::HASH . '/resume')->assertOk();
        $this->deleteJson('/ui-me/downloads/' . self::HASH . '?files=1')->assertOk();
        Http::assertSent(fn($r) => str_contains($r->url(), '/torrents/delete') && $r['deleteFiles'] === 'true');
        Http::assertSent(fn($r) => str_contains($r->url(), '/torrents/pause') && $r['hashes'] === self::HASH);

        $this->postJson('/ui-me/downloads/ffffffffffffffffffffffffffffffffffffffff/pause')->assertStatus(422)->assertJsonPath('message', 'Unknown download.');
    }

    public function test_a_finished_file_is_served_only_through_a_valid_signed_link_from_the_home_network(): void
    {
        $s = app(SettingsService::class);
        $s->set('downloads.enabled', true);
        $s->set('files.enabled', true);
        $this->fakeQbt();

        $links = $this->getJson('/ui-me/downloads/' . self::HASH . '/links')->assertOk()->json('data.links');
        $this->assertSame('video.mp4', $links[0]['name']);
        $url = $links[0]['url'];

        $this->get($url)->assertOk();
        $this->assertSame('0123456789', file_get_contents($this->dir . '/movie/video.mp4'));
        $this->get($url, ['Range' => 'bytes=0-3'])->assertStatus(206);
        $this->get($url . '1')->assertStatus(403);
        $this->get(preg_replace('/signature=[a-f0-9]+/', 'signature=bad', $url))->assertStatus(403);
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->get($url)->assertStatus(403);

        $this->travel(2)->hours();
        $this->get($url)->assertStatus(403);
        $this->travelBack();

        $s->set('files.enabled', false);
        $this->get($url)->assertStatus(404);
    }

    public function test_a_path_outside_the_served_folders_never_gets_a_link_or_a_file(): void
    {
        $s = app(SettingsService::class);
        $s->set('files.enabled', true);
        $links = app(FileLinkService::class);
        $outside = sys_get_temp_dir() . '/javmeta-outside-' . basename($this->dir) . '.txt';

        $this->assertNull($links->resolve($outside));
        $this->assertNull($links->resolve($this->dir . '/movie/../../' . basename($outside)));
        $this->expectException(RuntimeException::class);
        $links->url($outside);
    }

    public function test_file_types_are_extension_based_and_sandboxed_including_ranges(): void
    {
        app(SettingsService::class)->set('files.enabled', true);
        foreach (['html', 'svg', 'xml', 'js', 'pdf', 'txt', 'unknown', 'MP4'] as $extension) {
            $path = $this->dir . '/movie/payload.' . $extension;
            file_put_contents($path, '<script>alert(document.cookie)</script>');
            $url = app(FileLinkService::class)->url($path);
            foreach ([[], ['Range' => 'bytes=0-3'], ['Range' => 'bytes=9999-10000']] as $headers) {
                $response = $this->get($url, $headers);
                $response->assertStatus($headers === [] ? 200 : ($headers['Range'] === 'bytes=0-3' ? 206 : 416));
                $response->assertHeader('X-Content-Type-Options', 'nosniff');
                $response->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'; media-src 'self'; img-src 'self'");
                $response->assertHeader('Content-Type', $extension === 'MP4' ? 'video/mp4' : 'application/octet-stream');
                $this->assertStringStartsWith($extension === 'MP4' ? 'inline;' : 'attachment;', $response->headers->get('Content-Disposition'));
            }
        }
    }

    public function test_base32_download_is_stored_joined_and_removed_using_hex_hash(): void
    {
        app(SettingsService::class)->set('downloads.enabled', true);
        $hash = str_repeat('0', 40);
        $this->movieWithMagnet('B32-001', 'magnet:?xt=urn:btih:' . str_repeat('A', 32));
        $this->fakeQbt([['hash' => $hash, 'tags' => '', 'progress' => 1]]);
        $this->postJson('/ui-me/downloads', ['code' => 'B32-001'])->assertOk()->assertJsonPath('data.hash', $hash);
        $this->assertDatabaseHas('download_items', ['hash' => $hash]);
        $this->getJson('/ui-me/downloads')->assertOk()->assertJsonPath('data.items.0.movie_code', 'B32-001');
        $this->deleteJson('/ui-me/downloads/' . $hash)->assertOk();
        $this->assertDatabaseCount('download_items', 0);
    }

    public function test_magnet_hash_parser_accepts_only_complete_valid_btih_values(): void
    {
        $client = app(QBittorrentClient::class);
        foreach ([self::HASH, strtoupper(self::HASH)] as $hash) {
            $this->assertSame(self::HASH, $client->hashOf('magnet:?xt=urn:btih:' . $hash));
        }
        foreach (['AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'] as $hash) {
            $this->assertSame(str_repeat('0', 40), $client->hashOf('magnet:?xt=urn:btih:' . $hash));
        }
        $this->assertSame('00443214c74254b635cf84653a56d7c675be77df', $client->hashOf('magnet:?xt=urn:btih:ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'));
        $this->assertSame(self::HASH, $client->hashOf('magnet:?xt=urn:sha1:wrong&xt=urn:btih:bad&xt=urn%3Abtih%3A' . self::HASH . '&xt=urn:btih:' . str_repeat('A', 32)));
        foreach ([...range(33, 39), 44] as $length) {
            $this->assertInvalidMagnet($client, 'magnet:?xt=urn:btih:' . str_repeat('a', $length));
        }
        foreach ([str_repeat('0', 32), str_repeat('Z', 40), str_repeat('A', 31) . '8', ''] as $hash) {
            $this->assertInvalidMagnet($client, 'magnet:?xt=urn:btih:' . $hash);
        }
        $this->assertInvalidMagnet($client, 'magnet:?dn=no-btih');
    }

    private function assertInvalidMagnet(QBittorrentClient $client, string $magnet): void
    {
        try {
            $client->hashOf($magnet);
            $this->fail('Invalid magnet accepted.');
        } catch (RuntimeException $error) {
            $this->assertSame('Not a magnet link.', $error->getMessage());
        }
    }
}
