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
use JOOservices\CrawlerX\Dto\Entity\PerformerDto;
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

    public function test_a_performer_source_gets_a_listing_and_a_detail_verdict(): void
    {
        $client = new class extends CrawlerxClient {
            public function fetchPerformerListing(string $sourceSlug, string $url): CrawlerxFetchResult
            {
                return CrawlerxFetchResult::success(new CrawlListResultDto(
                    url: $url,
                    page: 1,
                    entityType: 'performer',
                    items: [
                        new CrawlItemResultDto(url: 'https://example.test/a/page', entityType: 'performer', nextCrawlType: 'performer_listing'),
                        new CrawlItemResultDto(url: 'https://example.test/a/1', entityType: 'performer'),
                    ],
                    pagination: new CrawlPaginationDto(currentPage: 1, lastPage: null, nextPage: null, nextUrl: null, hasNextPage: false),
                ));
            }

            public function fetchPerformerDetail(string $sourceSlug, string $url): CrawlerxFetchResult
            {
                return CrawlerxFetchResult::performer(new PerformerDto(name: 'Aoi Tsukasa'));
            }
        };

        $rows = $this->checker($client)->check('demo', ['performer_listing_urls' => ['https://example.test/a']], true, 3);

        self::assertSame(['performer_listing', 'performer_detail'], array_column($rows, 'capability'));
        self::assertSame('https://example.test/a/1', $rows[1]['url'], 'The first item that is a real performer page, not a further listing.');
        self::assertSame([SourceHealthChecker::WORKING, SourceHealthChecker::WORKING], array_column($rows, 'verdict'));
        self::assertSame([3], $this->sleeps, 'Only the second request waits.');
    }

    public function test_a_listing_without_any_detail_page_stops_after_the_listing_verdict(): void
    {
        $client = new class extends CrawlerxClient {
            public function fetchListing(string $sourceSlug, string $url): CrawlerxFetchResult
            {
                return CrawlerxFetchResult::success(new CrawlListResultDto(
                    url: $url,
                    page: 1,
                    entityType: 'movie',
                    items: [new CrawlItemResultDto(url: 'https://example.test/next', entityType: 'movie', nextCrawlType: 'listing')],
                    pagination: new CrawlPaginationDto(currentPage: 1, lastPage: null, nextPage: null, nextUrl: null, hasNextPage: false),
                ));
            }
        };

        $rows = $this->checker($client)->check('demo', ['listing_urls' => ['https://example.test/']], true, 5);

        self::assertSame(['listing'], array_column($rows, 'capability'));
    }

    public function test_a_source_that_declares_no_page_is_degraded_rather_than_silently_healthy(): void
    {
        $rows = $this->checker(new CrawlerxClient())->check('demo', [], true, 5);

        self::assertCount(1, $rows);
        self::assertSame('all', $rows[0]['capability']);
        self::assertSame(SourceHealthChecker::DEGRADED, $rows[0]['verdict']);
        self::assertSame('The source declares no page to check.', $rows[0]['reason']);
    }

    private function checker(CrawlerxClient $client): SourceHealthChecker
    {
        return new SourceHealthChecker($client, function (int $seconds): void {
            $this->sleeps[] = $seconds;
        });
    }
}
