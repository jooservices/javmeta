<?php

declare(strict_types=1);

namespace Tests\E2E\Scenarios;

use Closure;
use Tests\E2E\Step;
use Tests\E2E\Steps\CreateApiKey;
use Tests\E2E\Steps\FindCrawledMovie;
use Tests\E2E\Steps\QuerySearchIndex;

final class J7CrawledMovieSearchScenario extends FixtureScenario
{
    /**
     * @param Closure(): list<\Tests\E2E\Support\ApiKeyCredential> $reset
     * @param Closure(): \Tests\E2E\Support\FixtureSeed $seedFixture
     * @param Closure(\Tests\E2E\Support\FixtureSeed): \Tests\E2E\Support\CrawlResult $crawl
     * @param Closure(string, string): list<string> $searchIndex
     */
    public function __construct(Closure $reset, Closure $seedFixture, Closure $crawl, private readonly Closure $searchIndex)
    {
        parent::__construct($reset, $seedFixture, $crawl);
    }

    protected function name(): string
    {
        return 'J7 a crawled movie is found by code, text search and semantic search';
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
            new QuerySearchIndex($this->searchIndex, QuerySearchIndex::TEXT),
            new QuerySearchIndex($this->searchIndex, QuerySearchIndex::SEMANTIC, 'a movie that matches no keyword at all'),
        ];
    }
}
