<?php

declare(strict_types=1);

namespace App\Services\Report;

use Carbon\CarbonImmutable;

final class NodeSampleAggregator
{
    /**
     * @param  array<string, list<array<string, mixed>>>  $samplesByNode
     * @return array<string, array<string, mixed>>
     */
    public function aggregate(array $samplesByNode, CarbonImmutable $since, CarbonImmutable $now, int $maxAgeSeconds): array
    {
        $nodes = [];
        foreach ($samplesByNode as $node => $samples) {
            usort($samples, static fn(array $left, array $right): int => (int) ($left['sampled_at'] ?? 0) <=> (int) ($right['sampled_at'] ?? 0));
            $latest = $samples === [] ? null : $samples[array_key_last($samples)];
            if (! is_array($latest) || $now->timestamp - (int) ($latest['sampled_at'] ?? 0) > $maxAgeSeconds) {
                continue;
            }

            $window = array_values(array_filter(
                $samples,
                static fn(array $sample): bool => (int) ($sample['sampled_at'] ?? 0) >= $since->timestamp
                    && (int) ($sample['sampled_at'] ?? 0) <= $now->timestamp,
            ));
            $cpu = array_values(array_filter(array_map(
                static fn(array $sample): ?float => is_numeric($sample['cpu_percent'] ?? null) ? (float) $sample['cpu_percent'] : null,
                $window,
            ), static fn(?float $value): bool => $value !== null));
            $workerSamples = array_values(array_filter($window, static fn(array $sample): bool => is_array($sample['workers'] ?? null)));
            $busyShares = [];
            foreach ($workerSamples as $sample) {
                $total = max(0, (int) ($sample['workers']['total'] ?? 0));
                if ($total > 0) {
                    $busyShares[] = max(0, (int) ($sample['workers']['busy'] ?? 0)) / $total;
                }
            }

            $nodes[$node] = [
                'sample_count' => count($window),
                'cpu_avg' => $cpu === [] ? null : array_sum($cpu) / count($cpu),
                'cpu_peak' => $cpu === [] ? null : max($cpu),
                'cpu_latest' => is_numeric($latest['cpu_percent'] ?? null) ? (float) $latest['cpu_percent'] : null,
                'mem_available_bytes' => is_numeric($latest['mem_available_bytes'] ?? null) ? (int) $latest['mem_available_bytes'] : null,
                'disk_used_percent' => is_numeric($latest['disk_used_percent'] ?? null) ? (float) $latest['disk_used_percent'] : null,
                'load1' => is_numeric($latest['load1'] ?? null) ? (float) $latest['load1'] : null,
                'workers' => is_array($latest['workers'] ?? null) ? $latest['workers'] : null,
                'busy_share_avg' => $busyShares === [] ? null : array_sum($busyShares) / count($busyShares),
            ];
        }

        ksort($nodes);

        return $nodes;
    }
}
