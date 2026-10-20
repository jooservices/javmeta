<?php

declare(strict_types=1);

namespace App\Services\Dependencies\Probes;

use App\Services\Dependencies\DependencyProbe;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class RedisProbe implements DependencyProbe
{
    public function probe(): bool
    {
        $connections = $this->configuredConnections();
        if ($connections === []) {
            return true;
        }

        try {
            foreach ($connections as $connection) {
                if (Redis::connection($connection)->ping() === false) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return list<string> */
    private function configuredConnections(): array
    {
        $connections = $this->cacheConnections((string) config('cache.default', 'database'));
        $connections = array_merge(
            $connections,
            $this->queueConnections((string) config('queue.default', 'database')),
        );

        if ((string) config('session.driver', 'database') === 'redis') {
            $connections[] = (string) config('session.connection', 'default') ?: 'default';
        }

        $horizonConnection = (string) config('horizon.use', 'default');
        if ($horizonConnection !== '') {
            $connections[] = $horizonConnection;
        }

        return array_values(array_unique($connections));
    }

    /** @param array<string, true> $visited */
    private function cacheConnections(string $storeName, array $visited = []): array
    {
        if (isset($visited[$storeName])) {
            return [];
        }
        $visited[$storeName] = true;

        $store = config("cache.stores.{$storeName}");
        if (! is_array($store)) {
            return [];
        }

        if (($store['driver'] ?? null) === 'redis') {
            return [(string) ($store['connection'] ?? 'default') ?: 'default'];
        }

        if (($store['driver'] ?? null) !== 'failover' || ! is_array($store['stores'] ?? null)) {
            return [];
        }

        $connections = [];
        foreach ($store['stores'] as $fallbackStore) {
            $connections = array_merge(
                $connections,
                $this->cacheConnections((string) $fallbackStore, $visited),
            );
        }

        return $connections;
    }

    /** @param array<string, true> $visited */
    private function queueConnections(string $connectionName, array $visited = []): array
    {
        if (isset($visited[$connectionName])) {
            return [];
        }
        $visited[$connectionName] = true;

        $connection = config("queue.connections.{$connectionName}");
        if (! is_array($connection)) {
            return [];
        }

        if (($connection['driver'] ?? null) === 'redis') {
            return [(string) ($connection['connection'] ?? 'default') ?: 'default'];
        }

        if (($connection['driver'] ?? null) !== 'failover' || ! is_array($connection['connections'] ?? null)) {
            return [];
        }

        $connections = [];
        foreach ($connection['connections'] as $fallbackConnection) {
            $connections = array_merge(
                $connections,
                $this->queueConnections((string) $fallbackConnection, $visited),
            );
        }

        return $connections;
    }
}
