<?php

declare(strict_types=1);

namespace Tests\Unit\Observability;

use App\Services\Observability\Pruning\FilesystemBackupSynchronizer;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

final class FilesystemBackupSynchronizerTest extends TestCase
{
    public function test_files_and_directory_can_be_synced_on_the_actual_filesystem(): void
    {
        $directory = base_path('.tmp/sync-' . bin2hex(random_bytes(8)));
        mkdir($directory, 0700, true);
        $path = $directory . '/backup';
        try {
            file_put_contents($path, 'backup');
            $sync = new FilesystemBackupSynchronizer();
            $sync->sync($path);
            $sync->sync($directory);
            self::assertSame('backup', file_get_contents($path));
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_unsyncable_stream_fails_with_sanitized_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot sync backup storage.');
        (new FilesystemBackupSynchronizer())->sync('php://memory');
    }
}
