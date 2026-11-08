<?php

declare(strict_types=1);

namespace Tests\E2E\Steps;

use Closure;
use Tests\E2E\Context;
use Tests\E2E\Step;
use Tests\E2E\Support\CrawlResult;
use Tests\E2E\Support\StepFailure;

final class RunCrawlOnce implements Step
{
    /** @param Closure(\Tests\E2E\Support\FixtureSeed): CrawlResult $crawl */
    public function __construct(private readonly Closure $crawl) {}

    public function run(Context $context): Context
    {
        if ($context->fixture === null) {
            throw new StepFailure('No fixture source was seeded for the crawl.', $context);
        }

        return $context->withCrawl(($this->crawl)($context->fixture));
    }
}
