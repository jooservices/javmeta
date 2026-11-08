<?php

declare(strict_types=1);

namespace App\Services\Workers;

use App\Observability\MetricStreams;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Events\MasterSupervisorLooped;

final class HorizonLayoutSnapshot
{
    // Keep the Redis key stable while older releases may still hold the fleet lock.
    public const FLEET_RECONCILE_LOCK = 'javmeta:horizon:reconcile:fleet-restart';

    public const NODE_REGISTRY_KEY = 'javmeta:horizon:worker-layout:nodes';

    public function handle(MasterSupervisorLooped $event, ?SupervisorRepository $supervisors = null): void
    {
        $supervisors ??= app(SupervisorRepository::class);
        $snapshot = config('javmeta_workers.booted_layout');
        if (! is_array($snapshot) || ! isset($snapshot['hash'])) {
            return;
        }

        $master = $event->master->name;
        $observed = [];
        foreach ($supervisors->all() as $supervisor) {
            if (! is_object($supervisor) || ($supervisor->master ?? null) !== $master) {
                continue;
            }
            $supervisorName = (string) ($supervisor->name ?? '');
            $prefix = $master . ':';
            if (! str_starts_with($supervisorName, $prefix)) {
                continue;
            }
            $name = substr($supervisorName, strlen($prefix));
            if ($name === '' || ! isset($snapshot['supervisors'][$name])) {
                continue;
            }
            $observed[$name] = true;
            $queue = $snapshot['supervisors'][$name]['queue'][0] ?? null;
            $processes = (array) ($supervisor->processes ?? []);
            $count = is_string($queue) ? ($processes['redis:' . $queue] ?? null) : null;
            $snapshot['supervisors'][$name]['maxProcesses'] = is_numeric($count) ? max(0, (int) $count) : 0;
        }
        foreach (array_keys($snapshot['supervisors']) as $name) {
            if (! isset($observed[$name])) {
                $snapshot['supervisors'][$name]['maxProcesses'] = 0;
            }
        }
        $snapshot['master'] = $master;
        $snapshot['hash'] = hash('sha256', json_encode($snapshot['supervisors'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $redis = Redis::connection(config('horizon.use', 'default'));
        $redis->setex(
            self::key((string) $snapshot['node']),
            180,
            json_encode(['snapshot' => $snapshot, 'pid' => getmypid() ?: 0], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        $redis->sadd(self::NODE_REGISTRY_KEY, (string) $snapshot['node']);
        $pending = (string) $redis->get(self::FLEET_RECONCILE_LOCK);
        $expected = json_decode($pending, true);
        if (($expected['node'] ?? null) === ($snapshot['node'] ?? null)
            && ($expected['hash'] ?? null) === $snapshot['hash']
            && now()->timestamp >= (int) ($expected['release_after'] ?? 0)) {
            $redis->command('eval', [
                "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end",
                [self::FLEET_RECONCILE_LOCK, $pending],
                1,
            ]);
        }
    }

    /** @param list<array<string, mixed>> $snapshots
     *  @return list<array{name: string, value: int, labels: array{queue: string, node: string}}>
     */
    public static function metricPoints(array $snapshots): array
    {
        $points = [];
        foreach ($snapshots as $snapshot) {
            $node = $snapshot['node'] ?? null;
            if (! is_string($node) || $node === '') {
                continue;
            }
            foreach (HorizonWorkerLayout::workerCounts((array) ($snapshot['supervisors'] ?? [])) as $queue => $count) {
                $points[] = [
                    'name' => MetricStreams::NAMES['horizon_workers'],
                    'value' => $count,
                    'labels' => ['queue' => $queue, 'node' => $node],
                ];
            }
        }

        return $points;
    }

    public static function key(string $node): string
    {
        return 'javmeta:horizon:worker-layout:' . $node;
    }
}
