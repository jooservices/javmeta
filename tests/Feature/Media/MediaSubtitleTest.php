<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Services\Auth\ApiKeyService;
use App\Services\Media\MediaQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MediaSubtitleTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_subtitles_are_persisted_and_invalid_output_is_rejected(): void
    {
        $dir = sys_get_temp_dir() . '/javmeta-subs-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/generated.vtt', "WEBVTT\n\n");
        config(['javmeta_media.output_folder' => $dir]);
        try {
            $file = DB::table('library_files')->insertGetId(['path' => '/generated/video.mp4', 'folder' => '/generated', 'size' => 12, 'mtime' => 1, 'first_seen_at' => now(), 'last_seen_at' => now()]);
            $q = app(MediaQueue::class);
            $id = $q->enqueue('transcribe', $file);
            $j = $q->claim('test');
            $h = ['X-API-Key' => app(ApiKeyService::class)->create('worker', 120, ['media'])->plaintext];
            $body = ['claim_token' => $j['claim_token'], 'result' => ['subtitles' => [['language' => 'en', 'format' => 'vtt', 'source' => 'whisper', 'path' => '/etc/passwd']], 'language' => 'en', 'segment_count' => 1]];
            $this->postJson("/api/v1/media/jobs/{$id}/complete", $body, $h)->assertUnprocessable();
            $body['result']['subtitles'][0]['path'] = $dir . '/generated.vtt';
            $this->postJson("/api/v1/media/jobs/{$id}/complete", $body, $h)->assertOk();
            $this->assertDatabaseHas('media_subtitles', ['library_file_id' => $file, 'language' => 'en', 'format' => 'vtt', 'source' => 'whisper']);
        } finally {
            unlink($dir . '/generated.vtt');
            rmdir($dir);
        }
    }
}
