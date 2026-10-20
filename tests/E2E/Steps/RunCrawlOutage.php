<?php

declare(strict_types=1);

namespace Tests\E2E\Steps;

use Closure;
use Tests\E2E\Context;
use Tests\E2E\Step;
use Tests\E2E\Support\FixtureSeed;
use Tests\E2E\Support\StepFailure;

/** Crawls the fixture while its source is down; the callback throws when the crawl unexpectedly succeeds. */
final class RunCrawlOutage implements Step
{
    /** @param Closure(FixtureSeed): void $outage */
    public function __construct(private readonly Closure $outage) {}

    public function run(Context $context): Context
    {
        if ($context->fixture === null) {
            throw new StepFailure('No fixture source was seeded for the outage.', $context);
        }

        ($this->outage)($context->fixture);

        return $context;
    }
}
