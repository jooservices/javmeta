<?php

declare(strict_types=1);

namespace Tests\E2E\Scenarios;

use Closure;
use Tests\E2E\Step;
use Tests\E2E\Steps\CreateApiKey;
use Tests\E2E\Steps\SearchMovies;
use Tests\E2E\Steps\SeedCatalog;
use Tests\E2E\Support\CatalogSeed;

final class J4MovieSearchScenario extends FixtureScenario
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
        return 'J4 consumer searches movies and walks stable pages';
    }

    /** @return list<Step> */
    protected function steps(): array
    {
        $paginationCodes = ['E2E-001', 'E2E-002', 'E2E-003', 'E2E-004', 'SSIS-001', 'E2E-005', 'E2E-006', 'E2E-007'];

        return [
            $this->resetStep(),
            new CreateApiKey(),
            new SeedCatalog($this->seedCatalog),
            ...$this->fixtureSteps(),
            new SearchMovies(['q' => 'E2E Performer'], ['SSIS-001'], 200),
            new SearchMovies(['q' => '出演者'], ['SSIS-001'], 200),
            new SearchMovies(['q' => 'E2E Alias'], ['SSIS-001'], 200),
            new SearchMovies([
                'genre' => 'drama',
                'released_from' => '2020-01-01',
                'released_to' => '2026-12-31',
                'runtime_min' => 60,
                'runtime_max' => 120,
                'censored' => 1,
            ], ['E2E-002', 'SSIS-001'], 200, true),
            new SearchMovies(['q' => 'E2E Performer', 'sort' => 'release_date', 'per_page' => 3], $paginationCodes, 200, true, true),
            new SearchMovies(['q' => ''], [], 400),
            new SearchMovies(['q' => '!!!'], [], 400),
            new SearchMovies(['released_from' => '2022-01-01', 'released_to' => '2021-01-01'], [], 400),
        ];
    }
}
