<?php

declare(strict_types=1);

namespace App\Services\Crawl;

final readonly class FetchMethodPreferenceDecision
{
    public function __construct(
        public bool $prefersFlaresolverr,
        public bool $probePlaywright,
    ) {}
}
