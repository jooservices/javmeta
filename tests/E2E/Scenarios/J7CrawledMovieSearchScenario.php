<?php

declare(strict_types=1);

namespace Tests\E2E\Scenarios;

use Tests\E2E\Step;
use Tests\E2E\Steps\CreateApiKey;
use Tests\E2E\Steps\FindCrawledMovie;

final class J7CrawledMovieSearchScenario extends FixtureScenario
{
    protected function name(): string
    {
        return 'J7 a crawled movie is found by code and text search';
    }

    /** @return list<Step> */
    protected function steps(): array
    {
        return [
            $this->resetStep(),
            new CreateApiKey(),
            ...$this->fixtureSteps(),
            new FindCrawledMovie(FindCrawledMovie::LOOKUP),
            new FindCrawledMovie(FindCrawledMovie::CODE),
            new FindCrawledMovie(FindCrawledMovie::TEXT),
        ];
    }
}
