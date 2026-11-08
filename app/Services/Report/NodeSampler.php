<?php

declare(strict_types=1);

namespace App\Services\Report;

final class NodeSampler
{
    public function __construct(
        private readonly NodeSampleStore $samples,
        private readonly WorkerBusyStateStore $workers,
        private readonly WorkerProcessSource $processes,
    ) {}

    /** @return array<string, mixed> */
    public function sample(?string $node = null): array
    {
        $node ??= self::nodeName();
        $node = trim($node) !== '' ? trim($node) : self::nodeName();
        $stat = @file_get_contents('/proc/stat');
        $meminfo = @file_get_contents('/proc/meminfo');
        $loadavg = @file_get_contents('/proc/loadavg');
        $currentCpu = is_string($stat) ? self::cpuCounters($stat) : null;
        $previous = $this->samples->samples($node);
        $previousCpu = $previous === [] ? null : ($previous[array_key_last($previous)]['cpu_counters'] ?? null);
        $workers = $this->workers->counts($node, $this->processes->processes());

        $sample = [
            'node' => $node,
            'sampled_at' => now()->timestamp,
            'cpu_percent' => is_array($previousCpu) && $currentCpu !== null ? self::cpuPercent($previousCpu, $currentCpu) : null,
            'cpu_counters' => $currentCpu,
            'load1' => is_string($loadavg) ? self::loadAverage($loadavg) : null,
            'mem_available_bytes' => is_string($meminfo) ? self::memoryAvailable($meminfo) : null,
            'disk_used_percent' => self::diskUsedPercent(trim((string) config('javmeta_report.node_volume_path', '')) ?: base_path()),
            'workers' => $workers,
        ];

        $this->samples->put($node, $sample);

        return $sample;
    }

    /** @return array{total: int, idle: int}|null */
    public static function cpuCounters(string $stat): ?array
    {
        if (preg_match('/^cpu\s+((?:\d+\s*){4,})$/m', $stat, $matches) !== 1) {
            return null;
        }
        $values = array_map('intval', preg_split('/\s+/', trim($matches[1])) ?: []);
        if (count($values) < 4) {
            return null;
        }

        return ['total' => array_sum(array_slice($values, 0, 8)), 'idle' => $values[3] + ($values[4] ?? 0)];
    }

    public static function nodeName(): string
    {
        $configured = trim((string) config('sentry.node', ''));
        if ($configured !== '' && strtolower($configured) !== 'unknown') {
            return $configured;
        }

        $hostname = gethostname();

        return is_string($hostname) && trim($hostname) !== '' ? trim($hostname) : 'unknown';
    }

    /** @param array{total: int, idle: int} $previous
     *  @param array{total: int, idle: int} $current
     */
    public static function cpuPercent(array $previous, array $current): ?float
    {
        $total = $current['total'] - $previous['total'];
        if ($total <= 0) {
            return null;
        }

        $idle = max(0, $current['idle'] - $previous['idle']);

        return round(max(0.0, min(100.0, (($total - $idle) / $total) * 100)), 2);
    }

    public static function memoryAvailable(string $meminfo): ?int
    {
        if (preg_match('/^MemAvailable:\s+(\d+)\s+kB$/m', $meminfo, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1] * 1024;
    }

    public static function loadAverage(string $loadavg): ?float
    {
        $parts = preg_split('/\s+/', trim($loadavg));

        return isset($parts[0]) && is_numeric($parts[0]) ? (float) $parts[0] : null;
    }

    public static function diskUsedPercent(string $path): ?float
    {
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        if (! is_numeric($total) || ! is_numeric($free) || (float) $total <= 0) {
            return null;
        }

        return round(max(0.0, min(100.0, (((float) $total - (float) $free) / (float) $total) * 100)), 2);
    }
}
