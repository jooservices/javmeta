<?php

declare(strict_types=1);

namespace Tests\Feature\Files;

use App\Models\User;
use App\Services\Downloads\FileLinkService;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\URL;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

#[CoversClass(\App\Http\Controllers\FileController::class)]
final class FileDownloadTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::forgetDefaults();
        $this->app->instance(ConfigStore::class, new FakeConfigStore());
        $this->app->forgetInstance(SettingsService::class);
        $this->dir = sys_get_temp_dir() . '/javmeta-files-' . uniqid();
        mkdir($this->dir, 0777, true);
        $settings = app(SettingsService::class);
        $settings->set('downloads.save_path', $this->dir);
        $settings->set('files.enabled', true);
        $this->actingAs(User::factory()->create(['role' => 'super_administrator']));
    }

    protected function tearDown(): void
    {
        SettingsService::forgetDefaults();
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function fileTypes(): iterable
    {
        yield 'mp4' => ['clip.mp4', 'video/mp4', 'inline'];
        yield 'm4v' => ['clip.m4v', 'video/mp4', 'inline'];
        yield 'mkv' => ['clip.mkv', 'video/x-matroska', 'inline'];
        yield 'webm' => ['clip.webm', 'video/webm', 'inline'];
        yield 'mov' => ['clip.mov', 'video/quicktime', 'inline'];
        yield 'avi' => ['clip.avi', 'video/x-msvideo', 'inline'];
        yield 'ts' => ['clip.ts', 'video/mp2t', 'inline'];
        yield 'mp3' => ['track.mp3', 'audio/mpeg', 'inline'];
        yield 'm4a' => ['track.m4a', 'audio/mp4', 'inline'];
        yield 'flac' => ['track.flac', 'audio/flac', 'inline'];
        yield 'ogg' => ['track.ogg', 'audio/ogg', 'inline'];
        yield 'wav' => ['track.wav', 'audio/wav', 'inline'];
        yield 'srt' => ['subs.srt', 'application/x-subrip', 'inline'];
        yield 'vtt' => ['subs.vtt', 'text/vtt', 'inline'];
        yield 'jpg' => ['cover.jpg', 'image/jpeg', 'inline'];
        yield 'jpeg' => ['cover.jpeg', 'image/jpeg', 'inline'];
        yield 'png' => ['cover.png', 'image/png', 'inline'];
        yield 'gif' => ['cover.gif', 'image/gif', 'inline'];
        yield 'webp' => ['cover.webp', 'image/webp', 'inline'];
        yield 'unknown' => ['archive.bin', 'application/octet-stream', 'attachment'];
    }

    #[DataProvider('fileTypes')]
    public function test_each_file_type_is_served_with_its_own_content_type_and_disposition(string $name, string $type, string $disposition): void
    {
        file_put_contents($this->dir . '/' . $name, 'payload');

        $response = $this->get(app(FileLinkService::class)->url($this->dir . '/' . $name));

        $response->assertOk();
        self::assertStringStartsWith($type, (string) $response->headers->get('Content-Type'));
        self::assertStringStartsWith($disposition, (string) $response->headers->get('Content-Disposition'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_a_signed_link_with_an_unreadable_payload_is_not_found(): void
    {
        $garbage = URL::temporarySignedRoute('files.download', now()->addMinutes(5), ['p' => 'not-an-encrypted-value']);
        $missing = URL::temporarySignedRoute('files.download', now()->addMinutes(5));

        $this->get($garbage)->assertNotFound();
        $this->get($missing)->assertNotFound();
    }

    public function test_a_signed_link_to_a_file_outside_the_served_folders_is_not_found(): void
    {
        $outside = sys_get_temp_dir() . '/javmeta-outside-' . uniqid() . '.txt';
        file_put_contents($outside, 'secret');

        try {
            $link = URL::temporarySignedRoute('files.download', now()->addMinutes(5), ['p' => Crypt::encryptString($outside)]);

            $this->get($link)->assertNotFound();
        } finally {
            unlink($outside);
        }
    }

    public function test_a_caller_outside_the_allowed_networks_is_refused_even_with_a_valid_link(): void
    {
        file_put_contents($this->dir . '/clip.mp4', 'payload');
        $link = app(FileLinkService::class)->url($this->dir . '/clip.mp4');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get($link)->assertForbidden();
    }
}
