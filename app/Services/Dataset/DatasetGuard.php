<?php

declare(strict_types=1);

namespace App\Services\Dataset;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DatasetGuard
{
    public function check(bool $force): void
    {
        $testing = app()->environment('testing');
        $connection = DB::connection();

        if (! $testing || $connection->getDriverName() !== 'sqlite') {
            $database = $connection->getDatabaseName();
            if (! in_array($database, (array) config('javmeta_dataset.allowed_databases'), true)) {
                throw new RuntimeException('Sample dataset refused: the effective database is not allow-listed.');
            }
        }
        if (! $testing && ! $force) {
            throw new RuntimeException('Sample dataset requires --force outside testing.');
        }
    }
}
