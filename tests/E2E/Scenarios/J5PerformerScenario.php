<?php

declare(strict_types=1);

namespace Tests\E2E\Scenarios;

use Closure;
use Tests\E2E\Step;
use Tests\E2E\Steps\CreateApiKey;
use Tests\E2E\Steps\GetPerformer;
use Tests\E2E\Steps\SeedCatalog;
use Tests\E2E\Support\CatalogSeed;

final class J5PerformerScenario extends FixtureScenario
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
        return 'J5 consumer opens a performer profile';
    }

    /** @return list<Step> */
    protected function steps(): array
    {
        return [
            $this->resetStep(),
            new CreateApiKey(),
            new SeedCatalog($this->seedCatalog),
            ...$this->fixtureSteps(),
            new GetPerformer(),
        ];
    }
}
