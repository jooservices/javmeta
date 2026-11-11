<?php

declare(strict_types=1);

namespace Tests\Unit\Workers;

use App\Services\Workers\HorizonLayoutSnapshot;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Events\MasterSupervisorLooped;
use Laravel\Horizon\MasterSupervisor;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

final class HorizonNodeRegistryTest extends TestCase
{
    public function test_horizon_registry_is_capped_at_200_and_has_a_ttl(): void
    {
        $registry = [];
        $redis = $this->redisWithRegistry($registry, 300);
        Redis::shouldReceive('connection')->times(300)->with((string) config('horizon.use', 'default'))->andReturn($redis);
        $supervisors = Mockery::mock(SupervisorRepository::class);
        $supervisors->shouldReceive('all')->times(300)->andReturn([]);

        for ($index = 0; $index < 300; $index++) {
            $this->snapshot('node-' . $index, $supervisors);
        }

        self::assertCount(200, $registry);
        self::assertSame(604800, $redis->ttlValue);
        self::assertArrayNotHasKey('node-0', $registry);
        self::assertArrayHasKey('node-299', $registry);
    }

    public function test_horizon_registry_prunes_nodes_older_than_one_day(): void
    {
        $staleAt = now()->subDays(2)->timestamp;
        $registry = ['stale-node' => $staleAt];
        $redis = $this->redisWithRegistry($registry, 1);
        Redis::shouldReceive('connection')->once()->with((string) config('horizon.use', 'default'))->andReturn($redis);
        $supervisors = Mockery::mock(SupervisorRepository::class);
        $supervisors->shouldReceive('all')->once()->andReturn([]);

        $this->snapshot('fresh-node', $supervisors);

        self::assertArrayNotHasKey('stale-node', $registry);
        self::assertArrayHasKey('fresh-node', $registry);
    }

    public function test_horizon_registry_applies_an_expiry_to_the_legacy_set(): void
    {
        $registry = [];
        $redis = $this->redisWithRegistry($registry, 1, -1);
        Redis::shouldReceive('connection')->once()->with((string) config('horizon.use', 'default'))->andReturn($redis);
        $supervisors = Mockery::mock(SupervisorRepository::class);
        $supervisors->shouldReceive('all')->once()->andReturn([]);

        $this->snapshot('fresh-node', $supervisors);

        self::assertTrue($redis->legacyExpiryApplied);
    }

    private function snapshot(string $node, SupervisorRepository $supervisors): void
    {
        config(['javmeta_workers.booted_layout' => [
            'hash' => 'booted',
            'node' => $node,
            'supervisors' => [],
        ]]);
        $master = (new ReflectionClass(MasterSupervisor::class))->newInstanceWithoutConstructor();
        $master->name = 'master-' . $node;

        app(HorizonLayoutSnapshot::class)->handle(new MasterSupervisorLooped($master), $supervisors);
    }

    /** @param array<string, int> $registry */
    private function redisWithRegistry(array &$registry, int $calls, int $legacyTtl = -2): object
    {
        $redis = Mockery::mock();
        $redis->ttlValue = null;
        $redis->legacyExpiryApplied = false;
        $redis->shouldReceive('setex')->times($calls)->andReturn(true);
        $redis->shouldReceive('zadd')->times($calls)->andReturnUsing(
            static function (string $key, int $score, string $node) use (&$registry): int {
                $registry[$node] = $score;

                return 1;
            },
        );
        $redis->shouldReceive('zremrangebyscore')->times($calls)->andReturnUsing(
            static function (string $key, string $minimum, int $maximum) use (&$registry): int {
                $before = count($registry);
                $registry = array_filter($registry, static fn(int $score): bool => $score > $maximum);

                return $before - count($registry);
            },
        );
        $redis->shouldReceive('zremrangebyrank')->times($calls)->andReturnUsing(
            static function (string $key, int $start, int $end) use (&$registry): int {
                asort($registry, SORT_NUMERIC);
                $removeCount = max(0, count($registry) - 200);
                $removed = array_slice(array_keys($registry), 0, $removeCount);
                foreach ($removed as $node) {
                    unset($registry[$node]);
                }

                return count($removed);
            },
        );
        $redis->shouldReceive('expire')->times($calls)->with(HorizonLayoutSnapshot::NODE_REGISTRY_KEY, 604800)
            ->andReturnUsing(static function () use ($redis): bool {
                $redis->ttlValue = 604800;

                return true;
            });
        $redis->shouldReceive('ttl')->times($calls)->with(HorizonLayoutSnapshot::LEGACY_NODE_REGISTRY_KEY)->andReturn($legacyTtl);
        if ($legacyTtl === -1) {
            $redis->shouldReceive('expire')->times($calls)->with(HorizonLayoutSnapshot::LEGACY_NODE_REGISTRY_KEY, 604800)
                ->andReturnUsing(static function () use ($redis): bool {
                    $redis->legacyExpiryApplied = true;

                    return true;
                });
        }
        $redis->shouldReceive('get')->times($calls)->with(HorizonLayoutSnapshot::FLEET_RECONCILE_LOCK)->andReturn(null);

        return $redis;
    }
}
