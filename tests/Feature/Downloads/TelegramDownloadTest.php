<?php

declare(strict_types=1);

namespace Tests\Feature\Downloads;

use App\Models\Movie;
use App\Models\MovieMedia;
use App\Models\User;
use App\Services\Notifications\FollowNotifier;
use App\Services\Notifications\TelegramBot;
use App\Services\Settings\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;
use Tests\TestCase;

final class TelegramDownloadTest extends TestCase
{
    use RefreshDatabase;

    private const HASH = 'aabbccddeeff00112233445566778899aabbccdd';

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::forgetDefaults();
        $this->app->instance(ConfigStore::class, new FakeConfigStore());
        $this->app->forgetInstance(SettingsService::class);
        config(['app.url' => 'https://jav.example.test']);
        $owner = User::factory()->create(['role' => 'super_administrator']);
        $s = app(SettingsService::class);
        foreach (['telegram.bot_token' => '123:t', 'telegram.chat_id' => '555', 'telegram.api_base' => 'https://api.telegram.test', 'telegram.bot_enabled' => true, 'downloads.qbittorrent_url' => 'http://qbt.test:8080', 'downloads.save_path' => '/tmp'] as $k => $v) {
            $s->set($k, $v);
        }
        $movie = Movie::factory()->create(['display_code' => 'TGD-001', 'maker' => 'Followed', 'cover_url' => null, 'first_seen_at' => now()->subHour()]);
        MovieMedia::query()->create(['movie_id' => $movie->id, 'kind' => 'magnet', 'url' => 'magnet:?xt=urn:btih:' . self::HASH, 'source_slug' => 's', 'crawled_at' => now()]);
        DB::table('user_marks')->insert(['user_id' => $owner->id, 'kind' => 'follow', 'subject' => 'maker', 'key' => 'Followed', 'created_at' => now()]);
    }

    protected function tearDown(): void
    {
        SettingsService::forgetDefaults();
        parent::tearDown();
    }

    private function fake(array $updates = []): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            'api.telegram.test/*getUpdates*' => Http::response(['ok' => true, 'result' => $updates]),
            'api.telegram.test/*' => Http::response(['ok' => true]),
            'qbt.test:8080/api/v2/auth/login' => Http::response('Ok.', 200, ['Set-Cookie' => 'SID=x; path=/']),
            'qbt.test:8080/api/v2/torrents/add' => Http::response('Ok.'),
            'qbt.test:8080/api/v2/torrents/info*' => Http::response([['hash' => self::HASH, 'name' => 'n', 'state' => 'downloading', 'progress' => 0.1, 'tags' => 'TGD-001']]),
        ]);
    }

    public function test_the_notification_gets_a_download_button_only_when_downloads_are_on(): void
    {
        $this->fake();
        app(FollowNotifier::class)->run(CarbonImmutable::parse('2026-10-24 12:00:00', 'Asia/Ho_Chi_Minh'));
        Http::assertSent(fn($r) => str_contains($r->url(), '/sendMessage') && count($r['reply_markup']['inline_keyboard'][0]) === 1);

        DB::table('follow_notifications')->delete();
        app(SettingsService::class)->set('downloads.enabled', true);
        $this->fake();
        app(FollowNotifier::class)->run(CarbonImmutable::parse('2026-10-24 12:00:00', 'Asia/Ho_Chi_Minh'));
        Http::assertSent(fn($r) => str_contains($r->url(), '/sendMessage') && ($r['reply_markup']['inline_keyboard'][0][1]['callback_data'] ?? null) === 'dl:TGD-001');
    }

    public function test_the_download_button_and_commands_start_and_list_downloads_for_the_owner_only(): void
    {
        app(SettingsService::class)->set('downloads.enabled', true);

        $this->fake([['update_id' => 1, 'callback_query' => ['id' => 'q1', 'data' => 'dl:TGD-001', 'message' => ['chat' => ['id' => 555]]]]]);
        $this->assertSame(1, app(TelegramBot::class)->poll()['handled']);
        Http::assertSent(fn($r) => str_contains($r->url(), '/torrents/add') && $r['tags'] === 'TGD-001');
        Http::assertSent(fn($r) => str_contains($r->url(), 'answerCallbackQuery') && str_contains($r['text'], 'TGD-001'));

        $this->fake([['update_id' => 2, 'callback_query' => ['id' => 'q2', 'data' => 'dl:TGD-001', 'message' => ['chat' => ['id' => 999]]]]]);
        $this->assertSame(0, app(TelegramBot::class)->poll()['handled']);
        Http::assertNotSent(fn($r) => str_contains($r->url(), '/torrents/add'));

        $this->fake([['update_id' => 3, 'message' => ['chat' => ['id' => 555], 'text' => '/downloads']]]);
        app(TelegramBot::class)->poll();
        Http::assertSent(fn($r) => str_contains($r->url(), '/sendMessage') && str_contains($r['text'], 'TGD-001 · 10% · downloading'));
    }

    public function test_download_commands_say_so_while_downloads_are_off(): void
    {
        $this->fake([['update_id' => 4, 'message' => ['chat' => ['id' => 555], 'text' => '/download TGD-001']]]);
        app(TelegramBot::class)->poll();

        Http::assertSent(fn($r) => str_contains($r->url(), '/sendMessage') && str_contains($r['text'], 'đang tắt'));
        Http::assertNotSent(fn($r) => str_contains($r->url(), '/torrents/add'));
    }
}
