<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\CrawlQueue;
use App\Models\Movie;
use App\Models\Performer;
use App\Models\ReportSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ReportDataBuilder
{
    public function __construct(
        private readonly NodeSampleStore $samples,
        private readonly NodeSampleAggregator $sampleAggregator,
        private readonly ReportServiceStats $serviceStats,
    ) {}

    /** @return array<string, mixed> */
    public function build(?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();
        $minutes = (int) config('javmeta_report.interval_minutes', 120);
        $start = $at->subMinutes($minutes);
        $previous = ReportSnapshot::query()->where('result', 'sent')->orderByDesc('id')->first();
        $previousValue = $previous?->getAttribute('raw_numbers');
        $previousRaw = is_array($previousValue) ? $previousValue : null;
        $raw = [
            'generated_at' => $at->toIso8601String(),
            'window_minutes' => $minutes,
            'window_start' => $start->toIso8601String(),
            'window_end' => $at->toIso8601String(),
            'sections' => $this->sections(),
        ];

        if (in_array('movies', $this->sections(), true)) {
            $raw['movies'] = $this->entity('movies', 'crawled_at', (int) Movie::query()->count(), $previousRaw, $start, $at, $minutes);
        }
        if (in_array('performers', $this->sections(), true)) {
            $raw['performers'] = $this->entity('performers', 'first_seen_at', (int) Performer::query()->count(), $previousRaw, $start, $at, $minutes);
        }
        if (in_array('crawl', $this->sections(), true)) {
            $raw['crawl'] = $this->crawl($start, $at);
        }
        if (in_array('sources', $this->sections(), true)) {
            $raw['sources'] = $this->sources($start, $at);
        }

        $nodeSamples = $this->samples->all();
        $raw['node_names'] = array_keys($nodeSamples);
        $raw['nodes'] = $this->sampleAggregator->aggregate(
            $nodeSamples,
            $start,
            $at,
            max(60, (int) config('javmeta_report.sample_max_age_seconds', 180)),
        );
        if (in_array('fleet', $this->sections(), true)) {
            $raw['fleet'] = $this->fleet($raw['nodes'], $start);
        }
        if (in_array('workers', $this->sections(), true)) {
            $raw['workers'] = $raw['nodes'];
        }
        if (in_array('servers', $this->sections(), true)) {
            $raw['servers'] = $raw['nodes'];
        }
        $serviceStats = [];
        if (in_array('services', $this->sections(), true) || in_array('servers', $this->sections(), true)) {
            $serviceStats = $this->serviceStats->collect();
        }
        if (in_array('services', $this->sections(), true)) {
            $raw['services'] = $serviceStats;
        }
        if (in_array('servers', $this->sections(), true)) {
            $raw['server_extra'] = $serviceStats;
        }

        return $raw;
    }

    /** @return list<string> */
    private function sections(): array
    {
        return array_values((array) config('javmeta_report.sections', []));
    }

    /** @param array<string, mixed>|null $previousRaw
     *  @return array<string, int|float>
     */
    private function entity(string $table, string $dateColumn, int $total, ?array $previousRaw, CarbonImmutable $start, CarbonImmutable $at, int $minutes): array
    {
        $lastReportTotal = (int) ($previousRaw[$table]['total'] ?? $total);
        $since = (int) DB::table($table)->whereBetween($dateColumn, [$start, $at])->count();
        $last24 = (int) DB::table($table)->where($dateColumn, '>=', $at->subDay())->where($dateColumn, '<=', $at)->count();
        $buckets = $this->hourBuckets($table, $dateColumn, $start, $at);
        $hours = max(1 / 60, $minutes / 60);
        $scale = $minutes < 60 ? 60 / $minutes : 1;

        return [
            'total' => $total,
            'since_last' => $total - $lastReportTotal,
            'last_24h' => $last24,
            'avg_per_hour' => round($since / $hours, 2),
            'peak_per_hour' => round(($buckets === [] ? 0 : max($buckets)) * $scale, 2),
        ];
    }

    /** @return list<int> */
    private function hourBuckets(string $table, string $dateColumn, CarbonImmutable $start, CarbonImmutable $at): array
    {
        $bucket = match (DB::connection()->getDriverName()) {
            'pgsql' => "date_trunc('hour', {$dateColumn})",
            'sqlite' => "strftime('%Y-%m-%d %H:00:00', {$dateColumn})",
            default => "DATE_FORMAT({$dateColumn}, '%Y-%m-%d %H:00:00')",
        };
        $rows = DB::table($table)
            ->whereBetween($dateColumn, [$start, $at])
            ->whereNotNull($dateColumn)
            ->selectRaw($bucket . ' AS hour_bucket, COUNT(*) AS items')
            ->groupBy('hour_bucket')
            ->get();

        return $rows->map(static fn(object $row): int => (int) $row->items)->all();
    }

    /** @return array<string, array{attempts: int, success_percent: float, blocked_percent: float}> */
    private function crawl(CarbonImmutable $start, CarbonImmutable $at): array
    {
        if (! Schema::hasTable('crawl_fetch_attempts')) {
            return [];
        }
        $rows = DB::table('crawl_fetch_attempts')
            ->whereIn('kind', [CrawlQueue::KIND_LISTING, CrawlQueue::KIND_DETAIL, CrawlQueue::KIND_GALLERY, CrawlQueue::KIND_PERFORMER_LISTING, CrawlQueue::KIND_PERFORMER_DETAIL])
            ->whereBetween('started_at', [$start, $at])
            ->selectRaw("kind, COUNT(*) AS attempts, SUM(CASE WHEN outcome <> 'skipped' THEN 1 ELSE 0 END) AS counted, SUM(CASE WHEN outcome <> 'skipped' AND http_status >= 200 AND http_status < 300 THEN 1 ELSE 0 END) AS successes, SUM(CASE WHEN http_status IN (403, 429) OR cf_mitigated IS NOT NULL THEN 1 ELSE 0 END) AS blocked")
            ->groupBy('kind')
            ->get()
            ->keyBy('kind');
        $result = [];
        foreach ([
            'movies' => [CrawlQueue::KIND_LISTING, CrawlQueue::KIND_DETAIL, CrawlQueue::KIND_GALLERY],
            'performers' => [CrawlQueue::KIND_PERFORMER_LISTING, CrawlQueue::KIND_PERFORMER_DETAIL],
        ] as $entity => $kinds) {
            $attempts = $counted = $successes = $blocked = 0;
            foreach ($kinds as $kind) {
                $row = $rows->get($kind);
                $attempts += (int) ($row->attempts ?? 0);
                $counted += (int) ($row->counted ?? 0);
                $successes += (int) ($row->successes ?? 0);
                $blocked += (int) ($row->blocked ?? 0);
            }
            if ($attempts > 0) {
                $result[$entity] = [
                    'attempts' => $attempts,
                    'success_percent' => $counted > 0 ? round(($successes / $counted) * 100, 1) : 0.0,
                    'blocked_percent' => round(($blocked / $attempts) * 100, 1),
                ];
            }
        }

        return $result;
    }

    /** @return array{blocked: list<string>, exhausted: list<string>} */
    private function sources(CarbonImmutable $start, CarbonImmutable $at): array
    {
        $limit = max(1, min(5, (int) config('javmeta_report.top_sources_limit', 5)));
        $blocked = [];
        if (Schema::hasTable('crawl_fetch_attempts')) {
            $blocked = DB::table('crawl_fetch_attempts')
                ->whereIn('kind', [CrawlQueue::KIND_LISTING, CrawlQueue::KIND_DETAIL, CrawlQueue::KIND_GALLERY, CrawlQueue::KIND_PERFORMER_LISTING, CrawlQueue::KIND_PERFORMER_DETAIL])
                ->whereBetween('started_at', [$start, $at])
                ->where(static fn($query) => $query->whereIn('http_status', [403, 429])->orWhereNotNull('cf_mitigated'))
                ->select('source_slug')
                ->selectRaw('COUNT(*) AS blocked_count')
                ->groupBy('source_slug')
                ->orderByDesc('blocked_count')
                ->orderBy('source_slug')
                ->limit($limit)
                ->pluck('source_slug')
                ->all();
        }

        $exhausted = CrawlQueue::query()
            ->where('status', CrawlQueue::STATUS_FAILED)
            ->where('last_failed_at', '>=', $start)
            ->whereColumn('attempts', '>=', 'max_attempts')
            ->select('source_slug')
            ->selectRaw('COUNT(*) AS exhausted_count')
            ->groupBy('source_slug')
            ->orderByDesc('exhausted_count')
            ->orderBy('source_slug')
            ->limit($limit)
            ->pluck('source_slug')
            ->all();

        return ['blocked' => array_map('strval', $blocked), 'exhausted' => array_map('strval', $exhausted)];
    }

    /** @param array<string, array<string, mixed>> $nodes
     *  @return array{nodes_running: int, failed_jobs: int}
     */
    private function fleet(array $nodes, CarbonImmutable $start): array
    {
        $failedJobs = Schema::hasTable('failed_jobs')
            ? (int) DB::table('failed_jobs')->where('failed_at', '>=', $start)->count()
            : 0;

        return ['nodes_running' => count($nodes), 'failed_jobs' => $failedJobs];
    }
}
