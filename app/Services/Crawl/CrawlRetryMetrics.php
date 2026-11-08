<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Repositories\SourceRepository;

final class CrawlRetryMetrics
{
    public function __construct(private readonly ?SourceRepository $repository = null) {}

    public function increment(string $source, int $round, string $outcome, string $egress): void
    {
        ($this->repository ?? app(SourceRepository::class))
            ->incrementRetryMetric($source, $round, $outcome, $egress);
    }
}
