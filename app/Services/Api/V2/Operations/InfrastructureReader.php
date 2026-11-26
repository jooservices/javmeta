<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/** Hosts and dependencies of the platform, read from Prometheus plus a few probes. Read-only and cached for a short time. */
final class InfrastructureReader
{
    private const CPU_QUERY = '100 - avg by (instance)(rate(node_cpu_seconds_total{mode="idle"}[5m]))*100';

    /** @return array{data: array<string, mixed>, meta: array<string, mixed>} */
    public function read(): array
    {
        $base = (string) config('javmeta_ops.prometheus_url', '');
        if ($base === '') {
            return self::empty('prometheus_not_configured');
        }

        /** @var array{data: array<string, mixed>, meta: array<string, mixed>} $snapshot */
        $snapshot = Cache::remember('api-v2:infrastructure', (int) config('javmeta_ops.cache_seconds', 20), function () use ($base): array {
            try {
                return $this->snapshot(rtrim($base, '/'));
            } catch (RuntimeException) {
                return self::empty('prometheus_unreachable');
            }
        });

        return $snapshot;
    }

    /** @return array{data: array<string, mixed>, meta: array<string, mixed>} */
    private function snapshot(string $base): array
    {
        $vms = $this->vms($base);

        $data = [
            'at' => Iso::stamp(CarbonImmutable::now()),
            'vms' => $vms,
            'services' => $this->services($base),
            'series' => $this->cpuSeries($base, $vms),
            'deps' => $this->dependencies($base),
            'probes' => $this->probes($base),
        ];

        return ['data' => $data, 'meta' => []];
    }

    /** @return array{data: array<string, mixed>, meta: array<string, mixed>} */
    private static function empty(string $reason): array
    {
        return [
            'data' => ['at' => Iso::stamp(CarbonImmutable::now()), 'vms' => [], 'services' => new \stdClass(), 'series' => [], 'deps' => new \stdClass(), 'probes' => new \stdClass()],
            'meta' => ['degraded' => true, 'reason' => $reason],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function vms(string $base): array
    {
        $up = $this->query($base, 'up{job="node_exporter"}');
        $metrics = [
            'cpu' => $this->byInstance($this->query($base, self::CPU_QUERY)),
            'mem' => $this->byInstance($this->query($base, '(1 - node_memory_MemAvailable_bytes/node_memory_MemTotal_bytes)*100')),
            'disk' => $this->byInstance($this->query($base, '(1 - node_filesystem_avail_bytes{mountpoint="/",fstype!="rootfs"}/node_filesystem_size_bytes{mountpoint="/",fstype!="rootfs"})*100')),
            'load1' => $this->byInstance($this->query($base, 'node_load1')),
            'boot' => $this->byInstance($this->query($base, 'time() - node_boot_time_seconds')),
            'swapTotal' => $this->byInstance($this->query($base, 'node_memory_SwapTotal_bytes')),
            'swapFree' => $this->byInstance($this->query($base, 'node_memory_SwapFree_bytes')),
        ];
        $roles = (array) config('javmeta_ops.vm_roles', []);

        $vms = [];
        foreach ($up as $series) {
            $instance = (string) ($series['metric']['instance'] ?? '');
            $name = (string) ($series['metric']['vm_name'] ?? $instance);
            $swapTotal = $metrics['swapTotal'][$instance] ?? null;
            $vms[] = [
                'instance' => $instance,
                'ip' => explode(':', $instance)[0],
                'name' => $name,
                'role' => $roles[$name]['role'] ?? $name,
                'group' => $roles[$name]['group'] ?? 'ops',
                'up' => (string) ($series['value'][1] ?? '0') === '1',
                'cpu' => $metrics['cpu'][$instance] ?? null,
                'mem' => $metrics['mem'][$instance] ?? null,
                'disk' => $metrics['disk'][$instance] ?? null,
                'load1' => $metrics['load1'][$instance] ?? null,
                'uptimeDays' => isset($metrics['boot'][$instance]) ? round($metrics['boot'][$instance] / 86400, 1) : null,
                'swap' => $swapTotal ? round((1 - ($metrics['swapFree'][$instance] ?? 0) / $swapTotal) * 100, 1) : null,
            ];
        }
        usort($vms, static fn(array $a, array $b): int => strnatcmp((string) $a['instance'], (string) $b['instance']));

        return $vms;
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function services(string $base): array
    {
        $services = [];
        foreach ($this->query($base, 'node_systemd_unit_state{name=~"xcrawler.*",state="active"}') as $series) {
            $unit = (string) ($series['metric']['name'] ?? '');
            if (str_ends_with($unit, '.timer')) {
                continue;
            }
            $services[(string) ($series['metric']['vm_name'] ?? '')][] = [
                'unit' => str_replace('.service', '', $unit),
                'type' => $series['metric']['type'] ?? 'simple',
                'active' => (string) ($series['value'][1] ?? '0') === '1',
            ];
        }

        return $services;
    }

    /**
     * @param list<array<string, mixed>> $vms
     * @return array<string, list<array{t: string|null, v: float}>>
     */
    private function cpuSeries(string $base, array $vms): array
    {
        $series = [];
        foreach ($vms as $vm) {
            if (! str_starts_with((string) $vm['name'], 'xcrawler')) {
                continue;
            }
            $query = '100 - avg(rate(node_cpu_seconds_total{mode="idle",instance="' . $vm['instance'] . '"}[5m]))*100';
            $series[(string) $vm['name']] = $this->range($base, $query);
        }

        return $series;
    }

    /** @return array<string, array<string, mixed>> */
    private function dependencies(string $base): array
    {
        $health = $this->query($base, 'elasticsearch_cluster_health_status');
        $color = null;
        foreach ($health as $series) {
            if ((string) ($series['value'][1] ?? '') === '1') {
                $color = $series['metric']['color'] ?? null;
                break;
            }
        }
        $mongo = null;
        foreach ($this->query($base, 'mongodb_ss_connections') as $series) {
            if (($series['metric']['conn_type'] ?? null) === 'current') {
                $mongo = (float) ($series['value'][1] ?? 0);
            }
        }

        return [
            'postgres' => [
                'conn' => $this->one($base, 'sum(pg_stat_activity_count)'),
                'connXcrawler' => $this->one($base, 'sum(pg_stat_activity_count{datname="xcrawler"})'),
                'max' => $this->one($base, 'max(pg_settings_max_connections)'),
                'sizeBytes' => $this->one($base, 'pg_database_size_bytes{datname="xcrawler"}'),
                'series' => $this->range($base, 'sum(pg_stat_activity_count)'),
            ],
            'redis' => [
                'clients' => $this->one($base, 'redis_connected_clients'),
                'memBytes' => $this->one($base, 'redis_memory_used_bytes'),
                'opsPerSec' => $this->one($base, 'sum(rate(redis_commands_processed_total[5m]))'),
            ],
            'elasticsearch' => [
                'color' => $color,
                'shards' => $this->one($base, 'elasticsearch_cluster_health_active_shards'),
                'docs' => $this->one($base, 'elasticsearch_indices_docs'),
            ],
            'mongo' => ['connections' => $mongo, 'up' => $this->one($base, 'up{job="mongodb_exporter"}') === 1.0],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function probes(string $base): array
    {
        $probes = ['prometheus' => $this->http($base . '/-/healthy')];
        foreach ((array) config('javmeta_ops.probes', []) as $name => $target) {
            $probes[(string) $name] = isset($target['http']) ? $this->http((string) $target['http']) : $this->tcp((string) ($target['tcp'] ?? ''));
        }

        return $probes;
    }

    /** @return array{ok: bool, status: int|null, ms: int|null} */
    private function http(string $url): array
    {
        $start = hrtime(true);
        try {
            $response = Http::timeout((int) config('javmeta_ops.timeout_seconds', 5))->get($url);
        } catch (Throwable) {
            return ['ok' => false, 'status' => null, 'ms' => null];
        }

        return ['ok' => $response->status() < 400, 'status' => $response->status(), 'ms' => (int) round((hrtime(true) - $start) / 1_000_000)];
    }

    /** @return array{ok: bool, ms: int|null} */
    private function tcp(string $target): array
    {
        [$host, $port] = array_pad(explode(':', $target, 2), 2, '0');
        $start = hrtime(true);
        $socket = @fsockopen($host, (int) $port, $errno, $error, 3);
        if ($socket === false) {
            return ['ok' => false, 'ms' => null];
        }
        fclose($socket);

        return ['ok' => true, 'ms' => (int) round((hrtime(true) - $start) / 1_000_000)];
    }

    /**
     * @return list<array<string, mixed>> the instant-vector result
     * @throws RuntimeException when Prometheus does not answer
     */
    private function query(string $base, string $query): array
    {
        try {
            $response = Http::timeout((int) config('javmeta_ops.timeout_seconds', 5))->get($base . '/api/v1/query', ['query' => $query]);
            $result = $response->successful() ? $response->json('data.result') : null;
        } catch (Throwable) {
            $result = null;
        }

        if (! is_array($result)) {
            throw new RuntimeException('Prometheus did not answer.');
        }

        return array_values($result);
    }

    /** @return list<array{t: string|null, v: float}> the last 24 hours in 30 minute steps */
    private function range(string $base, string $query): array
    {
        $end = time();
        try {
            $values = Http::timeout((int) config('javmeta_ops.timeout_seconds', 5))
                ->get($base . '/api/v1/query_range', ['query' => $query, 'start' => $end - 86400, 'end' => $end, 'step' => 1800])
                ->json('data.result.0.values');
        } catch (Throwable) {
            return [];
        }

        return array_map(static fn(array $point): array => ['t' => Iso::stamp(CarbonImmutable::createFromTimestampUTC((int) $point[0])), 'v' => round((float) $point[1], 1)], is_array($values) ? $values : []);
    }

    private function one(string $base, string $query): ?float
    {
        $series = $this->query($base, $query)[0] ?? null;

        return $series === null ? null : (float) ($series['value'][1] ?? 0);
    }

    /**
     * @param list<array<string, mixed>> $result
     * @return array<string, float>
     */
    private function byInstance(array $result): array
    {
        $values = [];
        foreach ($result as $series) {
            $values[(string) ($series['metric']['instance'] ?? '')] = (float) ($series['value'][1] ?? 0);
        }

        return $values;
    }
}
