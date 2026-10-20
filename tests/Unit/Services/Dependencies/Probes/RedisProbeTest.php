<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dependencies\Probes;

use App\Services\Dependencies\Probes\RedisProbe;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

final class RedisProbeTest extends TestCase
{
    public function test_it_pings_horizons_redis_connection_when_other_drivers_are_database_backed(): void
    {
        config()->set('cache.default', 'database');
        config()->set('queue.default', 'database');
        config()->set('session.driver', 'database');
        config()->set('horizon.use', 'default');
        $connection = Mockery::mock();
        $connection->shouldReceive('ping')->once()->andReturn('PONG');
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($connection);

        self::assertTrue((new RedisProbe())->probe());
    }

    public function test_it_pings_the_connection_selected_by_the_cache_store(): void
    {
        config()->set('cache.default', 'redis');
        config()->set('cache.stores.redis', ['driver' => 'redis', 'connection' => 'cache']);
        config()->set('queue.default', 'database');
        config()->set('session.driver', 'database');
        $connection = Mockery::mock();
        $horizonConnection = Mockery::mock();
        $connection->shouldReceive('ping')->once()->andReturn('PONG');
        $horizonConnection->shouldReceive('ping')->once()->andReturn('PONG');
        Redis::shouldReceive('connection')->once()->with('cache')->andReturn($connection);
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($horizonConnection);

        self::assertTrue((new RedisProbe())->probe());
    }

    public function test_it_pings_redis_selected_by_a_failover_cache_store(): void
    {
        config()->set('cache.default', 'failover');
        config()->set('cache.stores.failover', [
            'driver' => 'failover',
            'stores' => ['database', 'redis'],
        ]);
        config()->set('cache.stores.redis', ['driver' => 'redis', 'connection' => 'cache']);
        config()->set('queue.default', 'database');
        config()->set('session.driver', 'database');
        $connection = Mockery::mock();
        $horizonConnection = Mockery::mock();
        $connection->shouldReceive('ping')->once()->andReturn('PONG');
        $horizonConnection->shouldReceive('ping')->once()->andReturn('PONG');
        Redis::shouldReceive('connection')->once()->with('cache')->andReturn($connection);
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($horizonConnection);

        self::assertTrue((new RedisProbe())->probe());
    }

    public function test_it_reports_a_failed_ping_as_unavailable(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis', ['driver' => 'redis', 'connection' => 'default']);
        config()->set('cache.default', 'database');
        config()->set('session.driver', 'database');
        $connection = Mockery::mock();
        $connection->shouldReceive('ping')->once()->andReturn(false);
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($connection);

        self::assertFalse((new RedisProbe())->probe());
    }
}
