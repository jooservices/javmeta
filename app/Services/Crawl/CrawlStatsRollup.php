<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CrawlStatsRollup
{
    public const GLOBAL_SOURCE = '__global__';
    public const GLOBAL_LANE = '__global__';
    public const GLOBAL_METHOD = '__global__';

    public function __construct(private readonly CrawlStatsAggregator $aggregator) {}

    /**
     * @return array{hours: int, attempt_rows: int, elapsed_ms: float}
     */
    public function rollup(CarbonImmutable $from, CarbonImmutable $until, bool $backfill = false): array
    {
        $started = hrtime(true);
        $from = $from->utc()->startOfHour();
        $until = $until->utc();

        if (! Schema::hasTable('crawl_fetch_attempts') || $until <= $from) {
            return ['hours' => 0, 'attempt_rows' => 0, 'elapsed_ms' => $this->elapsedMs($started)];
        }

        if ($backfill && ! DB::table('crawl_fetch_attempts')
            ->where('started_at', '>=', $from)
            ->where('started_at', '<', $until)
            ->exists()) {
            return ['hours' => 0, 'attempt_rows' => 0, 'elapsed_ms' => $this->elapsedMs($started)];
        }

        $attempts = DB::table('crawl_fetch_attempts')
            ->select(['source_slug', 'lane', 'method', 'started_at', 'duration_ms', 'http_status', 'cf_mitigated', 'outcome', 'error_code'])
            ->where('started_at', '>=', $from)
            ->where('started_at', '<', $until)
            ->orderBy('started_at')
            ->cursor();
        $aggregates = $this->aggregator->aggregate($attempts);
        $aggregateHours = [];
        foreach ($aggregates['hours'] as $hourAggregate) {
            $aggregateHours[$hourAggregate['hour_at']->toDateTimeString()] = $hourAggregate;
        }
        $hours = $this->hourBuckets($from, $until);
        $details = [];
        foreach ($aggregates['groups'] as $row) {
            $details[] = $this->databaseRow($row);
        }

        $globalData = $this->globalData($from, $until);
        $globalRows = [];
        foreach ($hours as $hour) {
            $key = $hour->toDateTimeString();
            $aggregate = $aggregateHours[$key] ?? null;
            $counts = $globalData[$key] ?? [];
            $globalRows[] = $this->databaseRow(array_merge(
                $aggregate ?? $this->emptyAggregate($hour),
                [
                    'source_slug' => self::GLOBAL_SOURCE,
                    'lane' => self::GLOBAL_LANE,
                    'method' => self::GLOBAL_METHOD,
                    'movies_new' => (int) ($counts['movies_new'] ?? 0),
                    'movies_updated' => (int) ($counts['movies_updated'] ?? 0),
                    'performers_new' => (int) ($counts['performers_new'] ?? 0),
                    'jobs_failed' => (int) ($counts['jobs_failed'] ?? 0),
                    'bytes_total' => $counts['bytes_total'] ?? null,
                ],
            ));
        }

        $now = now()->utc();
        if ($details !== []) {
            foreach ($details as &$detail) {
                $detail['created_at'] = $now;
            }
            unset($detail);
            DB::table('crawl_stats_hourly')->upsert(
                $details,
                ['hour_at', 'source_slug', 'lane', 'method'],
                ['attempts', 'ok', 'blocked', 'timeout', 'other_error', 'skipped', 'avg_duration_ms', 'p90_duration_ms', 'bytes_total', 'updated_at'],
            );
        }

        foreach ($globalRows as &$global) {
            $global['created_at'] = $now;
        }
        unset($global);
        DB::table('crawl_stats_hourly')->upsert(
            $globalRows,
            ['hour_at', 'source_slug', 'lane', 'method'],
            [
                'attempts', 'ok', 'blocked', 'timeout', 'other_error', 'skipped', 'avg_duration_ms',
                'p90_duration_ms', 'bytes_total', 'movies_new', 'movies_updated', 'performers_new',
                'jobs_failed', 'updated_at',
            ],
        );

        $retentionDays = max(1, (int) config('javmeta_stats.retention_days', 365));
        DB::table('crawl_stats_hourly')->where('hour_at', '<', now()->subDays($retentionDays))->delete();

        return [
            'hours' => count($hours),
            'attempt_rows' => count($details),
            'elapsed_ms' => $this->elapsedMs($started),
        ];
    }

    /** @return list<CarbonImmutable> */
    private function hourBuckets(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $hours = [];
        for ($hour = $from; $hour < $until; $hour = $hour->addHour()) {
            $hours[] = $hour;
        }

        return $hours;
    }

    /** @return array<string, array<string, int|null>> */
    private function globalData(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $expression = $this->hourExpression('started_at');
        $data = [];
        $runs = DB::table('crawl_runs')
            ->selectRaw("{$expression} AS hour_at, SUM(movies_new) AS movies_new, SUM(movies_updated) AS movies_updated, SUM(proxy_bytes) AS bytes_total")
            ->where('started_at', '>=', $from)
            ->where('started_at', '<', $until)
            ->groupByRaw($expression)
            ->get();

        foreach ($runs as $row) {
            $key = $this->hourKey($row->hour_at);
            $data[$key] = [
                'movies_new' => (int) $row->movies_new,
                'movies_updated' => (int) $row->movies_updated,
                'performers_new' => 0,
                'jobs_failed' => 0,
                'bytes_total' => (int) $row->bytes_total,
            ];
        }

        $performerExpression = $this->hourExpression('first_seen_at');
        $performers = DB::table('performers')
            ->selectRaw("{$performerExpression} AS hour_at, COUNT(*) AS total")
            ->whereNotNull('first_seen_at')
            ->where('first_seen_at', '>=', $from)
            ->where('first_seen_at', '<', $until)
            ->groupByRaw($performerExpression)
            ->get();
        foreach ($performers as $row) {
            $key = $this->hourKey($row->hour_at);
            $data[$key] ??= ['movies_new' => 0, 'movies_updated' => 0, 'performers_new' => 0, 'jobs_failed' => 0, 'bytes_total' => null];
            $data[$key]['performers_new'] = (int) $row->total;
        }

        if (Schema::hasTable('failed_jobs')) {
            $failureExpression = $this->hourExpression('failed_at');
            $failures = DB::table('failed_jobs')
                ->selectRaw("{$failureExpression} AS hour_at, COUNT(*) AS total")
                ->where('failed_at', '>=', $from)
                ->where('failed_at', '<', $until)
                ->groupByRaw($failureExpression)
                ->get();
            foreach ($failures as $row) {
                $key = $this->hourKey($row->hour_at);
                $data[$key] ??= ['movies_new' => 0, 'movies_updated' => 0, 'performers_new' => 0, 'jobs_failed' => 0, 'bytes_total' => null];
                $data[$key]['jobs_failed'] = (int) $row->total;
            }
        }

        return $data;
    }

    private function hourExpression(string $column): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? "date_trunc('hour', {$column})"
            : "strftime('%Y-%m-%d %H:00:00', {$column})";
    }

    private function hourKey(mixed $hour): string
    {
        return CarbonImmutable::parse((string) $hour)->utc()->startOfHour()->toDateTimeString();
    }

    private function emptyAggregate(CarbonImmutable $hour): array
    {
        return [
            'hour_at' => $hour,
            'attempts' => 0,
            'ok' => 0,
            'blocked' => 0,
            'timeout' => 0,
            'other_error' => 0,
            'skipped' => 0,
            'avg_duration_ms' => null,
            'p90_duration_ms' => null,
            'bytes_total' => null,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function databaseRow(array $row): array
    {
        return [
            'hour_at' => $row['hour_at'],
            'source_slug' => (string) ($row['source_slug'] ?? self::GLOBAL_SOURCE),
            'lane' => (string) ($row['lane'] ?? self::GLOBAL_LANE),
            'method' => (string) ($row['method'] ?? self::GLOBAL_METHOD),
            'attempts' => (int) ($row['attempts'] ?? 0),
            'ok' => (int) ($row['ok'] ?? 0),
            'blocked' => (int) ($row['blocked'] ?? 0),
            'timeout' => (int) ($row['timeout'] ?? 0),
            'other_error' => (int) ($row['other_error'] ?? 0),
            'skipped' => (int) ($row['skipped'] ?? 0),
            'avg_duration_ms' => $row['avg_duration_ms'] ?? null,
            'p90_duration_ms' => $row['p90_duration_ms'] ?? null,
            'bytes_total' => $row['bytes_total'] ?? null,
            'movies_new' => $row['movies_new'] ?? null,
            'movies_updated' => $row['movies_updated'] ?? null,
            'performers_new' => $row['performers_new'] ?? null,
            'jobs_failed' => $row['jobs_failed'] ?? null,
            'updated_at' => now()->utc(),
        ];
    }

    private function elapsedMs(int $started): float
    {
        return round((hrtime(true) - $started) / 1_000_000, 2);
    }
}
