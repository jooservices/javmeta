<?php

declare(strict_types=1);

namespace App\Services\Dataset;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DatasetGuard
{
    public function check(bool $force): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $database = DB::connection()->getDatabaseName();
        if (! in_array($database, (array) config('javmeta_dataset.allowed_databases'), true)) {
            throw new RuntimeException('Sample dataset refused: the effective database is not allow-listed.');
        }
        if (! $force) {
            throw new RuntimeException('Sample dataset requires --force outside testing.');
        }
    }
}
