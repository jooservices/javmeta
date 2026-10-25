<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Models\User;
use App\Services\Auth\ApiKeyService;
use App\Services\Media\MediaQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MediaQueueTest extends TestCase
{
    use RefreshDatabase;

    private function file(): int
    {
        return DB::table('library_files')->insertGetId(['path' => '/generated/video.mp4', 'folder' => '/generated', 'size' => 12, 'mtime' => 1, 'first_seen_at' => now(), 'last_seen_at' => now()]);
    }

    private function headers(): array
    {
        return ['X-API-Key' => app(ApiKeyService::class)->create('worker', 120, ['media'])->plaintext];
    }

    public function test_worker_scope_and_owner_auth_are_required(): void
    {
        $this->getJson('/api/v1/media/config')->assertUnauthorized();
        $this->getJson('/api/v1/media/config', ['X-API-Key' => app(ApiKeyService::class)->create('movies', 120, ['movies'])->plaintext])->assertForbidden();
        $this->getJson('/api/v1/media/config', $this->headers())->assertOk()->assertJsonPath('data.max_parallel', 1);
        $this->postJson('/ui-me/media', ['kind' => 'scenes', 'library_file_id' => $this->file()])->assertUnauthorized();
        $this->getJson('/ui-api/media/jobs/next?worker=ui')->assertUnauthorized();
    }

    public function test_claims_respect_parallel_limit_and_attempt_tokens(): void
    {
        $q = app(MediaQueue::class);
        $file = $this->file();
        $first = $q->enqueue('scenes', $file);
        $this->assertSame($first, $q->enqueue('scenes', $file));
        $q->enqueue('thumbnails', $file);
        $h = $this->headers();
        $job = $this->getJson('/api/v1/media/jobs/next?worker=test', $h)->assertOk()->json('data');
        $this->assertSame($first, $job['id']);
        $this->assertSame(1, $job['attempts']);
        $this->getJson('/api/v1/media/jobs/next?worker=other', $h)->assertOk()->assertJsonPath('data', null);
        $this->postJson("/api/v1/media/jobs/{$first}/complete", ['claim_token' => '00000000-0000-4000-8000-000000000000', 'result' => ['duration' => 2]], $h)->assertConflict();
        $this->postJson("/api/v1/media/jobs/{$first}/complete", ['claim_token' => $job['claim_token'], 'result' => ['duration' => 2]], $h)->assertOk();
        $this->assertDatabaseHas('media_jobs', ['id' => $first, 'status' => 'done']);
        $this->assertNotNull($q->claim('other'));
    }

    public function test_failures_retry_until_limit_then_owner_can_retry(): void
    {
        config(['javmeta_media.max_attempts' => 2]);
        $q = app(MediaQueue::class);
        $id = $q->enqueue('thumbnails', $this->file());
        $h = $this->headers();
        for ($i = 0; $i < 2; $i++) {
            $j = $q->claim('test');
            $this->postJson("/api/v1/media/jobs/{$id}/fail", ['claim_token' => $j['claim_token'], 'error' => 'Generated test failure'], $h)->assertOk();
        }
        $this->assertDatabaseHas('media_jobs', ['id' => $id, 'status' => 'failed', 'attempts' => 2]);
        $this->actingAs(User::factory()->create(['role' => 'super_administrator']));
        $this->postJson("/ui-me/media/{$id}/retry")->assertAccepted();
        $this->assertDatabaseHas('media_jobs', ['id' => $id, 'status' => 'pending', 'attempts' => 0]);
        $this->postJson("/ui-me/media/{$id}/retry")->assertConflict();
    }

    public function test_expired_claim_is_recovered_and_old_worker_cannot_finish(): void
    {
        $q = app(MediaQueue::class);
        $id = $q->enqueue('thumbnails', $this->file());
        $old = $q->claim('old');
        DB::table('media_jobs')->where('id', $id)->update(['started_at' => now()->subDay()]);
        $new = $q->claim('new');
        $this->assertNotSame($old['claim_token'], $new['claim_token']);
        $this->postJson("/api/v1/media/jobs/{$id}/complete", ['claim_token' => $old['claim_token'], 'result' => ['duration' => 2]], $this->headers())->assertConflict();
    }

    public function test_owner_can_enqueue_list_and_invalid_input_is_refused(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_administrator']));
        $id = $this->file();
        $this->postJson('/ui-me/media', ['kind' => 'scenes', 'library_file_id' => $id])->assertAccepted();
        $this->getJson('/ui-me/media')->assertOk()->assertJsonPath('data.data.0.kind', 'scenes');
        $this->getJson('/ui-me/media/files')->assertOk()->assertJsonPath('data.data.0.id', $id);
        $this->postJson('/ui-me/media', ['kind' => 'shell', 'library_file_id' => $id])->assertUnprocessable();
        $this->postJson('/ui-me/media', ['kind' => 'scenes', 'library_file_id' => 9999])->assertUnprocessable();
        $this->artisan('media:enqueue scenes --file=' . $id)->assertSuccessful();
        $this->artisan('media:status')->assertSuccessful();
        $this->artisan('media:enqueue unknown --all')->assertFailed();
    }

    public function test_scene_ingest_rejects_output_escape_and_invalid_time_range(): void
    {
        $q = app(MediaQueue::class);
        $id = $q->enqueue('scenes', $this->file());
        $j = $q->claim('test');
        $body = ['claim_token' => $j['claim_token'], 'result' => ['scenes' => [['start_sec' => 0, 'end_sec' => 2, 'thumb_path' => '/etc/passwd']]]];
        $this->postJson("/api/v1/media/jobs/{$id}/complete", $body, $this->headers())->assertUnprocessable();
        $body['result']['scenes'][0]['end_sec'] = -1;
        $this->postJson("/api/v1/media/jobs/{$id}/complete", $body, $this->headers())->assertUnprocessable();
        $this->assertDatabaseCount('media_scenes', 0);
    }
    public function test_scene_ingest_persists_a_valid_generated_output(): void
    {
        $dir = sys_get_temp_dir() . '/javmeta-scenes-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/scene.jpg', 'generated fixture');
        config(['javmeta_media.output_folder' => $dir]);
        try {
            $q = app(MediaQueue::class);
            $file = $this->file();
            $id = $q->enqueue('scenes', $file);
            $j = $q->claim('test');
            $this->postJson("/api/v1/media/jobs/{$id}/complete", ['claim_token' => $j['claim_token'], 'result' => ['scenes' => [['start_sec' => 0, 'end_sec' => 2, 'thumb_path' => $dir . '/scene.jpg']]]], $this->headers())->assertOk();
            $this->assertDatabaseHas('media_scenes', ['library_file_id' => $file, 'start_sec' => 0, 'end_sec' => 2]);
        } finally {
            unlink($dir . '/scene.jpg');
            rmdir($dir);
        }
    }
}
