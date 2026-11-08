<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

final readonly class CrawlResult
{
    public function __construct(public string $sourceSlug, public string $sourceUrl, public string $movieCode, public string $movieTitle) {}
}
