<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Health;

use App\Services\Crawler\CrawlerxClient;
use App\Services\Crawler\CrawlerxFetchResult;
use App\Services\Health\SourceHealthChecker;
use JOOservices\CrawlerX\Dto\CrawlItemResultDto;
use JOOservices\CrawlerX\Dto\CrawlListResultDto;
use JOOservices\CrawlerX\Dto\CrawlPaginationDto;
use JOOservices\CrawlerX\Dto\Entity\MovieDto;
use PHPUnit\Framework\TestCase;

final class SourceHealthCheckerTest extends TestCase
{
    /** @var list<int> */
    private array $sleeps = [];

    public function test_a_working_source_gives_a_listing_and_a_detail_verdict_with_a_polite_pause(): void
    {
        $client = new class extends CrawlerxClient {
            public function fetchListing(string $sourceSlug, string $url): CrawlerxFetchResult
            {
                return CrawlerxFetchResult::success(new CrawlListResultDto(
                    url: $url,
                    page: 1,
                    entityType: 'movie',
                    items: [new CrawlItemResultDto(url: 'https://example.test/m/1', entityType: 'movie')],
                    pagination: new CrawlPaginationDto(currentPage: 1, lastPage: null, nextPage: null, nextUrl: null, hasNextPage: false),
                ));
            }

            public function fetchDetail(string $sourceSlug, string $url): CrawlerxFetchResult
            {
                return CrawlerxFetchResult::movie(new MovieDto(title: 'A title', code: 'ABC-123'));
            }
        };

        $rows = $this->checker($client)->check('demo', ['movie_listing_urls' => ['https://example.test/']], true, 5);

        self::assertSame(['listing', 'detail'], array_column($rows, 'capability'));
        self::assertSame([SourceHealthChecker::WORKING, SourceHealthChecker::WORKING], array_column($rows, 'verdict'));
        self::assertSame([5], $this->sleeps);
    }

    public function test_a_blocked_listing_is_reported_with_its_error_and_nothing_else_is_fetched(): void
    {
        $client = new class extends CrawlerxClient {
            public function fetchListing(string $sourceSlug, string $url): CrawlerxFetchResult
            {
                return CrawlerxFetchResult::failure(CrawlerxClient::ERROR_CHALLENGE, 'Cloudflare challenge');
            }
        };

        $rows = $this->checker($client)->check('demo', ['movie_listing_urls' => ['https://example.test/']], true, 5);

        self::assertCount(1, $rows);
        self::assertSame(SourceHealthChecker::BLOCKED, $rows[0]['verdict']);
        self::assertSame('challenge', $rows[0]['error_code']);
        self::assertSame('Cloudflare challenge', $rows[0]['reason']);
    }

    public function test_a_parse_failure_or_a_movie_without_a_code_is_degraded(): void
    {
        $client = new class extends CrawlerxClient {
            public function fetchGallery(string $sourceSlug, string $url): CrawlerxFetchResult
            {
                return CrawlerxFetchResult::failure(CrawlerxClient::ERROR_PARSE_FAILED, 'Markup changed');
            }
        };

        $rows = $this->checker($client)->check('demo', ['gallery_urls' => ['https://example.test/g/1']], true, 5);

        self::assertSame(SourceHealthChecker::DEGRADED, $rows[0]['verdict']);
    }

    public function test_a_disabled_source_is_not_fetched(): void
    {
        $rows = $this->checker(new CrawlerxClient())->check('demo', ['movie_listing_urls' => ['https://example.test/']], false, 5);

        self::assertSame(SourceHealthChecker::DISABLED, $rows[0]['verdict']);
        self::assertSame([], $this->sleeps);
    }

    private function checker(CrawlerxClient $client): SourceHealthChecker
    {
        return new SourceHealthChecker($client, function (int $seconds): void {
            $this->sleeps[] = $seconds;
        });
    }
}
