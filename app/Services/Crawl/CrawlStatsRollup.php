<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use App\Repositories\CrawlStatsServiceRepository;
use InvalidArgumentException;

final class CrawlStatsRollup
{
    public const GLOBAL_SOURCE = '__global__';
    public const GLOBAL_LANE = '__global__';
    public const GLOBAL_METHOD = '__global__';

    public function __construct(
        private readonly CrawlStatsAggregator $aggregator,
        private readonly ?CrawlStatsServiceRepository $repository = null,
    ) {}

    /**
     * @return array{hours: int, attempt_rows: int, elapsed_ms: float}
     */
    public function rollup(DateTimeInterface $from, DateTimeInterface $until, bool $backfill = false): array
    {
        $started = hrtime(true);
        $from = CarbonImmutable::instance($from)->utc()->startOfHour();
        $until = CarbonImmutable::instance($until)->utc();

        if (! $this->repository()->hasFetchAttemptsTable() || $until <= $from) {
            return ['hours' => 0, 'attempt_rows' => 0, 'elapsed_ms' => $this->elapsedMs($started)];
        }

        if ($backfill && $from < $this->oldestRetainedHour()) {
            throw new InvalidArgumentException('Crawl statistics backfill is outside the retention window.');
        }

        if ($backfill && ! $this->repository()->attemptsExist($from, $until)) {
            return ['hours' => 0, 'attempt_rows' => 0, 'elapsed_ms' => $this->elapsedMs($started)];
        }

        $hours = $this->hourBuckets($from, $until);
        $attemptRows = 0;
        foreach ($hours as $hour) {
            $attemptRows += $this->rollupHour($hour, $until);
        }

        return [
            'hours' => count($hours),
            'attempt_rows' => $attemptRows,
            'elapsed_ms' => $this->elapsedMs($started),
        ];
    }

    private function rollupHour(CarbonImmutable $hour, CarbonImmutable $until): int
    {
        $hourUntil = $hour->addHour();
        if ($hourUntil > $until) {
            $hourUntil = $until;
        }

        $attempts = $this->repository()->attemptsForHour($hour, $hourUntil);
        $aggregates = $this->aggregator->aggregate($attempts);
        $details = [];
        foreach ($aggregates['groups'] as $row) {
            $details[] = $this->databaseRow($row);
        }

        $counts = $this->repository()->globalData($hour, $hourUntil)[$hour->toDateTimeString()] ?? [];
        $aggregate = $aggregates['hours'][0] ?? $this->emptyAggregate($hour);
        $global = $this->databaseRow(array_merge(
            $aggregate,
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

        $now = now()->utc();
        if ($details !== []) {
            foreach ($details as &$detail) {
                $detail['created_at'] = $this->repository()->bound($now);
            }
            unset($detail);
        }
        $global['created_at'] = $this->repository()->bound($now);
        $this->repository()->storeHour($details, $global);

        return count($details);
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

    private function repository(): CrawlStatsServiceRepository
    {
        return $this->repository ?? app(CrawlStatsServiceRepository::class);
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
            'hour_at' => $this->repository()->bound($row['hour_at']),
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
            'updated_at' => $this->repository()->bound(now()->utc()->toImmutable()),
        ];
    }

    private function elapsedMs(int $started): float
    {
        return round((hrtime(true) - $started) / 1_000_000, 2);
    }

    private function oldestRetainedHour(): CarbonImmutable
    {
        $retentionDays = max(1, (int) config('javmeta_stats.retention_days', 365));

        return now('UTC')->subDays($retentionDays)->toImmutable();
    }
}
