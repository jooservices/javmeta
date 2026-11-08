<?php

declare(strict_types=1);

namespace App\Services\Observability\Pruning;

use Illuminate\Container\Attributes\Bind;

#[Bind(FilesystemBackupSynchronizer::class)]
interface BackupSynchronizer
{
    public function sync(string $path): void;
}
