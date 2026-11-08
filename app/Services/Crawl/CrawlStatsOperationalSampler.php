<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Repositories\CrawlStatsServiceRepository;
use Carbon\CarbonImmutable;

final class CrawlStatsOperationalSampler
{
    public function __construct(private readonly ?CrawlStatsServiceRepository $repository = null) {}

    public function sample(): void
    {
        ($this->repository ?? app(CrawlStatsServiceRepository::class))->sampleOperationalStats(
            CarbonImmutable::instance(now()->utc()),
            (bool) config('javmeta_stats.enabled', true),
            max(1, (int) config('javmeta_queues.browser_inflight', 8)),
        );
    }
}
