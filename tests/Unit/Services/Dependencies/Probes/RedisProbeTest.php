<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dependencies\Probes;

use App\Services\Dependencies\Probes\RedisProbe;
use Illuminate\Support\Facades\Redis;
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
        ]);
        $connection = new class {
            /** @var array<string, string> */
            public array $values = [];

            public function ping(): string
            {
                return 'PONG';
            }

            public function set(string $key, string $value, array $options): string
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
        Redis::shouldReceive('purge')->once()->with('default');
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($connection);

        $result = (new RedisProbe())->check();

        self::assertTrue($result->available);
        self::assertSame([], $connection->values);
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

            public function set(string $key, string $value, array $options): bool
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
        Redis::shouldReceive('purge')->once()->with('default');
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($connection);

        self::assertFalse((new RedisProbe())->probe());
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

            public function set(string $key, string $value, array $options): string
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
        Redis::shouldReceive('purge')->once()->with('horizon');
        Redis::shouldReceive('connection')->once()->with('horizon')->andReturn($connection);

        self::assertTrue((new RedisProbe())->probe());
    }
}
