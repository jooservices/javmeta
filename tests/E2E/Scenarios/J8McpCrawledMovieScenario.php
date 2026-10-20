<?php

declare(strict_types=1);

namespace Tests\E2E\Scenarios;

use Tests\E2E\Step;
use Tests\E2E\Steps\CallMcp;
use Tests\E2E\Steps\McpFindCrawledMovie;

final class J8McpCrawledMovieScenario extends FixtureScenario
{
    protected function name(): string
    {
        return 'J8 an AI client finds the crawled movie through MCP';
    }

    /** @return list<Step> */
    protected function steps(): array
    {
        return [
            $this->resetStep(),
            ...$this->fixtureSteps(),
            new McpFindCrawledMovie('get_movie'),
            new McpFindCrawledMovie('lookup_movies'),
            new CallMcp('get_counts', [], ['movies' => 1]),
        ];
    }
}
