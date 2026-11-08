<?php

declare(strict_types=1);

namespace Tests\E2E\Scenarios;

use Closure;
use Tests\E2E\Scenario;
use Tests\E2E\Steps\ResetTestState;
use Tests\E2E\Steps\RunCrawlOnce;
use Tests\E2E\Steps\SeedFixtureSource;
use Tests\E2E\Support\ApiKeyCredential;
use Tests\E2E\Support\CrawlResult;
use Tests\E2E\Support\FixtureSeed;

abstract class FixtureScenario extends Scenario
{
    /**
     * @param Closure(): list<ApiKeyCredential> $reset
     * @param Closure(): FixtureSeed $seedFixture
     * @param Closure(FixtureSeed): CrawlResult $crawl
     */
    public function __construct(
        protected readonly Closure $reset,
        protected readonly Closure $seedFixture,
        protected readonly Closure $crawl,
    ) {
        parent::__construct();
    }

    /** @return list<\Tests\E2E\Step> */
    protected function fixtureSteps(): array
    {
        return [
            new SeedFixtureSource($this->seedFixture),
            new RunCrawlOnce($this->crawl),
        ];
    }

    protected function resetStep(): ResetTestState
    {
        return new ResetTestState($this->reset);
    }
}
