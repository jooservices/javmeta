<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Services\Dependencies\Dependency;
use App\Services\Dependencies\DependencyMonitor;
use App\Services\Search\ElasticsearchHttpClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class ReportServiceStats
{
    public function __construct(private readonly ElasticsearchHttpClient $elasticsearch) {}

    /** @return array<string, mixed> */
    public function collect(): array
    {
        $stats = [];
        $stats['postgres'] = $this->postgres();
        $stats['redis'] = $this->redis();
        $stats['elasticsearch'] = $this->elasticsearch();
        $stats['browser'] = $this->browser();

        $url = trim((string) config('javmeta_report.browser_stats_url', ''));
        if ($url !== '') {
            $stats['browser_stats'] = $this->browserStats($url);
        }

        return array_filter($stats, static fn(mixed $value): bool => $value !== null && $value !== []);
    }

    /** @return array{used: int, maximum: int}|null */
    private function postgres(): ?array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return null;
        }
        try {
            $row = DB::selectOne("SELECT COUNT(*)::int AS used, current_setting('max_connections')::int AS maximum FROM pg_stat_activity WHERE backend_type = 'client backend'");

            return ['used' => max(0, (int) ($row->used ?? 0)), 'maximum' => max(1, (int) ($row->maximum ?? 1))];
        } catch (Throwable) {
            return null;
        }
    }

    private function redis(): ?string
    {
        try {
            $info = Redis::connection(config('horizon.use', 'default'))->info('memory');
            $value = is_array($info) ? ($info['used_memory_human'] ?? $info['memory']['used_memory_human'] ?? null) : null;

            return is_string($value) ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function elasticsearch(): ?string
    {
        if (! (bool) config('elasticsearch.enabled', true)) {
            return null;
        }
        try {
            $response = $this->elasticsearch->get('report_health', '/_cluster/health', 2);
            if (! $response->successful()) {
                return 'red';
            }
            $state = $response->json('status');

            return is_string($state) ? strtolower($state) : null;
        } catch (Throwable) {
            return 'down';
        }
    }

    private function browser(): ?string
    {
        try {
            $state = app(DependencyMonitor::class)->check(Dependency::Browser);
            if ($state['state'] === 'disabled') {
                return null;
            }

            return $state['available'] ? 'up' : 'down';
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, int|float>|null */
    private function browserStats(string $url): ?array
    {
        try {
            $response = Http::acceptJson()->connectTimeout(1)->timeout(2)->get($url);
            $data = $response->successful() ? $response->json() : null;
            if (! is_array($data)) {
                return null;
            }

            return array_filter([
                'cpu_avg' => is_numeric($data['cpu_avg'] ?? null) ? (float) $data['cpu_avg'] : null,
                'cpu_peak' => is_numeric($data['cpu_peak'] ?? null) ? (float) $data['cpu_peak'] : null,
                'mem_available_bytes' => is_numeric($data['mem_available_bytes'] ?? null) ? (int) $data['mem_available_bytes'] : null,
                'disk_used_percent' => is_numeric($data['disk_used_percent'] ?? null) ? (float) $data['disk_used_percent'] : null,
                'chrome_processes' => is_numeric($data['chrome_processes'] ?? null) ? (int) $data['chrome_processes'] : null,
                'playwright_busy' => is_numeric($data['playwright_busy'] ?? null) ? (int) $data['playwright_busy'] : null,
                'playwright_total' => is_numeric($data['playwright_total'] ?? null) ? (int) $data['playwright_total'] : null,
            ], static fn(mixed $value): bool => $value !== null);
        } catch (Throwable) {
            return null;
        }
    }
}
