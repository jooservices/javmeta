<?php

declare(strict_types=1);

namespace Tests\E2E;

use Tests\E2E\Scenarios\J1HealthScenario;
use Tests\E2E\Scenarios\J2ApiKeyScenario;
use Tests\E2E\Scenarios\J3MovieLookupScenario;
use Tests\E2E\Scenarios\J4MovieSearchScenario;
use Tests\E2E\Scenarios\J5PerformerScenario;
use Tests\E2E\Scenarios\J6McpScenario;
use Tests\E2E\Support\CrawlResult;
use Tests\E2E\Support\FixtureSeed;
use Tests\E2E\Support\CatalogSeed;

final class E2EJourneysTest extends E2ETestCase
{
    public function test_journeys_j1_through_j6_over_the_http_api(): void
    {
        /** @var \Closure(): list<\Tests\E2E\Support\ApiKeyCredential> $reset */
        $reset = fn(): array => $this->resetTestState();
        $seedFixture = fn(): FixtureSeed => $this->seedFixtureSource();
        $crawl = fn(FixtureSeed $fixture): CrawlResult => $this->runCrawlOnce($fixture);
        /** @var \Closure(): CatalogSeed $seedCatalog */
        $seedCatalog = fn(): CatalogSeed => $this->seedCatalog();

        $results = [
            (new J1HealthScenario($reset, $seedFixture, $crawl))->run($this->journeyContext()),
            (new J2ApiKeyScenario($reset))->run($this->journeyContext()),
            (new J3MovieLookupScenario($reset, $seedFixture, $crawl, $seedCatalog))->run($this->journeyContext()),
            (new J4MovieSearchScenario($reset, $seedFixture, $crawl, $seedCatalog))->run($this->journeyContext()),
            (new J5PerformerScenario($reset, $seedFixture, $crawl, $seedCatalog))->run($this->journeyContext()),
            (new J6McpScenario($reset, $seedFixture, $crawl, $seedCatalog))->run($this->journeyContext()),
        ];

        self::assertCount(6, $results);
        foreach ($results as $result) {
            self::assertNotNull($result->lastResponse, 'Each completed journey should leave its final HTTP response in context.');
        }
    }
}
