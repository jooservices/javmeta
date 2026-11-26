<?php

declare(strict_types=1);

namespace Tests\Integration\Api\V2;

use App\Models\Movie;
use App\Models\MovieCode;
use App\Models\MovieMedia;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The downloads area on real PostgreSQL: the migration, the queue and the library queries, and the media worker's queue. */
final class DownloadsPostgresTest extends PostgresApiTestCase
{
    public function test_the_migration_adds_the_request_table_and_queue_columns_and_the_old_insert_still_works(): void
    {
        $this->assertTrue(Schema::hasTable('download_requests'));
        $this->assertTrue(Schema::hasColumns('download_items', ['state', 'progress', 'size_bytes', 'updated_at']));

        DB::table('download_items')->insert(['hash' => 'old', 'movie_code' => null, 'magnet' => 'magnet:?xt=urn:btih:old', 'added_at' => now()]);
        $this->assertSame('queued', DB::table('download_items')->where('hash', 'old')->value('state'));
    }

    public function test_owner_flow_over_the_queue_library_and_media_jobs(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $this->actingAs($owner, 'web')->getJson('/api/v2/sessions/current');
        $headers = ['X-CSRF-Token' => (string) $this->app['session.store']->token()];

        $this->postJson('/api/v2/download-items', ['magnet' => 'magnet:?xt=urn:btih:AB12&dn=x', 'movie_code' => 'abc-001'], $headers)->assertCreated();
        $this->patchJson('/api/v2/download-items/ab12', ['state' => 'paused'], $headers)->assertOk()->assertJsonPath('data.state', 'paused');
        $this->getJson('/api/v2/download-status')->assertJsonPath('data.total', 1)->assertJsonPath('data.paused', 1);
        $this->getJson('/api/v2/download-items')->assertJsonPath('data.0.added_at', fn($value) => is_string($value) && str_ends_with($value, 'Z'));

        $file = DB::table('library_files')->insertGetId(['path' => '/m/a.mp4', 'folder' => '/m', 'size' => 5, 'mtime' => 0, 'code' => 'ABC-001', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $this->getJson('/api/v2/library')->assertJsonPath('data.files', 1)->assertJsonPath('data.matched', 1)->assertJsonPath('data.bytes', 5);
        $this->getJson('/api/v2/library/files?match=matched')->assertJsonPath('meta.pagination.total', 1);
        $this->getJson('/api/v2/library/codes')->assertJsonPath('data.0', 'ABC-001');
        $this->getJson('/api/v2/download-items/ab12/links')->assertOk()->assertJsonPath('data', []);

        $job = $this->postJson('/api/v2/media-jobs', ['kind' => 'thumbnail', 'params' => ['library_file_id' => $file]], $headers)->assertCreated()->json('data.id');
        $this->getJson('/api/v2/media-files')->assertJsonCount(1, 'data');
        $this->getJson('/api/v2/media-jobs')->assertJsonPath('data.0.id', $job);
        DB::table('media_jobs')->where('id', $job)->update(['status' => 'failed']);
        $this->patchJson("/api/v2/media-jobs/{$job}", ['state' => 'queued'], $headers)->assertOk()->assertJsonPath('data.status', 'pending');
    }

    public function test_guest_requests_and_playback_use_the_movie_tables(): void
    {
        $movie = Movie::factory()->create(['display_code' => 'ABC-001', 'code_normalized' => 'ABC001']);
        MovieCode::factory()->create(['movie_id' => $movie->id, 'code' => 'ABC-001', 'code_normalized' => 'ABC001']);
        MovieMedia::factory()->create(['movie_id' => $movie->id, 'kind' => 'hls', 'url' => 'https://cdn.test/a.m3u8', 'meta' => ['quality' => '720p']]);

        $this->postJson('/api/v2/playback-sessions', ['movie_code' => 'abc-001'])->assertCreated()->assertJsonPath('data.hls.0.quality', '720p');
        $this->postJson('/api/v2/download-requests', ['movie_code' => 'abc-001'])->assertCreated()->assertJsonPath('data.movie_code', 'ABC-001');
        $this->getJson('/api/v2/download-requests')->assertOk()->assertJsonCount(1, 'data');
    }
}
