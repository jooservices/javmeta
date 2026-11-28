<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Operations;

use App\Repositories\Api\V2\AlertFlagRepository;
use App\Repositories\Api\V2\CrawlStatsRepository;
use App\Repositories\Api\V2\SourceOpsRepository;
use App\Services\Settings\SettingsService;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use stdClass;

/** The two Command Center pages that summarise the crawl: the overview and the performance page. */
final class OperationsOverview
{
    private const CAP_KEYS = [
        'queue.per_source_inflight', 'queue.browser_inflight', 'queue.feed_pool_limit', 'queue.depth_limit', 'queue.browser_depth_limit',
        'queue.browser_sources', 'queue.retry_after_seconds', 'alerts.queue_stuck_seconds', 'worker.browser_workers_per_queue',
        'worker.retry_workers_per_queue', 'browser.max_concurrency', 'crawl.lane_browser_percent', 'retry.per_source_inflight',
    ];

    public function __construct(
        private readonly CrawlStatsRepository $stats,
        private readonly SourceOpsRepository $sources,
        private readonly AlertFlagRepository $alerts,
        private readonly SettingsService $settings,
    ) {}

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $now = CarbonImmutable::now();
        $sources = $this->sources->counts();

        return [
            'last_24h' => $this->stats->totalsSince($now->subHours(24)),
            'hourly' => array_map(static fn(stdClass $row): array => [
                'hour_at' => Iso::stamp($row->hour_at),
                'attempts' => (int) $row->attempts,
                'ok' => (int) $row->ok,
                'blocked' => (int) $row->blocked,
                'timeout' => (int) $row->timeout,
                'errors' => (int) $row->errors,
                'movies_new' => (int) $row->movies_new,
            ], $this->stats->hourlyOverview($now->subHours(72))),
            'queue' => $this->stats->queueTotals(),
            'alerts' => array_map(AlertView::summary(...), $this->alerts->latestAlerts(20)),
            'sources' => ['enabled' => $sources['enabled'], 'total' => $sources['total'], 'circuit_open' => $sources['circuit_open']],
        ];
    }

    /** @return array<string, mixed> */
    public function performance(): array
    {
        $now = CarbonImmutable::now();
        $runs = $this->stats->runActivity($now);

        return [
            'hourly' => array_map(static fn(stdClass $row): array => [
                'hour_at' => Iso::stamp($row->hour_at),
                'attempts' => (int) $row->attempts,
                'ok' => (int) $row->ok,
                'blocked' => (int) $row->blocked,
                'timeout' => (int) $row->timeout,
                'err' => (int) $row->err,
                'skipped' => (int) $row->skipped,
                'avg_ms' => self::rounded($row->avg_ms),
                'p90' => self::rounded($row->p90),
                'queue_ready' => self::rounded($row->queue_ready),
                'pg' => self::rounded($row->pg),
            ], $this->stats->hourlyPerformance($now->subHours(336))),
            'lanes' => array_map(static fn(stdClass $row): array => ['claimed_lane' => $row->claimed_lane, 'n' => (int) $row->n], $this->stats->claimedLanes()),
            'runs' => ['last' => Iso::stamp($runs['last']), 'n10' => $runs['n10'], 'n60' => $runs['n60'], 'now' => Iso::stamp($now)],
            'caps' => $this->caps(),
        ];
    }

    /** @return array<string, mixed> */
    private function caps(): array
    {
        $caps = [];
        foreach (self::CAP_KEYS as $key) {
            try {
                $caps[$key] = $this->settings->get($key);
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return $caps;
    }

    private static function rounded(mixed $value): ?int
    {
        return $value === null ? null : (int) round((float) $value);
    }
}
