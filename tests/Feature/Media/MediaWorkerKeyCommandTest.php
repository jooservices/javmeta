<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Services\Auth\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MediaWorkerKeyCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_worker_key_is_created_once_with_only_the_media_scope(): void
    {
        $dir = sys_get_temp_dir() . '/javmeta-key-' . uniqid();
        $this->app->useStoragePath($dir);
        try {
            $this->artisan('media:worker-key')->assertSuccessful();
            $path = $dir . '/app/private/media/worker.key';
            $original = file_get_contents($path);
            $value = json_decode($original, true, 512, JSON_THROW_ON_ERROR);
            $key = app(ApiKeyService::class)->findActiveByPlaintext($value['key']);
            $this->assertSame(['media'], $key->scopes);
            $this->assertSame(0640, fileperms($path) & 0777);
            $this->artisan('media:worker-key')->assertSuccessful();
            $this->assertSame($original, file_get_contents($path));
            $this->assertDatabaseCount('api_keys', 1);
            file_put_contents($path, 'invalid');
            $this->artisan('media:worker-key')->assertFailed();
            $this->assertDatabaseCount('api_keys', 1);
        } finally {
            @unlink($dir . '/app/private/media/worker.key');
            @rmdir($dir . '/app/private/media');
            @rmdir($dir . '/app/private');
            @rmdir($dir . '/app');
            @rmdir($dir);
        }
    }
}
