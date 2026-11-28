<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Repositories\CrawlStatsServiceRepository;
use App\Repositories\SourceRepository;
use Carbon\CarbonImmutable;

/**
 * Per-source coverage: how many listing pages were found and crawled, how many detail rows are pending and done, and how long ago
 * the source last stored a new movie, with a verdict on whether those figures prove the source exhausted.
 *
 * The figures come from the queue and the hourly statistics; nothing new is stored.
 */
final class SourceCoverage
{
    public const EXHAUSTED = 'exhausted';

    public const NOT_FULLY_CRAWLED = 'not_fully_crawled';

    public const UNKNOWN = 'unknown';

    public function __construct(
        private readonly CrawlStatsServiceRepository $stats,
        private readonly SourceRepository $sources,
    ) {}

    /**
     * @return list<array{source_slug: string, listing_discovered: int, listing_crawled: int, detail_pending: int, detail_done: int, last_new_item_age_seconds: int|null, verdict: string}>
     */
    public function report(?string $source = null): array
    {
        $now = CarbonImmutable::now();
        $counts = $this->stats->coverageCountsBySource();
        $lastNew = $this->stats->lastNewItemBySource();
        $statsStart = $this->stats->hourlyStatsStart();
        $rows = [];
        foreach ($this->sources->enabledSlugs() as $slug) {
            if ($source !== null && $source !== $slug) {
                continue;
            }
            $figures = $counts[$slug] ?? ['listing_discovered' => 0, 'listing_crawled' => 0, 'listing_open' => 0, 'detail_pending' => 0, 'detail_done' => 0];
            $age = isset($lastNew[$slug]) ? max(0, $now->getTimestamp() - $lastNew[$slug]->getTimestamp()) : null;
            $rows[] = [
                'source_slug' => $slug,
                'listing_discovered' => $figures['listing_discovered'],
                'listing_crawled' => $figures['listing_crawled'],
                'detail_pending' => $figures['detail_pending'],
                'detail_done' => $figures['detail_done'],
                'last_new_item_age_seconds' => $age,
                'verdict' => $this->verdict($figures, $age, $statsStart, $now),
            ];
        }

        return $rows;
    }

    /**
     * Not fully crawled: something is still open, or the source still stores new movies. Exhausted: nothing is open, at least one
     * listing page was crawled, and the source stored no new movie for the configured days, which the statistics must cover.
     * Anything else cannot be proved from the figures.
     *
     * @param array{listing_discovered: int, listing_crawled: int, listing_open: int, detail_pending: int, detail_done: int} $figures
     */
    private function verdict(array $figures, ?int $ageSeconds, ?CarbonImmutable $statsStart, CarbonImmutable $now): string
    {
        $window = max(1, (int) config('javmeta_queues.coverage_exhausted_after_days', 7)) * 86400;
        if ($figures['listing_open'] > 0 || $figures['detail_pending'] > 0 || ($ageSeconds !== null && $ageSeconds < $window)) {
            return self::NOT_FULLY_CRAWLED;
        }
        if ($figures['listing_crawled'] === 0) {
            return self::UNKNOWN;
        }
        $covered = $ageSeconds !== null || ($statsStart !== null && $now->getTimestamp() - $statsStart->getTimestamp() >= $window);

        return $covered ? self::EXHAUSTED : self::UNKNOWN;
    }
}
