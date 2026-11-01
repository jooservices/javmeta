<?php

declare(strict_types=1);

namespace App\Services\Observability\Pruning;

use RuntimeException;
use Throwable;

final class FilesystemBackupSynchronizer implements BackupSynchronizer
{
    public function sync(string $path): void
    {
        $handle = null;
        try {
            $handle = @fopen($path, 'rb');
            if ($handle === false || ! @fsync($handle)) {
                throw new RuntimeException('Cannot sync backup storage.');
            }
        } catch (Throwable) {
            throw new RuntimeException('Cannot sync backup storage.');
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }
}
