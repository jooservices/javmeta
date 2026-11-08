<?php

declare(strict_types=1);

namespace App\Services\Report;

use Illuminate\Support\Facades\Cache;

final class WorkerBusyStateStore
{
    /** @param list<array{pid: int, lane: string}> $processes
     *  @return array{busy: int, idle: int, total: int, lanes: array<string, array{busy: int, idle: int, total: int}>}
     */
    public function counts(string $node, array $processes): array
    {
        $counts = [
            'busy' => 0,
            'idle' => 0,
            'total' => 0,
            'lanes' => [
                'direct' => ['busy' => 0, 'idle' => 0, 'total' => 0],
                'browser' => ['busy' => 0, 'idle' => 0, 'total' => 0],
            ],
        ];

        foreach ($processes as $process) {
            $lane = in_array($process['lane'], ['direct', 'browser'], true) ? $process['lane'] : 'direct';
            $marker = Cache::get(self::key($node, $process['pid']));
            $busy = is_array($marker) && in_array($marker['lane'] ?? null, ['direct', 'browser'], true);
            if ($busy) {
                $lane = (string) $marker['lane'];
            }

            $state = $busy ? 'busy' : 'idle';
            $counts[$state]++;
            $counts['total']++;
            $counts['lanes'][$lane][$state]++;
            $counts['lanes'][$lane]['total']++;
        }

        return $counts;
    }

    public function markBusy(string $node, int $pid, string $lane, string $queue): void
    {
        if ($pid < 1 || ! in_array($lane, ['direct', 'browser'], true)) {
            return;
        }

        Cache::put(
            self::key($node, $pid),
            ['lane' => $lane, 'queue' => mb_substr($queue, 0, 100), 'started_at' => now()->timestamp],
            max(30, (int) config('javmeta_report.worker_busy_ttl_seconds', 3600)),
        );
    }

    public function clear(string $node, int $pid): void
    {
        if ($pid > 0) {
            Cache::forget(self::key($node, $pid));
        }
    }

    public static function key(string $node, int $pid): string
    {
        return 'javmeta:worker:busy:' . rawurlencode($node) . ':' . $pid;
    }
}
