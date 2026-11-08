<?php

declare(strict_types=1);

namespace Tests\E2E\Scenarios;

use Tests\E2E\Step;
use Tests\E2E\Steps\Authenticate;
use Tests\E2E\Steps\CreateApiKey;
use Tests\E2E\Steps\FindCrawledMovie;
use Tests\E2E\Steps\McpFindCrawledMovie;
use Tests\E2E\Steps\RevokeApiKey;

final class J9KeyRevocationScenario extends FixtureScenario
{
    protected function name(): string
    {
        return 'J9 revoked keys lose REST and MCP access to the crawled movie';
    }

    /** @return list<Step> */
    protected function steps(): array
    {
        return [
            $this->resetStep(),
            new CreateApiKey(),
            ...$this->fixtureSteps(),
            new FindCrawledMovie(FindCrawledMovie::LOOKUP),
            new McpFindCrawledMovie('get_movie'),
            new RevokeApiKey(),
            new Authenticate(401),
            new FindCrawledMovie(FindCrawledMovie::LOOKUP, 401),
            new FindCrawledMovie(FindCrawledMovie::TEXT, 401),
            new McpFindCrawledMovie('get_movie'),
            new RevokeApiKey('mcp'),
            new Authenticate(401, 'mcp'),
            new McpFindCrawledMovie('get_movie', 401),
        ];
    }
}
