<?php

declare(strict_types=1);

namespace Tests\E2E\Scenarios;

use Closure;
use Tests\E2E\Step;
use Tests\E2E\Steps\CheckSourceHealth;
use Tests\E2E\Steps\CreateApiKey;
use Tests\E2E\Steps\FindCrawledMovie;
use Tests\E2E\Steps\RunCrawlOnce;
use Tests\E2E\Steps\RunCrawlOutage;
use Tests\E2E\Steps\SeedFixtureSource;

final class J10SourceOutageScenario extends FixtureScenario
{
    /**
     * @param Closure(): list<\Tests\E2E\Support\ApiKeyCredential> $reset
     * @param Closure(): \Tests\E2E\Support\FixtureSeed $seedFixture
     * @param Closure(\Tests\E2E\Support\FixtureSeed): \Tests\E2E\Support\CrawlResult $crawl
     * @param Closure(\Tests\E2E\Support\FixtureSeed): void $outage
     * @param Closure(\Tests\E2E\Support\FixtureSeed): \Tests\E2E\Support\CrawlResult $recover
     */
    public function __construct(
        Closure $reset,
        Closure $seedFixture,
        Closure $crawl,
        private readonly Closure $outage,
        private readonly Closure $recover,
    ) {
        parent::__construct($reset, $seedFixture, $crawl);
    }

    protected function name(): string
    {
        return 'J10 a source goes down and comes back';
    }

    /** @return list<Step> */
    protected function steps(): array
    {
        return [
            $this->resetStep(),
            new CreateApiKey(),
            new SeedFixtureSource($this->seedFixture),
            new RunCrawlOutage($this->outage),
            new CheckSourceHealth(CheckSourceHealth::DOWN),
            new RunCrawlOnce($this->recover),
            new CheckSourceHealth(CheckSourceHealth::RECOVERED),
            new FindCrawledMovie(FindCrawledMovie::LOOKUP),
        ];
    }
}
