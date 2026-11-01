<?php

declare(strict_types=1);

namespace App\Services\Dependencies\Probes;

use App\Services\Dependencies\DetailedDependencyProbe;
use App\Services\Dependencies\ProbeResult;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

final class PostgresProbe implements DetailedDependencyProbe
{
    public function probe(): bool
    {
        return $this->check()->available === true;
    }

    public function check(): ProbeResult
    {
        try {
            $connectionName = (string) config('database.default', 'pgsql');
            $driver = (string) config("database.connections.{$connectionName}.driver", '');
            if ($driver === 'sqlite' && app()->environment('testing')) {
                DB::connection()->select('SELECT 1');

                return new ProbeResult(true, 'SQLite test connection responded.');
            }
            if ($driver !== 'pgsql') {
                return new ProbeResult(false, 'PostgreSQL is not the configured database driver.');
            }

            $timeout = max(1, (int) config('javmeta_health.startup.timeout_seconds', 2));
            $connectionConfig = (array) config("database.connections.{$connectionName}", []);
            $connectionConfig['options'] = array_replace(
                (array) ($connectionConfig['options'] ?? []),
                [PDO::ATTR_TIMEOUT => $timeout],
            );
            $probeConnectionName = 'startup_health_' . bin2hex(random_bytes(8));
            $connectionConfig['name'] = $probeConnectionName;

            try {
                $connection = DB::build($connectionConfig);
                $timezone = $connection->transaction(function () use ($connection, $timeout): array {
                    $connection->statement('SET LOCAL statement_timeout TO ' . ($timeout * 1000));
                    $connection->select('SELECT 1');

                    return $connection->select('SHOW timezone');
                });
            } finally {
                DB::purge($probeConnectionName);
            }

            $first = (array) ($timezone[0] ?? []);
            $value = strtoupper(trim((string) (reset($first) ?: '')));

            if (! in_array($value, ['UTC', 'ETC/UTC'], true)) {
                return new ProbeResult(false, 'PostgreSQL timezone must be UTC.');
            }

            return new ProbeResult(true, 'SELECT 1 succeeded; timezone is UTC.');
        } catch (Throwable) {
            return new ProbeResult(false, 'PostgreSQL connection or query failed.');
        }
    }
}
