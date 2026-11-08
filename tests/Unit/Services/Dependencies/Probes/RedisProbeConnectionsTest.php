<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dependencies\Probes;

use App\Services\Dependencies\Probes\RedisProbe;
use Illuminate\Redis\RedisManager;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Tests\TestCase;

#[CoversClass(RedisProbe::class)]
final class RedisProbeConnectionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'queue.default' => 'sync',
            'session.driver' => 'file',
            'horizon.use' => '',
            'database.redis.horizon' => null,
        ]);
    }

    public function test_nothing_is_probed_when_no_redis_backed_feature_is_configured(): void
    {
        $result = (new RedisProbe(static fn(): RedisManager => throw new RuntimeException('no manager expected')))->check();

        self::assertTrue($result->available);
        self::assertStringContainsString('No Redis-backed', $result->message);
    }

    public function test_every_redis_backed_feature_contributes_its_own_connection_once(): void
    {
        config([
            'cache.default' => 'redis',
            'cache.stores.redis' => ['driver' => 'redis', 'connection' => 'cache'],
            'queue.default' => 'redis',
            'queue.connections.redis' => ['driver' => 'redis', 'connection' => 'default'],
            'session.driver' => 'redis',
            'session.connection' => 'session',
            'horizon.use' => 'default',
        ]);

        self::assertSame(['cache', 'default', 'session'], $this->probedConnections());
    }

    public function test_the_separate_horizon_connection_wins_over_the_horizon_use_setting(): void
    {
        config(['database.redis.horizon' => ['host' => 'h'], 'horizon.use' => 'default']);

        self::assertSame(['horizon'], $this->probedConnections());
    }

    public function test_failover_stores_and_queues_are_followed_to_their_redis_members_without_looping(): void
    {
        config([
            'cache.default' => 'resilient',
            'cache.stores.resilient' => ['driver' => 'failover', 'stores' => ['redis-cache', 'file', 'resilient', 'missing']],
            'cache.stores.redis-cache' => ['driver' => 'redis'],
            'cache.stores.file' => ['driver' => 'file'],
            'queue.default' => 'resilient',
            'queue.connections.resilient' => ['driver' => 'failover', 'connections' => ['redis-queue', 'database', 'resilient', 'missing']],
            'queue.connections.redis-queue' => ['driver' => 'redis', 'connection' => 'queue'],
            'queue.connections.database' => ['driver' => 'database'],
        ]);

        self::assertSame(['default', 'queue'], $this->probedConnections());
    }

    public function test_a_session_without_a_connection_name_uses_the_default_connection(): void
    {
        config(['session.driver' => 'redis', 'session.connection' => '']);

        self::assertSame(['default'], $this->probedConnections());
    }

    public function test_a_failing_ping_is_reported_without_probing_further(): void
    {
        config(['cache.default' => 'redis', 'cache.stores.redis' => ['driver' => 'redis', 'connection' => 'cache']]);
        $connection = new class {
            public function ping(): bool
            {
                return false;
            }
        };
        $manager = Mockery::mock(RedisManager::class);
        $manager->shouldReceive('connection')->once()->with('cache')->andReturn($connection);

        $result = (new RedisProbe(static fn(): RedisManager => $manager))->check();

        self::assertFalse($result->available);
        self::assertSame('Redis PING failed.', $result->message);
    }

    public function test_an_unreachable_server_is_reported_as_a_connection_failure(): void
    {
        config(['cache.default' => 'redis', 'cache.stores.redis' => ['driver' => 'redis', 'connection' => 'cache']]);

        $result = (new RedisProbe(static fn(): RedisManager => throw new RuntimeException('connection refused')))->check();

        self::assertFalse($result->available);
        self::assertSame('Redis connection, ACL or prefixed key access failed.', $result->message);
    }

    /** @return list<string> the connection names the probe asked the manager for, sorted */
    private function probedConnections(): array
    {
        $asked = [];
        $connection = new class {
            public function ping(): string
            {
                return 'PONG';
            }

            public function setex(string $key, int $seconds, string $value): string
            {
                $this->value = $value;

                return 'OK';
            }

            public ?string $value = null;

            public function get(string $key): ?string
            {
                return $this->value;
            }

            public function del(string $key): int
            {
                return 1;
            }
        };
        $manager = Mockery::mock(RedisManager::class);
        $manager->shouldReceive('connection')->andReturnUsing(static function (string $name) use (&$asked, $connection): object {
            $asked[] = $name;

            return $connection;
        });

        $result = (new RedisProbe(static fn(): RedisManager => $manager))->check();

        self::assertTrue($result->available, $result->message);
        sort($asked);

        return $asked;
    }
}
