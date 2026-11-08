<?php

declare(strict_types=1);

namespace Tests\Feature\Crawl;

use App\Models\CrawlQueue;
use App\Services\Crawler\CrawlerxClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use JOOservices\Client\Testing\TestResponse;
use JOOservices\CrawlerX\CrawlerXFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RunsCrawlerxFixtures;
use Tests\TestCase;

/**
 * Characterises the javmeta adapter over the real jooservices/crawlerx stack.
 * Only the HTTP transport is faked (no network): fixtures are live captures.
 */
final class CrawlerxClientTest extends TestCase
{
    use RefreshDatabase;
    use RunsCrawlerxFixtures;

    private const FIXTURES = __DIR__ . '/../../Fixtures/crawlerx';

    private const LIBRARY = 'javlibrary';

    private const DETAIL_URL = 'https://www.javlibrary.com/en/javme3rasy.html';

    private const LISTING_URL = 'https://www.javlibrary.com/en/vl_newrelease.php';

    private const PERFORMER_DETAIL_URL = 'https://www.javlibrary.com/en/vl_star.php?s=ayubu';

    private const PERFORMER_LISTING_URL = 'https://www.javlibrary.com/en/star_list.php?prefix=A';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        $this->fakeCrawlerxHttp();
        $this->serveLibrary();
    }

    protected function tearDown(): void
    {
        $this->stopFakingCrawlerxHttp();
        CrawlerXFactory::reset();

        parent::tearDown();
    }

    public function test_fetch_listing_returns_the_parsed_list_with_fetch_attempts(): void
    {
        $result = (new CrawlerxClient())->fetchListing(self::LIBRARY, self::LISTING_URL);

        self::assertTrue($result->ok, (string) $result->errorMessage);
        self::assertNotNull($result->list);
        self::assertNull($result->movie);
        self::assertNull($result->errorCode);
        self::assertNotSame([], $result->attempts);
        self::assertSame(self::LISTING_URL, $this->requestedUrls()[0]);
    }

    public function test_fetch_detail_returns_a_movie(): void
    {
        $result = (new CrawlerxClient())->fetchDetail(self::LIBRARY, self::DETAIL_URL);

        self::assertTrue($result->ok, (string) $result->errorMessage);
        self::assertNotNull($result->movie);
        self::assertNull($result->list);
    }

    public function test_fetch_performer_detail_returns_a_performer(): void
    {
        $result = (new CrawlerxClient())->fetchPerformerDetail(self::LIBRARY, self::PERFORMER_DETAIL_URL);

        self::assertTrue($result->ok, (string) $result->errorMessage);
        self::assertNotNull($result->performer);
    }

    public function test_fetch_performer_listing_returns_a_list(): void
    {
        $result = (new CrawlerxClient())->fetchPerformerListing(self::LIBRARY, self::PERFORMER_LISTING_URL);

        self::assertTrue($result->ok, (string) $result->errorMessage);
        self::assertNotNull($result->list);
    }

    public function test_fetch_gallery_returns_a_gallery(): void
    {
        $url = 'https://jav.photos/free/1pondo-hana-aoyama-sexist-mobile-pics';
        $this->respondWithFixture($url, self::FIXTURES . '/javphotos/gallery-hana-aoyama.html');

        $result = (new CrawlerxClient())->fetchGallery('javphotos', $url);

        self::assertTrue($result->ok, (string) $result->errorMessage);
        self::assertNotNull($result->gallery);
    }

    /** @return array<string, array{int, string, bool}> */
    public static function terminalStatuses(): array
    {
        return [
            'not found' => [404, CrawlerxClient::ERROR_NOT_FOUND, false],
            'gone' => [410, CrawlerxClient::ERROR_GONE, false],
            'auth required' => [401, CrawlerxClient::ERROR_AUTH_REQUIRED, false],
        ];
    }

    #[DataProvider('terminalStatuses')]
    public function test_terminal_http_statuses_become_non_retryable_failures(int $status, string $code, bool $retryable): void
    {
        $url = 'https://www.javlibrary.com/en/javmegone.html';
        $this->respondWith($url, TestResponse::make($status, [], 'nope'), TestResponse::make($status, [], 'nope'), TestResponse::make($status, [], 'nope'));

        $result = (new CrawlerxClient())->fetchDetail(self::LIBRARY, $url);

        self::assertFalse($result->ok);
        self::assertSame($code, $result->errorCode);
        self::assertSame($retryable, $result->retryable);
        self::assertNotSame('', (string) $result->errorMessage);
        self::assertNull($result->movie);
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function mismatchedTypes(): array
    {
        $exhausted = static fn(string $url): string => "All fetch methods exhausted for URL [{$url}].";

        return [
            'listing page crawled as detail' => ['fetchDetail', self::LISTING_URL, CrawlerxClient::ERROR_NETWORK, $exhausted(self::LISTING_URL)],
            'detail page crawled as listing' => ['fetchListing', self::DETAIL_URL, CrawlerxClient::ERROR_NETWORK, $exhausted(self::DETAIL_URL)],
            'detail page crawled as performer detail' => ['fetchPerformerDetail', self::DETAIL_URL, CrawlerxClient::ERROR_NETWORK, $exhausted(self::DETAIL_URL)],
            'performer page crawled as detail' => ['fetchDetail', self::PERFORMER_DETAIL_URL, CrawlerxClient::ERROR_NETWORK, $exhausted(self::PERFORMER_DETAIL_URL)],
            'gallery requested from a site without galleries' => [
                'fetchGallery',
                self::DETAIL_URL,
                CrawlerxClient::ERROR_PARSE_FAILED,
                'JOOservices\\CrawlerX\\Adapters\\JavLibrary\\JavLibraryCrawler does not implement GalleryCapable.',
            ],
        ];
    }

    #[DataProvider('mismatchedTypes')]
    public function test_a_page_that_does_not_match_the_requested_kind_is_a_failure(string $method, string $url, string $code, string $message): void
    {
        $result = (new CrawlerxClient())->{$method}(self::LIBRARY, $url);

        self::assertFalse($result->ok);
        self::assertSame($code, $result->errorCode);
        self::assertSame($message, $result->errorMessage);
        self::assertNull($result->list);
        self::assertNull($result->movie);
        self::assertNull($result->performer);
        self::assertNull($result->gallery);
    }

    /** @return array<string, array{string, string, string}> */
    public static function normalLaneRows(): array
    {
        return [
            'listing' => [CrawlQueue::KIND_LISTING, self::LISTING_URL, 'list'],
            'detail' => [CrawlQueue::KIND_DETAIL, self::DETAIL_URL, 'movie'],
            'performer listing' => [CrawlQueue::KIND_PERFORMER_LISTING, self::PERFORMER_LISTING_URL, 'list'],
            'performer detail' => [CrawlQueue::KIND_PERFORMER_DETAIL, self::PERFORMER_DETAIL_URL, 'performer'],
        ];
    }

    #[DataProvider('normalLaneRows')]
    public function test_fetch_queue_row_dispatches_by_kind_on_the_normal_lane(string $kind, string $url, string $property): void
    {
        $row = (new CrawlQueue())->forceFill(['source_slug' => self::LIBRARY, 'url' => $url, 'kind' => $kind, 'claimed_lane' => 'direct']);

        $result = (new CrawlerxClient())->fetchQueueRow($row);

        self::assertTrue($result->ok, (string) $result->errorMessage);
        self::assertNotNull($result->{$property});
    }

    public function test_fetch_queue_row_dispatches_a_gallery_row(): void
    {
        $url = 'https://jav.photos/free/1pondo-hana-aoyama-sexist-mobile-pics';
        $this->respondWithFixture($url, self::FIXTURES . '/javphotos/gallery-hana-aoyama.html');
        $row = (new CrawlQueue())->forceFill(['source_slug' => 'javphotos', 'url' => $url, 'kind' => CrawlQueue::KIND_GALLERY]);

        $result = (new CrawlerxClient())->fetchQueueRow($row);

        self::assertTrue($result->ok, (string) $result->errorMessage);
        self::assertNotNull($result->gallery);
    }

    public function test_fetch_queue_row_rejects_an_unknown_kind_on_the_normal_lane(): void
    {
        $row = (new CrawlQueue())->forceFill(['source_slug' => self::LIBRARY, 'url' => self::DETAIL_URL, 'kind' => 'bogus']);

        $result = (new CrawlerxClient())->fetchQueueRow($row);

        self::assertFalse($result->ok);
        self::assertSame(CrawlerxClient::ERROR_PARSE_FAILED, $result->errorCode);
        self::assertSame('Unsupported crawl queue kind.', $result->errorMessage);
        self::assertSame([], $this->requestedUrls());
    }

    /** @return array<string, array{string, string, string}> */
    public static function retryLaneRows(): array
    {
        return [
            'listing' => [CrawlQueue::KIND_LISTING, self::LISTING_URL, 'list'],
            'detail' => [CrawlQueue::KIND_DETAIL, self::DETAIL_URL, 'movie'],
            'performer listing' => [CrawlQueue::KIND_PERFORMER_LISTING, self::PERFORMER_LISTING_URL, 'list'],
            'performer detail' => [CrawlQueue::KIND_PERFORMER_DETAIL, self::PERFORMER_DETAIL_URL, 'performer'],
        ];
    }

    #[DataProvider('retryLaneRows')]
    public function test_the_retry_lane_uses_the_retry_profile_and_restores_the_default_chain(string $kind, string $url, string $property): void
    {
        config()->set('javmeta_queues.retry.fetch_profile', ['http_proxy_url' => '', 'flaresolverr_url' => '', 'playwright_url' => '']);
        $row = (new CrawlQueue())->forceFill(['source_slug' => self::LIBRARY, 'url' => $url, 'kind' => $kind, 'claimed_lane' => 'retry']);

        $result = (new CrawlerxClient())->fetchQueueRow($row);

        self::assertTrue($result->ok, (string) $result->errorMessage);
        self::assertNotNull($result->{$property});
    }

    public function test_the_retry_lane_defaults_an_unknown_kind_to_a_detail_crawl(): void
    {
        config()->set('javmeta_queues.retry.fetch_profile', ['http_proxy_url' => '', 'flaresolverr_url' => '', 'playwright_url' => '']);
        $row = (new CrawlQueue())->forceFill(['source_slug' => self::LIBRARY, 'url' => self::DETAIL_URL, 'kind' => 'bogus', 'claimed_lane' => 'retry']);

        $result = (new CrawlerxClient())->fetchQueueRow($row);

        self::assertTrue($result->ok, (string) $result->errorMessage);
        self::assertNotNull($result->movie);
    }

    public function test_the_retry_lane_with_an_empty_profile_falls_back_to_the_normal_fetch(): void
    {
        config()->set('javmeta_queues.retry.fetch_profile', []);
        $row = (new CrawlQueue())->forceFill(['source_slug' => self::LIBRARY, 'url' => self::DETAIL_URL, 'kind' => CrawlQueue::KIND_DETAIL, 'claimed_lane' => 'retry']);

        $result = (new CrawlerxClient())->fetchQueueRow($row);

        self::assertTrue($result->ok, (string) $result->errorMessage);
        self::assertNotNull($result->movie);
    }

    private function serveLibrary(): void
    {
        $dir = self::FIXTURES . '/javlibrary';
        $this->respondWithFixture(self::DETAIL_URL, $dir . '/detail-javme3rasy-live.html');
        $this->respondWithFixture(self::LISTING_URL, $dir . '/listing-live.html');
        $this->respondWithFixture(self::PERFORMER_DETAIL_URL, $dir . '/performer-detail-ayubu-live.html');
        $this->respondWithFixture(self::PERFORMER_LISTING_URL, $dir . '/performer-listing-live.html');
    }
}
