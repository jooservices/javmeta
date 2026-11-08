<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dependencies\Probes;

use App\Services\Dependencies\Probes\RedisProbe;
use Closure;
use Illuminate\Redis\RedisManager;
use Mockery;
use Tests\TestCase;

final class RedisProbeTest extends TestCase
{
    public function test_it_pings_and_proves_prefixed_key_access_on_configured_connections(): void
    {
        config([
            'cache.default' => 'array',
            'queue.default' => 'redis',
            'queue.connections.redis.driver' => 'redis',
            'queue.connections.redis.connection' => 'default',
            'horizon.use' => 'default',
            'database.redis.horizon' => null,
            'database.redis.default.timeout' => 9,
            'database.redis.default.read_timeout' => 8,
            'database.redis.default.read_write_timeout' => 7,
        ]);
        $originalConfig = config('database.redis');
        $probeConfig = [];
        $connection = new class {
            /** @var array<string, string> */
            public array $values = [];

            public function ping(): string
            {
                return 'PONG';
            }

            public function setex(string $key, int $seconds, string $value): string
            {
                $this->values[$key] = $value;

                return 'OK';
            }

            public function get(string $key): ?string
            {
                return $this->values[$key] ?? null;
            }

            public function del(string $key): int
            {
                unset($this->values[$key]);

                return 1;
            }
        };
        $probe = $this->probeWithConnection($connection, 'default', static function (array $config) use (&$probeConfig): void {
            $probeConfig = $config;
        });

        $result = $probe->check();

        self::assertTrue($result->available);
        self::assertSame([], $connection->values);
        self::assertSame($originalConfig, config('database.redis'));
        self::assertSame(2, $probeConfig['default']['timeout']);
        self::assertSame(2, $probeConfig['default']['read_timeout']);
        self::assertSame(2, $probeConfig['default']['read_write_timeout']);
    }

    public function test_it_reports_acl_or_prefix_failures_as_down(): void
    {
        config([
            'cache.default' => 'array',
            'queue.default' => 'redis',
            'queue.connections.redis.driver' => 'redis',
            'queue.connections.redis.connection' => 'default',
            'horizon.use' => 'default',
            'database.redis.horizon' => null,
        ]);
        $connection = new class {
            public function ping(): string
            {
                return 'PONG';
            }

            public function setex(string $key, int $seconds, string $value): bool
            {
                return false;
            }

            public function get(string $key): ?string
            {
                return null;
            }

            public function del(string $key): int
            {
                return 0;
            }
        };
        $probe = $this->probeWithConnection($connection);

        self::assertFalse($probe->probe());
    }

    public function test_it_checks_the_separate_horizon_redis_connection(): void
    {
        config([
            'cache.default' => 'array',
            'queue.default' => 'sync',
            'horizon.use' => 'default',
            'database.redis.horizon' => ['host' => 'redis.example.test'],
        ]);
        $connection = new class {
            /** @var array<string, string> */
            public array $values = [];

            public function ping(): string
            {
                return 'PONG';
            }

            public function setex(string $key, int $seconds, string $value): string
            {
                $this->values[$key] = $value;

                return 'OK';
            }

            public function get(string $key): ?string
            {
                return $this->values[$key] ?? null;
            }

            public function del(string $key): int
            {
                unset($this->values[$key]);

                return 1;
            }
        };
        $probe = $this->probeWithConnection($connection, 'horizon');

        self::assertTrue($probe->probe());
    }

    public function test_it_reports_a_failed_probe_key_cleanup_as_down(): void
    {
        config([
            'cache.default' => 'array',
            'queue.default' => 'redis',
            'queue.connections.redis.driver' => 'redis',
            'queue.connections.redis.connection' => 'default',
            'horizon.use' => 'default',
            'database.redis.horizon' => null,
        ]);
        $connection = new class {
            public function ping(): string
            {
                return 'PONG';
            }

            public function setex(string $key, int $seconds, string $value): string
            {
                return 'OK';
            }

            public function get(string $key): string
            {
                return 'unused';
            }

            public function del(string $key): bool
            {
                return false;
            }
        };

        self::assertFalse($this->probeWithConnection($connection)->probe());
    }

    /** @param Closure(array<string, mixed>): void|null $onConfig */
    private function probeWithConnection(object $connection, string $name = 'default', ?Closure $onConfig = null): RedisProbe
    {
        $manager = Mockery::mock(RedisManager::class);
        $manager->shouldReceive('connection')->once()->with($name)->andReturn($connection);

        return new RedisProbe(static function (array $config) use ($manager, $onConfig): RedisManager {
            $onConfig?->__invoke($config);

            return $manager;
        });
    }
}
