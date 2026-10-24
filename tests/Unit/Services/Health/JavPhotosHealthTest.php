<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Health;

use App\Services\Crawler\CrawlerxClient;
use App\Services\Crawler\CrawlerxFetchResult;
use App\Services\Health\SourceHealthChecker;
use JOOservices\CrawlerX\Dto\Entity\GalleryDto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class JavPhotosHealthTest extends TestCase
{
    /** @return iterable<string, array{bool, string}> */
    public static function outcomes(): iterable
    {
        yield 'working gallery' => [true, SourceHealthChecker::WORKING];
        yield 'blocked gallery' => [false, SourceHealthChecker::BLOCKED];
    }

    #[DataProvider('outcomes')]
    public function test_configured_gallery_is_checked_instead_of_reporting_no_page(bool $ok, string $verdict): void
    {
        $client = new class ($ok) extends CrawlerxClient {
            /** @var list<string> */
            public array $urls = [];

            public function __construct(private readonly bool $ok) {}

            public function fetchGallery(string $sourceSlug, string $url): CrawlerxFetchResult
            {
                $this->urls[] = $url;

                return $this->ok
                    ? CrawlerxFetchResult::gallery(new GalleryDto(title: 'Sample gallery'))
                    : CrawlerxFetchResult::failure(CrawlerxClient::ERROR_CHALLENGE, 'Challenge');
            }
        };
        $sources = require dirname(__DIR__, 4) . '/config/javmeta_sources.php';
        $source = $sources['sites']['javphotos'] ?? $sources['sources']['javphotos'] ?? null;
        self::assertIsArray($source);
        $rows = (new SourceHealthChecker($client, static function (int $seconds): void {}))->check('javphotos', $source, true, 0);
        self::assertSame($source['gallery_urls'], $client->urls);
        self::assertSame(['gallery'], array_column($rows, 'capability'));
        self::assertSame($verdict, $rows[0]['verdict']);
        self::assertNotSame('The source declares no page to check.', $rows[0]['reason']);
    }
}
