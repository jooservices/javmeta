<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Read the resolved connection (including DB_URL and cached config), without connecting or mutating data. */
final class UatTargetsCommand extends Command
{
    protected $signature = 'ops:uat-targets';

    protected $description = 'Verify the effective targets approved by deploy/environments.sh before UAT reset.';

    public function handle(): int
    {
        $connection = DB::connection();
        if (! app()->environment('uat') || config('app.env') !== 'uat'
            || $connection->getConfig('driver') !== 'pgsql'
            || $connection->getConfig('host') !== 'postgres'
            || (string) $connection->getConfig('port') !== '5432'
            || $connection->getDatabaseName() !== 'javmeta_uat'
            || config('database.connections.mongodb.database') !== 'javmeta_uat'
            || config('mongodb.database') !== 'javmeta_uat'
            || config('elasticsearch.movies_index') !== 'javmeta_uat_movies'
            || config('elasticsearch.performers_index') !== 'javmeta_uat_performers') {
            $this->components->error('Effective UAT targets or cached environment do not match the approved targets.');

            return self::FAILURE;
        }

        $this->line('UAT_TARGETS_VERIFIED');

        return self::SUCCESS;
    }
}
