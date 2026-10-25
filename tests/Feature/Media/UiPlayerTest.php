<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;
use Tests\TestCase;

final class UiPlayerTest extends TestCase
{
    use RefreshDatabase;

    public function test_player_requires_owner_and_obeys_file_switch(): void
    {
        $this->getJson('/ui-me/player/GEN-001')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'super_administrator']));
        DB::table('library_files')->insert(['path' => '/generated/video.mp4', 'folder' => '/generated', 'code' => 'GEN-001', 'size' => 10, 'mtime' => 1, 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $this->getJson('/ui-me/player/GEN-001')->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.url', null);
        $this->getJson('/ui-me/player/UNKNOWN')->assertNotFound();
        $this->getJson('/ui-me/player/GEN-001?file=999')->assertNotFound();
    }

    public function test_player_serves_video_range_vtt_and_markers_through_signed_links(): void
    {
        SettingsService::forgetDefaults();
        $this->app->instance(ConfigStore::class, new FakeConfigStore());
        $this->app->forgetInstance(SettingsService::class);
        $dir = sys_get_temp_dir() . '/javmeta-player-' . uniqid();
        mkdir($dir);
        mkdir($dir . '/library');
        mkdir($dir . '/output');
        file_put_contents($dir . '/library/generated.mp4', '0123456789');
        file_put_contents($dir . '/output/generated.vi.vtt', "WEBVTT\n\n1\n00:00:01.000 --> 00:00:02.000\nXin chào\n");
        $s = app(SettingsService::class);
        $s->set('files.enabled', true);
        $s->set('files.root_folders', [$dir . '/library']);
        $s->set('media.output_folder', $dir . '/output');
        $s->set('files.allowed_networks', ['127.0.0.0/8']);
        $this->actingAs(User::factory()->create(['role' => 'super_administrator']));
        try {
            $id = DB::table('library_files')->insertGetId(['path' => $dir . '/library/generated.mp4', 'folder' => $dir . '/library', 'code' => 'GEN-001', 'size' => 10, 'mtime' => 1, 'first_seen_at' => now(), 'last_seen_at' => now()]);
            DB::table('media_subtitles')->insert(['library_file_id' => $id, 'language' => 'vi', 'format' => 'vtt', 'source' => 'translation', 'path' => $dir . '/output/generated.vi.vtt', 'created_at' => now()]);
            DB::table('media_scenes')->insert(['library_file_id' => $id, 'start_sec' => 1, 'end_sec' => 2, 'thumb_path' => $dir . '/output/generated.jpg', 'description' => 'A generated scene.']);
            $data = $this->getJson('/ui-me/player/GEN-001')->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.subtitles.0.language', 'vi')->assertJsonPath('data.scenes.0.start_sec', 1)->json('data');
            $this->get($data['url'], ['Range' => 'bytes=0-3'])->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-3/10');
            $this->get($data['subtitles'][0]['url'])->assertOk()->assertHeader('Content-Type', 'text/vtt; charset=UTF-8');
            $this->get($data['url'] . 'tampered')->assertForbidden();
            $this->getJson('/ui-me/player/file-' . $id)->assertOk()->assertJsonPath('data.library_file_id', $id);
            unlink($dir . '/output/generated.vi.vtt');
            $this->getJson('/ui-me/player/GEN-001')->assertOk()->assertJsonPath('data.subtitles', [])->assertJsonPath('data.error', 'Một số phụ đề không còn đọc được. Hãy tạo lại phụ đề.');
            unlink($dir . '/library/generated.mp4');
            $this->getJson('/ui-me/player/GEN-001')->assertOk()->assertJsonPath('data.url', null);
        } finally {
            foreach (glob($dir . '/library/*') as $p) {
                unlink($p);
            }
            foreach (glob($dir . '/output/*') as $p) {
                unlink($p);
            }
            rmdir($dir . '/library');
            rmdir($dir . '/output');
            rmdir($dir);
            SettingsService::forgetDefaults();
        }
    }
}
