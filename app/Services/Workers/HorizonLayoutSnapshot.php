<?php

declare(strict_types=1);

namespace App\Services\Workers;

use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Events\MasterSupervisorLooped;

final class HorizonLayoutSnapshot
{
    public const FLEET_RESTART_LOCK = 'javmeta:horizon:reconcile:fleet-restart';

    public function handle(MasterSupervisorLooped $event): void
    {
        $snapshot = config('javmeta_workers.booted_layout');
        if (! is_array($snapshot) || ! isset($snapshot['hash'])) {
            return;
        }

        $redis = Redis::connection(config('horizon.use', 'default'));
        $redis->setex(
            self::key((string) $snapshot['node']),
            180,
            json_encode(['snapshot' => $snapshot, 'pid' => getmypid() ?: 0], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        $pending = (string) $redis->get(self::FLEET_RESTART_LOCK);
        $expected = json_decode($pending, true);
        if (($expected['node'] ?? null) === ($snapshot['node'] ?? null) && ($expected['hash'] ?? null) === $snapshot['hash']) {
            $redis->command('eval', [
                "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end",
                [self::FLEET_RESTART_LOCK, $pending],
                1,
            ]);
        }
    }

    public static function key(string $node): string
    {
        return 'javmeta:horizon:worker-layout:' . $node;
    }
}
