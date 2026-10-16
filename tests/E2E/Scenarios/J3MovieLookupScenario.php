<?php

declare(strict_types=1);

namespace Tests\E2E\Scenarios;

use Closure;
use Tests\E2E\Step;
use Tests\E2E\Steps\BulkLookupMovies;
use Tests\E2E\Steps\CreateApiKey;
use Tests\E2E\Steps\LookupMovie;
use Tests\E2E\Steps\SeedCatalog;
use Tests\E2E\Steps\WaitForMovie;
use Tests\E2E\Support\CatalogSeed;

final class J3MovieLookupScenario extends FixtureScenario
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
        return 'J3 consumer looks up and bulk-loads movie codes';
    }

    /** @return list<Step> */
    protected function steps(): array
    {
        $found = [
            'SSIS-001' => 'found',
            'E2E-001' => 'found',
            'E2E-002' => 'found',
            'E2E-003' => 'found',
            'E2E-004' => 'found',
            'E2E-005' => 'found',
            'E2E-006' => 'found',
        ];

        return [
            $this->resetStep(),
            new CreateApiKey(),
            new SeedCatalog($this->seedCatalog),
            ...$this->fixtureSteps(),
            new WaitForMovie(),
            new LookupMovie('SSIS-001', 200, 'SSIS-001'),
            new LookupMovie('ssis001', 200, 'SSIS-001'),
            new LookupMovie('SSIS-1', 200, 'SSIS-001'),
            new LookupMovie(' ssis-001 ', 200, 'SSIS-001'),
            new LookupMovie('UNKNOWN-404', 404),
            new BulkLookupMovies(
                ['SSIS-001', 'E2E-001', 'E2E-002', 'E2E-003', 'E2E-004', 'E2E-005', 'E2E-006', 'UNKNOWN-001', 'UNKNOWN-002', 'UNKNOWN-003'],
                200,
                [...$found, 'UNKNOWN-001' => 'not_found', 'UNKNOWN-002' => 'not_found', 'UNKNOWN-003' => 'not_found'],
            ),
            new BulkLookupMovies(['SSIS-001', 'ssis001', 'SSIS-1'], 200, ['SSIS-001' => 'found']),
            new BulkLookupMovies(array_map(static fn(int $index): string => sprintf('MISSING-%03d', $index), range(1, 101)), 413),
        ];
    }
}
