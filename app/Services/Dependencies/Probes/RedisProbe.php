<?php

declare(strict_types=1);

namespace App\Services\Dependencies\Probes;

use App\Services\Dependencies\DetailedDependencyProbe;
use App\Services\Dependencies\ProbeResult;
use Closure;
use Illuminate\Redis\RedisManager;
use Throwable;

final class RedisProbe implements DetailedDependencyProbe
{
    /** @var Closure(array<string, mixed>): RedisManager */
    private readonly Closure $managerFactory;

    /** @param Closure(array<string, mixed>): RedisManager|null $managerFactory */
    public function __construct(?Closure $managerFactory = null)
    {
        $this->managerFactory = $managerFactory ?? static fn(array $config): RedisManager => new RedisManager(
            app(),
            (string) ($config['client'] ?? 'phpredis'),
            $config,
        );
    }

    public function probe(): bool
    {
        return $this->check()->available === true;
    }

    public function check(): ProbeResult
    {
        $timeout = max(1, (int) config('javmeta_health.startup.timeout_seconds', 2));
        $redisConfig = (array) config('database.redis', []);
        foreach ($redisConfig as $name => $connection) {
            if (is_array($connection) && isset($connection['host'])) {
                $redisConfig[$name]['timeout'] = $timeout;
                $redisConfig[$name]['read_timeout'] = $timeout;
                $redisConfig[$name]['read_write_timeout'] = $timeout;
            }
        }

        $connections = $this->configuredConnections();
        if ($connections === []) {
            return new ProbeResult(true, 'No Redis-backed queue, cache, session or Horizon connection is configured.');
        }

        try {
            $manager = ($this->managerFactory)($redisConfig);
            foreach ($connections as $connection) {
                $redis = $manager->connection($connection);
                if ($redis->ping() === false) {
                    return new ProbeResult(false, 'Redis PING failed.');
                }

                $key = 'startup-health:' . bin2hex(random_bytes(12));
                $value = bin2hex(random_bytes(12));
                $usable = false;
                $deleted = false;
                try {
                    $usable = in_array($redis->setex($key, $timeout, $value), [true, 'OK'], true)
                        && $redis->get($key) === $value;
                } finally {
                    $deleted = $redis->del($key) !== false;
                }
                if (! $usable || ! $deleted) {
                    return new ProbeResult(false, 'Redis prefixed key access failed.');
                }
            }

            return new ProbeResult(true, 'PING and prefixed key access succeeded on configured connections.');
        } catch (Throwable) {
            return new ProbeResult(false, 'Redis connection, ACL or prefixed key access failed.');
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

        $horizonConnection = is_array(config('database.redis.horizon'))
            ? 'horizon'
            : (string) config('horizon.use', 'default');
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
