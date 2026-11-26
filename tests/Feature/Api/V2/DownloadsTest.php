<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V2;

use App\Models\Movie;
use App\Models\MovieCode;
use App\Models\MovieDownloadSearch;
use App\Models\MovieMedia;
use App\Models\User;
use App\Services\Api\V2\Downloads\FileLinks;
use App\Services\Api\V2\Downloads\LinkSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

final class DownloadsTest extends TestCase
{
    use RefreshDatabase;

    private const MAGNET = 'magnet:?xt=urn:btih:ABCDEF0123456789&dn=x';

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('api-v2-download-request');
    }

    private function movie(string $code = 'SSIS-001'): Movie
    {
        $movie = Movie::factory()->create(['display_code' => $code, 'code_normalized' => 'SSIS001']);
        MovieCode::factory()->create(['movie_id' => $movie->id, 'code' => $code, 'code_normalized' => 'SSIS001']);

        return $movie;
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'user']);
    }

    private function csrf(User $user): string
    {
        $this->actingAs($user, 'web')->getJson('/api/v2/sessions/current');

        return (string) $this->app['session.store']->token();
    }

    /** @return array<string, string> */
    private function signed(User $user): array
    {
        return ['X-CSRF-Token' => $this->csrf($user)];
    }

    private function searchStarts(bool $queued): void
    {
        $this->app->instance(LinkSearch::class, new class ($queued) implements LinkSearch {
            public function __construct(private readonly bool $queued) {}

            public function start(string $movieCode, string $clientKey): bool
            {
                return $this->queued;
            }
        });
    }

    public function test_a_guest_asks_for_links_and_the_same_open_request_comes_back(): void
    {
        $this->movie();
        $this->searchStarts(true);

        $first = $this->postJson('/api/v2/download-requests', ['movie_code' => 'ssis-001'])->assertCreated()->assertJsonPath('data.movie_code', 'SSIS-001')->assertJsonPath('data.state', 'queued')->assertJsonPath('data.attempts', 1);
        $id = $first->json('data.id');
        $first->assertHeader('Location', "/api/v2/download-requests/{$id}");

        $this->postJson('/api/v2/download-requests', ['movie_code' => 'SSIS-001'])->assertOk()->assertJsonPath('data.id', $id);
        $this->getJson('/api/v2/download-requests')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v2/download-requests/{$id}")->assertOk()->assertJsonPath('data.id', $id);
    }

    public function test_requests_belong_to_their_requester(): void
    {
        $this->movie();
        $this->searchStarts(false);
        $id = $this->postJson('/api/v2/download-requests', ['movie_code' => 'SSIS-001'])->assertCreated()->json('data.id');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->getJson("/api/v2/download-requests/{$id}")->assertNotFound()->assertJsonPath('code', 'request_not_found');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->getJson('/api/v2/download-requests')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_unknown_movie_is_404_and_a_missing_code_is_422(): void
    {
        $this->postJson('/api/v2/download-requests', ['movie_code' => 'NOPE-1'])->assertNotFound()->assertJsonPath('code', 'movie_not_found');
        $this->postJson('/api/v2/download-requests', [])->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonFragment(['field' => 'movie_code', 'code' => 'required']);
    }

    public function test_a_signed_in_user_asks_with_csrf_and_a_request_follows_its_search(): void
    {
        $movie = $this->movie();
        $this->searchStarts(true);
        $user = $this->owner();
        $headers = $this->signed($user);

        $this->postJson('/api/v2/download-requests', ['movie_code' => 'SSIS-001'])->assertForbidden()->assertJsonPath('code', 'csrf_mismatch');
        $id = $this->postJson('/api/v2/download-requests', ['movie_code' => 'SSIS-001'], $headers)->assertCreated()->json('data.id');

        MovieDownloadSearch::query()->create(['movie_id' => $movie->id, 'state' => 'searching', 'found_count' => 0]);
        $this->getJson("/api/v2/download-requests/{$id}")->assertJsonPath('data.state', 'searching');

        MovieDownloadSearch::query()->where('movie_id', $movie->id)->update(['state' => 'found', 'found_count' => 3]);
        $this->getJson("/api/v2/download-requests/{$id}")->assertJsonPath('data.state', 'found')->assertJsonPath('data.found_count', 3);

        MovieDownloadSearch::query()->where('movie_id', $movie->id)->update(['state' => 'idle']);
        $this->getJson("/api/v2/download-requests/{$id}")->assertJsonPath('data.state', 'found');
    }

    public function test_a_search_that_finds_nothing_fails_the_request(): void
    {
        $movie = $this->movie();
        $this->searchStarts(false);
        $id = $this->postJson('/api/v2/download-requests', ['movie_code' => 'SSIS-001'])->json('data.id');
        MovieDownloadSearch::query()->create(['movie_id' => $movie->id, 'state' => 'not_found', 'found_count' => 0]);

        $this->getJson("/api/v2/download-requests/{$id}")->assertJsonPath('data.state', 'failed');
    }

    public function test_a_request_can_be_cancelled_and_then_leaves_the_list(): void
    {
        $this->movie();
        $this->searchStarts(false);
        $id = $this->postJson('/api/v2/download-requests', ['movie_code' => 'SSIS-001'])->json('data.id');

        $this->deleteJson("/api/v2/download-requests/{$id}")->assertNoContent();
        $this->getJson("/api/v2/download-requests/{$id}")->assertOk()->assertJsonPath('data.state', 'cancelled');
        $this->getJson('/api/v2/download-requests')->assertJsonCount(0, 'data');
    }

    public function test_asking_for_links_is_limited_to_eight_per_minute(): void
    {
        $this->movie();
        $this->searchStarts(false);

        for ($i = 0; $i < 8; ++$i) {
            $this->postJson('/api/v2/download-requests', ['movie_code' => 'SSIS-001'])->assertSuccessful();
        }

        $this->postJson('/api/v2/download-requests', ['movie_code' => 'SSIS-001'])->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
    }

    public function test_the_owner_routes_need_the_owner_scope(): void
    {
        foreach (['library', 'library/files', 'library/codes', 'download-status', 'download-items', 'download-items/abc', 'download-items/abc/links', 'media-jobs', 'media-files'] as $path) {
            $this->getJson("/api/v2/{$path}")->assertUnauthorized()->assertJsonPath('code', 'authentication_required');
        }

        $this->postJson('/api/v2/download-items', [])->assertUnauthorized();
        $this->postJson('/api/v2/media-jobs', [])->assertUnauthorized();
        $this->patchJson('/api/v2/media-jobs/1', [])->assertUnauthorized();
        $this->patchJson('/api/v2/download-items/abc', [])->assertUnauthorized();
        $this->deleteJson('/api/v2/download-items/abc')->assertUnauthorized();
    }

    public function test_the_library_summary_files_and_codes(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'web');
        $this->getJson('/api/v2/library')->assertOk()->assertJsonPath('data.files', 0)->assertJsonPath('data.media.jobs', 0)->assertJsonPath('data.note', fn($note) => is_string($note));

        $this->file('/m/a.mp4', 'AAA-001', 100);
        $this->file('/m/b.mp4', null, 50);
        $this->file('/m/c.mp4', 'CCC-003', 25, missing: true);

        $this->getJson('/api/v2/library')->assertJsonPath('data.files', 3)->assertJsonPath('data.matched', 2)->assertJsonPath('data.unmatched', 1)->assertJsonPath('data.missing', 1)->assertJsonPath('data.bytes', 175)->assertJsonPath('data.note', null);
        $this->getJson('/api/v2/library/files?match=unmatched')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.path', '/m/b.mp4')->assertJsonPath('meta.pagination.total', 1);
        $this->getJson('/api/v2/library/files?match=matched&per_page=1&page=2')->assertJsonCount(1, 'data')->assertJsonPath('meta.pagination.pages', 2);
        $this->getJson('/api/v2/library/files')->assertJsonCount(3, 'data');
        $this->getJson('/api/v2/library/files?per_page=101')->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->getJson('/api/v2/library/files?match=x')->assertStatus(422);
        $this->getJson('/api/v2/library/codes')->assertOk()->assertExactJson(['data' => ['AAA-001', 'CCC-003'], 'meta' => []]);
    }

    public function test_media_files_lists_only_files_with_media(): void
    {
        $this->actingAs($this->owner(), 'web');
        $withJob = $this->file('/m/a.mp4', 'AAA-001', 10);
        $withScene = $this->file('/m/s.mp4', null, 11);
        $withSubtitle = $this->file('/m/t.mp4', null, 12);
        $this->file('/m/none.mp4', null, 13);
        DB::table('media_jobs')->insert(['kind' => 'thumbnails', 'library_file_id' => $withJob, 'params' => '{}', 'status' => 'done', 'created_at' => now()]);
        DB::table('media_scenes')->insert(['library_file_id' => $withScene, 'start_sec' => 0, 'end_sec' => 5, 'thumb_path' => '/o/t.jpg']);
        DB::table('media_subtitles')->insert(['library_file_id' => $withSubtitle, 'language' => 'en', 'format' => 'srt', 'path' => '/o/t.srt', 'source' => 'whisper', 'created_at' => now()]);

        $this->getJson('/api/v2/media-files')->assertOk()->assertJsonCount(3, 'data')->assertJsonMissing(['path' => '/m/none.mp4']);
    }

    public function test_download_items_are_added_paused_resumed_and_removed(): void
    {
        $owner = $this->owner();
        $headers = $this->signed($owner);

        $this->postJson('/api/v2/download-items', ['magnet' => 'nonsense'], $headers)->assertStatus(422)->assertJsonFragment(['field' => 'magnet', 'code' => 'regex']);
        $this->postJson('/api/v2/download-items', [], $headers)->assertStatus(422)->assertJsonFragment(['field' => 'magnet', 'code' => 'required']);

        $created = $this->postJson('/api/v2/download-items', ['magnet' => self::MAGNET, 'movie_code' => ' ssis-001 '], $headers)->assertCreated()
            ->assertJsonPath('data.hash', 'abcdef0123456789')->assertJsonPath('data.state', 'queued')->assertJsonPath('data.progress', 0)->assertJsonPath('data.movie_code', 'SSIS-001')->assertJsonPath('data.size_bytes', null);
        $created->assertHeader('Location', '/api/v2/download-items/abcdef0123456789');

        $this->postJson('/api/v2/download-items', ['magnet' => self::MAGNET], $headers)->assertConflict()->assertJsonPath('code', 'already_added');
        $this->postJson('/api/v2/download-items', ['magnet' => 'magnet:?xt=urn:btih:FFFF&dn=y', 'movie_code' => ' '], $headers)->assertCreated()->assertJsonPath('data.movie_code', null);

        $this->getJson('/api/v2/download-items')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.pagination.total', 2);
        $this->getJson('/api/v2/download-items/ABCDEF0123456789')->assertOk()->assertJsonPath('data.hash', 'abcdef0123456789');
        $this->getJson('/api/v2/download-items/missing')->assertNotFound()->assertJsonPath('code', 'download_not_found');

        $this->patchJson('/api/v2/download-items/abcdef0123456789', ['state' => 'running'], $headers)->assertOk()->assertJsonPath('data.state', 'running');
        $this->patchJson('/api/v2/download-items/abcdef0123456789', ['state' => 'paused'], $headers)->assertOk()->assertJsonPath('data.state', 'paused');
        $this->patchJson('/api/v2/download-items/abcdef0123456789', ['state' => 'bogus'], $headers)->assertStatus(422);
        $this->patchJson('/api/v2/download-items/missing', ['state' => 'running'], $headers)->assertNotFound();
        $this->patchJson('/api/v2/download-items/abcdef0123456789', ['state' => 'running'])->assertForbidden()->assertJsonPath('code', 'csrf_mismatch');

        $this->getJson('/api/v2/download-status')->assertOk()->assertJsonPath('data.total', 2)->assertJsonPath('data.paused', 1)->assertJsonPath('data.running', 0)->assertJsonPath('data.client', 'not_connected');

        $this->deleteJson('/api/v2/download-items/abcdef0123456789', [], $headers)->assertNoContent();
        $this->deleteJson('/api/v2/download-items/abcdef0123456789', [], $headers)->assertNoContent();
        $this->getJson('/api/v2/download-items/abcdef0123456789')->assertNotFound();
    }

    public function test_item_links_are_empty_without_a_served_file(): void
    {
        $headers = $this->signed($this->owner());
        $this->postJson('/api/v2/download-items', ['magnet' => self::MAGNET, 'movie_code' => 'SSIS-001'], $headers)->assertCreated();
        $this->postJson('/api/v2/download-items', ['magnet' => 'magnet:?xt=urn:btih:AAAA'], $headers)->assertCreated();

        $this->getJson('/api/v2/download-items/abcdef0123456789/links')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/v2/download-items/aaaa/links')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/v2/download-items/missing/links')->assertNotFound()->assertJsonPath('code', 'download_not_found');
    }

    public function test_item_links_point_at_served_files_of_the_movie(): void
    {
        $this->app->instance(FileLinks::class, new class implements FileLinks {
            public function linkFor(string $path): ?string
            {
                return str_ends_with($path, 'a.mp4') ? 'https://files.test/' . basename($path) : null;
            }
        });
        $headers = $this->signed($this->owner());
        $this->postJson('/api/v2/download-items', ['magnet' => self::MAGNET, 'movie_code' => 'SSIS-001'], $headers)->assertCreated();
        $this->file('/m/a.mp4', 'SSIS-001', 7);
        $this->file('/m/gone.mp4', 'SSIS-001', 1);

        $this->getJson('/api/v2/download-items/abcdef0123456789/links')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'a.mp4')->assertJsonPath('data.0.size', 7)->assertJsonPath('data.0.url', 'https://files.test/a.mp4');
    }

    public function test_media_jobs_are_created_listed_and_retried(): void
    {
        $owner = $this->owner();
        $headers = $this->signed($owner);
        $file = $this->file('/m/a.mp4', 'AAA-001', 10);

        $this->postJson('/api/v2/media-jobs', ['kind' => 'bogus'], $headers)->assertStatus(422)->assertJsonFragment(['field' => 'kind', 'code' => 'in']);
        $this->postJson('/api/v2/media-jobs', ['kind' => 'thumbnail'], $headers)->assertStatus(422)->assertJsonFragment(['field' => 'params.library_file_id', 'code' => 'required']);
        $this->postJson('/api/v2/media-jobs', ['kind' => 'thumbnail', 'params' => ['library_file_id' => 999]], $headers)->assertStatus(422)->assertJsonFragment(['field' => 'params.library_file_id', 'code' => 'exists']);
        $this->postJson('/api/v2/media-jobs', ['kind' => 'capture'], $headers)->assertStatus(422)->assertJsonFragment(['field' => 'source_url', 'code' => 'required']);
        $this->postJson('/api/v2/media-jobs', ['kind' => 'capture', 'source_url' => 'ftp://x/y'], $headers)->assertStatus(422)->assertJsonFragment(['field' => 'source_url', 'code' => 'invalid']);
        $this->postJson('/api/v2/media-jobs', ['kind' => 'capture', 'source_url' => 'https://example.test/live.m3u8'], $headers)->assertConflict()->assertJsonPath('code', 'capture_disabled');

        $thumb = $this->postJson('/api/v2/media-jobs', ['kind' => 'thumbnail', 'params' => ['library_file_id' => $file]], $headers)->assertCreated()->assertJsonPath('data.kind', 'thumbnail')->assertJsonPath('data.status', 'pending');
        $thumb->assertHeader('Location', '/api/v2/media-jobs/' . $thumb->json('data.id'));
        $this->assertSame('thumbnails', DB::table('media_jobs')->where('id', $thumb->json('data.id'))->value('kind'));
        $this->postJson('/api/v2/media-jobs', ['kind' => 'subtitle', 'params' => ['library_file_id' => $file]], $headers)->assertCreated()->assertJsonPath('data.kind', 'subtitle');

        config(['javmeta_capture.enabled' => true]);
        $capture = $this->postJson('/api/v2/media-jobs', ['kind' => 'capture', 'source_url' => 'https://example.test/live.m3u8'], $headers)->assertCreated()->assertJsonPath('data.kind', 'capture')->assertJsonPath('data.source_url', 'https://example.test/live.m3u8');

        $this->getJson('/api/v2/media-jobs?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.pagination.total', 3)->assertJsonPath('meta.pagination.pages', 2);

        $id = $capture->json('data.id');
        $this->patchJson("/api/v2/media-jobs/{$id}", ['state' => 'queued'], $headers)->assertConflict()->assertJsonPath('code', 'not_retryable');
        DB::table('media_jobs')->where('id', $id)->update(['status' => 'failed', 'error' => 'boom', 'attempts' => 3]);
        $this->patchJson("/api/v2/media-jobs/{$id}", ['state' => 'queued'], $headers)->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.attempts', 0)->assertJsonPath('data.error', null);
        $this->patchJson("/api/v2/media-jobs/{$id}", ['state' => 'done'], $headers)->assertStatus(422);
        $this->patchJson('/api/v2/media-jobs/999', ['state' => 'queued'], $headers)->assertConflict();
    }

    public function test_playback_hands_out_hls_addresses_to_anyone(): void
    {
        $movie = $this->movie();
        MovieMedia::factory()->create(['movie_id' => $movie->id, 'kind' => 'hls', 'url' => 'https://cdn.test/a.m3u8', 'meta' => ['quality' => '1080p']]);
        MovieMedia::factory()->create(['movie_id' => $movie->id, 'kind' => 'hls', 'url' => 'https://cdn.test/b.m3u8', 'meta' => null]);
        MovieMedia::factory()->create(['movie_id' => $movie->id, 'kind' => 'magnet', 'url' => 'magnet:?xt=urn:btih:1']);

        $response = $this->postJson('/api/v2/playback-sessions', ['movie_code' => 'ssis-001'])->assertCreated()
            ->assertJsonPath('data.movie_code', 'SSIS-001')->assertJsonCount(2, 'data.hls')->assertJsonPath('data.hls.0.quality', '1080p')->assertJsonPath('data.hls.1.quality', null);
        $this->assertTrue(\Illuminate\Support\Str::isUuid($response->json('data.id')));
        $this->assertGreaterThan(time() + 800, strtotime($response->json('data.expires_at')));
        $response->assertHeader('Location', '/api/v2/playback-sessions/' . $response->json('data.id'));

        $this->postJson('/api/v2/playback-sessions', ['movie_code' => 'NOPE-1'])->assertNotFound()->assertJsonPath('code', 'movie_not_found');
        $this->postJson('/api/v2/playback-sessions', [])->assertStatus(422);
    }

    private function file(string $path, ?string $code, int $size, bool $missing = false): int
    {
        return (int) DB::table('library_files')->insertGetId([
            'path' => $path, 'folder' => dirname($path), 'size' => $size, 'mtime' => 0, 'code' => $code,
            'first_seen_at' => now(), 'last_seen_at' => now(), 'missing_at' => $missing ? now() : null,
        ]);
    }
}
