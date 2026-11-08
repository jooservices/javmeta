<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Models\Movie;
use App\Models\Performer;
use App\Models\User;
use App\Services\Auth\ApiKeyService;
use App\Services\Media\MediaQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MediaCaptureTest extends TestCase
{
    use RefreshDatabase;

    public function test_capture_is_off_by_default_and_requires_an_explicit_owner_action(): void
    {
        $this->postJson('/ui-me/media/capture', ['url' => 'https://public.test/a.m3u8'])->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'super_administrator']));
        $this->postJson('/ui-me/media/capture', ['url' => 'https://public.test/a.m3u8'])->assertUnprocessable();
        $this->assertDatabaseCount('media_jobs', 0);
        config(['javmeta_capture.enabled' => true]);
        $this->postJson('/ui-me/media/capture', ['url' => 'https://public.test/a.m3u8'])->assertAccepted();
        $this->assertDatabaseHas('media_jobs', ['kind' => 'capture', 'status' => 'pending', 'library_file_id' => null]);
        $this->postJson('/ui-me/media/capture', ['url' => 'https://user:pw@public.test/a.m3u8'])->assertUnprocessable();
        $this->postJson('/ui-me/media/capture', ['url' => 'https://public.test/underage/a.m3u8'])->assertUnprocessable();
        $this->postJson('/ui-me/media', ['kind' => 'capture', 'library_file_id' => 1])->assertUnprocessable();
    }

    public function test_disable_after_enqueue_prevents_a_worker_claim(): void
    {
        config(['javmeta_capture.enabled' => true]);
        $q = app(MediaQueue::class);
        $id = $q->capture('https://public.test/a.mpd');
        config(['javmeta_capture.enabled' => false]);
        $this->assertNull($q->claim('worker'));
        $this->assertDatabaseHas('media_jobs', ['id' => $id, 'status' => 'failed', 'attempts' => 0]);
    }

    public function test_worker_config_contains_capture_limits_and_read_only_library_roots(): void
    {
        config(['javmeta_library.folders' => ['/generated/library']]);
        $headers = ['X-API-Key' => app(ApiKeyService::class)->create('media', 120, ['media'])->plaintext];
        $this->getJson('/api/v1/media/config', $headers)->assertOk()
            ->assertJsonPath('data.capture.enabled', false)
            ->assertJsonPath('data.library_folders.0', '/generated/library');
    }

    public function test_known_performer_under_eighteen_at_release_is_refused(): void
    {
        $movie = Movie::factory()->create(['release_date' => '2025-01-01', 'title_en' => 'Generated frame', 'title_jp' => 'Generated', 'description' => null]);
        $performer = Performer::factory()->create(['birth_date' => '2010-01-01']);
        DB::table('movie_performers')->insert(['movie_id' => $movie->id, 'performer_id' => $performer->id, 'source_slug' => 'generated', 'crawled_at' => now()]);
        $file = DB::table('library_files')->insertGetId(['movie_id' => $movie->id, 'path' => '/generated/video.mp4', 'folder' => '/generated', 'code' => 'GEN-001', 'size' => 1, 'mtime' => 1, 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $this->actingAs(User::factory()->create(['role' => 'super_administrator']));
        $this->postJson('/ui-me/media', ['kind' => 'transcribe', 'library_file_id' => $file])->assertUnprocessable();
        $this->assertDatabaseCount('media_jobs', 0);
    }

    public function test_capture_completion_validates_the_output_folder(): void
    {
        config(['javmeta_capture.enabled' => true]);
        $dir = sys_get_temp_dir() . '/javmeta-capture-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/capture.mp4', 'generated');
        config(['javmeta_media.output_folder' => $dir]);
        try {
            $q = app(MediaQueue::class);
            $id = $q->capture('https://public.test/a.m3u8');
            $claim = $q->claim('test');
            $h = ['X-API-Key' => app(ApiKeyService::class)->create('media', 120, ['media'])->plaintext];
            $body = ['claim_token' => $claim['claim_token'], 'result' => ['capture_path' => '/etc/passwd', 'bytes' => 9]];
            $this->postJson("/api/v1/media/jobs/{$id}/complete", $body, $h)->assertUnprocessable();
            $body['result']['capture_path'] = $dir . '/capture.mp4';
            $this->postJson("/api/v1/media/jobs/{$id}/complete", $body, $h)->assertOk();
            $this->assertDatabaseHas('media_jobs', ['id' => $id, 'status' => 'done']);
        } finally {
            unlink($dir . '/capture.mp4');
            rmdir($dir);
        }
    }

    public function test_library_metadata_suggesting_minors_is_refused_and_queued_files_are_rechecked(): void
    {
        $movie = Movie::factory()->create(['title_en' => 'Underage content']);
        $file = DB::table('library_files')->insertGetId(['movie_id' => $movie->id, 'path' => '/generated/video.mp4', 'folder' => '/generated', 'code' => 'GEN-001', 'size' => 1, 'mtime' => 1, 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $this->actingAs(User::factory()->create(['role' => 'super_administrator']));
        $this->postJson('/ui-me/media', ['kind' => 'scenes', 'library_file_id' => $file])->assertUnprocessable();
        $movie->update(['title_en' => 'Generated red frame', 'title_jp' => 'Generated']);
        $this->postJson('/ui-me/media', ['kind' => 'scenes', 'library_file_id' => $file])->assertAccepted();
        $movie->update(['title_en' => 'Underage content']);
        $this->assertNull(app(MediaQueue::class)->claim('worker'));
        $this->assertDatabaseHas('media_jobs', ['library_file_id' => $file, 'status' => 'failed']);
    }
}
