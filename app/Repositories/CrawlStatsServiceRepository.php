<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\CrawlQueue;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JOOservices\LaravelRepository\Contracts\RepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasCrud;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;

/** Persistence queries for crawl statistics and their operational samples. */
final class CrawlStatsServiceRepository extends EloquentRepository implements RepositoryInterface
{
    use HasCrud;
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;

    public function __construct(CrawlQueue $model)
    {
        parent::__construct($model);
    }

    public function hasFetchAttemptsTable(): bool
    {
        return Schema::hasTable('crawl_fetch_attempts');
    }

    public function hasFailedJobsTable(): bool
    {
        return Schema::hasTable('failed_jobs');
    }

    public function hasHourlyStatsTable(): bool
    {
        return Schema::hasTable('crawl_stats_hourly');
    }

    public function hasQueueTable(): bool
    {
        return Schema::hasTable('crawl_queue');
    }

    public function driverName(): string
    {
        return DB::connection()->getDriverName();
    }

    public function attemptsExist(DateTimeInterface $from, DateTimeInterface $until): bool
    {
        return DB::table('crawl_fetch_attempts')
            ->where('started_at', '>=', $this->bound($from))
            ->where('started_at', '<', $this->bound($until))
            ->exists();
    }

    /** @return iterable<object> */
    public function attemptsForHour(DateTimeInterface $from, DateTimeInterface $until): iterable
    {
        return DB::table('crawl_fetch_attempts')
            ->select(['source_slug', 'lane', 'method', 'started_at', 'duration_ms', 'http_status', 'cf_mitigated', 'outcome', 'error_code'])
            ->where('started_at', '>=', $this->bound($from))
            ->where('started_at', '<', $this->bound($until))
            ->orderBy('started_at')
            ->cursor();
    }

    /** @return array<string, array<string, int|null>> */
    public function globalData(DateTimeInterface $from, DateTimeInterface $until): array
    {
        $from = CarbonImmutable::instance($from)->utc();
        $until = CarbonImmutable::instance($until)->utc();
        $expression = $this->hourExpression('started_at');
        $data = [];
        $runs = DB::table('crawl_runs')
            ->selectRaw("{$expression} AS hour_at, SUM(movies_new) AS movies_new, SUM(movies_updated) AS movies_updated, SUM(proxy_bytes) AS bytes_total")
            ->where('started_at', '>=', $this->bound($from))
            ->where('started_at', '<', $this->bound($until))
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
            ->where('first_seen_at', '>=', $this->bound($from))
            ->where('first_seen_at', '<', $this->bound($until))
            ->groupByRaw($performerExpression)
            ->get();
        foreach ($performers as $row) {
            $key = $this->hourKey($row->hour_at);
            $data[$key] ??= ['movies_new' => 0, 'movies_updated' => 0, 'performers_new' => 0, 'jobs_failed' => 0, 'bytes_total' => null];
            $data[$key]['performers_new'] = (int) $row->total;
        }

        if ($this->hasFailedJobsTable()) {
            $failureExpression = $this->hourExpression('failed_at', false);
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

    /** @param list<array<string, mixed>> $details @param array<string, mixed> $global */
    public function storeHour(array $details, array $global): void
    {
        DB::transaction(function () use ($details, $global): void {
            if ($details !== []) {
                DB::table('crawl_stats_hourly')->upsert(
                    $details,
                    ['hour_at', 'source_slug', 'lane', 'method'],
                    ['attempts', 'ok', 'blocked', 'timeout', 'other_error', 'skipped', 'avg_duration_ms', 'p90_duration_ms', 'bytes_total', 'updated_at'],
                );
            }

            DB::table('crawl_stats_hourly')->upsert(
                [$global],
                ['hour_at', 'source_slug', 'lane', 'method'],
                [
                    'attempts', 'ok', 'blocked', 'timeout', 'other_error', 'skipped', 'avg_duration_ms',
                    'p90_duration_ms', 'bytes_total', 'movies_new', 'movies_updated', 'performers_new',
                    'jobs_failed', 'updated_at',
                ],
            );
        });
    }

    public function bound(DateTimeInterface $moment): CarbonImmutable|string
    {
        $moment = CarbonImmutable::instance($moment);

        return $this->driverName() === 'pgsql' ? $moment->utc()->format('Y-m-d H:i:s.uP') : $moment;
    }

    public function sampleOperationalStats(CarbonImmutable $now, bool $enabled, int $browserSlots): void
    {
        if (! $enabled || ! $this->hasHourlyStatsTable() || ! $this->hasQueueTable()) {
            return;
        }

        $now = $now->utc();
        $hour = $now->copy()->startOfHour();
        $key = [
            'hour_at' => $hour,
            'source_slug' => '__global__',
            'lane' => '__global__',
            'method' => '__global__',
        ];
        $queue = DB::table('crawl_queue')->selectRaw(
            'SUM(CASE WHEN status = ? AND (next_attempt_at IS NULL OR next_attempt_at <= ?) THEN 1 ELSE 0 END) AS ready, '
            . 'SUM(CASE WHEN status = ? AND next_attempt_at > ? THEN 1 ELSE 0 END) AS delayed, '
            . 'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS reserved',
            ['pending', $now, 'pending', $now, 'claimed'],
        )->first();

        $playwrightRatio = null;
        $flaresolverrRatio = null;
        if ($this->hasFetchAttemptsTable()) {
            $durations = DB::table('crawl_fetch_attempts')
                ->where('created_at', '>=', $now->copy()->subMinute())
                ->selectRaw(
                    "SUM(CASE WHEN lower(method) IN ('playwright', 'playwright_stealth', 'chrome_stealth') AND outcome <> 'skipped' THEN duration_ms ELSE 0 END) AS playwright_ms, "
                    . "SUM(CASE WHEN lower(method) = 'flaresolverr' AND outcome <> 'skipped' THEN duration_ms ELSE 0 END) AS flaresolverr_ms",
                )->first();
            $slots = max(1, $browserSlots);
            $playwrightRatio = min(1.0, max(0.0, (int) ($durations->playwright_ms ?? 0) / ($slots * 60_000)));
            $flaresolverrRatio = min(1.0, max(0.0, (int) ($durations->flaresolverr_ms ?? 0) / ($slots * 60_000)));
        }

        $values = array_merge($key, [
            'queue_ready_avg' => (float) ($queue->ready ?? 0),
            'queue_delayed_avg' => (float) ($queue->delayed ?? 0),
            'queue_reserved_avg' => (float) ($queue->reserved ?? 0),
            'playwright_busy_ratio_avg' => $playwrightRatio,
            'flaresolverr_busy_ratio_avg' => $flaresolverrRatio,
            'queue_samples' => 1,
            'playwright_samples' => $playwrightRatio === null ? 0 : 1,
            'flaresolverr_samples' => $flaresolverrRatio === null ? 0 : 1,
            'postgres_connections_avg' => null,
            'postgres_samples' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $updates = [
            'queue_ready_avg' => DB::raw($this->averageExpression('queue_ready_avg', 'queue_samples')),
            'queue_delayed_avg' => DB::raw($this->averageExpression('queue_delayed_avg', 'queue_samples')),
            'queue_reserved_avg' => DB::raw($this->averageExpression('queue_reserved_avg', 'queue_samples')),
            'queue_samples' => DB::raw('crawl_stats_hourly.queue_samples + 1'),
            'playwright_busy_ratio_avg' => DB::raw($this->averageExpression('playwright_busy_ratio_avg', 'playwright_samples')),
            'flaresolverr_busy_ratio_avg' => DB::raw($this->averageExpression('flaresolverr_busy_ratio_avg', 'flaresolverr_samples')),
            'playwright_samples' => DB::raw($this->sampleCountExpression('playwright_busy_ratio_avg', 'playwright_samples')),
            'flaresolverr_samples' => DB::raw($this->sampleCountExpression('flaresolverr_busy_ratio_avg', 'flaresolverr_samples')),
            'updated_at' => DB::raw('excluded.updated_at'),
        ];
        if ($this->driverName() === 'pgsql') {
            // PostgreSQL-only operational metric; CrawlStatsRollupTest exercises this path on real PostgreSQL.
            $connections = DB::selectOne("SELECT COUNT(*)::int AS used FROM pg_stat_activity WHERE backend_type = 'client backend'");
            $values['postgres_connections_avg'] = (float) ($connections->used ?? 0);
            $values['postgres_samples'] = 1;
            $updates['postgres_connections_avg'] = DB::raw($this->averageExpression('postgres_connections_avg', 'postgres_samples'));
            $updates['postgres_samples'] = DB::raw('crawl_stats_hourly.postgres_samples + 1');
        }

        DB::table('crawl_stats_hourly')->upsert([$values], ['hour_at', 'source_slug', 'lane', 'method'], $updates);
    }

    private function hourExpression(string $column, bool $withTimeZone = true): string
    {
        return $this->driverName() === 'pgsql'
            ? ($withTimeZone ? "date_trunc('hour', {$column}, 'UTC')" : "date_trunc('hour', {$column})")
            : "strftime('%Y-%m-%d %H:00:00', {$column})";
    }

    private function hourKey(mixed $hour): string
    {
        return CarbonImmutable::parse((string) $hour)->utc()->startOfHour()->toDateTimeString();
    }

    private function averageExpression(string $average, string $samples): string
    {
        return sprintf(
            'CASE WHEN excluded.%1$s IS NULL THEN crawl_stats_hourly.%1$s WHEN crawl_stats_hourly.%1$s IS NULL OR crawl_stats_hourly.%2$s = 0 THEN excluded.%1$s ELSE crawl_stats_hourly.%1$s + (1.0 * (excluded.%1$s - crawl_stats_hourly.%1$s) / (crawl_stats_hourly.%2$s + 1)) END',
            $average,
            $samples,
        );
    }

    private function sampleCountExpression(string $average, string $samples): string
    {
        return sprintf(
            'CASE WHEN excluded.%1$s IS NULL THEN crawl_stats_hourly.%2$s ELSE crawl_stats_hourly.%2$s + 1 END',
            $average,
            $samples,
        );
    }
}
