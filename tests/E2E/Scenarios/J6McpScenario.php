<?php

declare(strict_types=1);

namespace Tests\E2E\Scenarios;

use Closure;
use Tests\E2E\Step;
use Tests\E2E\Steps\CallMcp;
use Tests\E2E\Steps\SeedCatalog;
use Tests\E2E\Support\CatalogSeed;

final class J6McpScenario extends FixtureScenario
{
    /** @param Closure(): list<\Tests\E2E\Support\ApiKeyCredential> $reset @param Closure(): \Tests\E2E\Support\FixtureSeed $seedFixture @param Closure(\Tests\E2E\Support\FixtureSeed): \Tests\E2E\Support\CrawlResult $crawl @param Closure(): CatalogSeed $seedCatalog */
    public function __construct(
        Closure $reset,
        Closure $seedFixture,
        Closure $crawl,
        private readonly Closure $seedCatalog,
    ) {
        parent::__construct($reset, $seedFixture, $crawl);
    }

    protected function name(): string
    {
        return 'J6 AI client uses MCP lookup, search, counts, and status tools';
    }

    /** @return list<Step> */
    protected function steps(): array
    {
        return [
            $this->resetStep(),
            new SeedCatalog($this->seedCatalog),
            ...$this->fixtureSteps(),
            new CallMcp('get_movie', ['code' => 'SSIS-001'], ['code' => 'SSIS-001']),
            new CallMcp('search', ['q' => 'E2E Performer', 'entity' => 'movie'], ['query' => 'E2E Performer']),
            new CallMcp('get_counts'),
            new CallMcp('crawl_status'),
            new CallMcp('system_status'),
        ];
    }
}
