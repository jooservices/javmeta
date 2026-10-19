<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Models\CrawlQueue;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A listing (or performer listing) keeps the crawl going: the next page of every paged fixture must be queued as
 * another row of the same kind. The fixtures listed in SINGLE_PAGE are pages the site really shows in one piece.
 */
final class CrawlerxPaginationContractTest extends CrawlerxContractTestCase
{
    /** @var list<string> */
    private const SINGLE_PAGE = [
        'caribbeancom|listing.html',
        'duga|listing-ranking.html',
        'fc2|listing.html',
        'avfan_profiles|listing-cup-a.html',
        'tokyohot|listing.html',
        // The XCity index pages (all idols, a kana row) lead to the real paged lists rather than to a page 2.
        'xcity|performer-index.html',
        'xcity|performer-kana-a.html',
    ];

    /** @return array<string, array{string, string, string, string}> */
    public static function fixtures(): array
    {
        return array_filter(
            self::crawlerxFixtures(),
            static fn(array $f): bool => in_array($f[1], [CrawlQueue::KIND_LISTING, CrawlQueue::KIND_PERFORMER_LISTING], true)
                && ! in_array($f[0] . '|' . basename($f[3]), self::SINGLE_PAGE, true),
        );
    }

    #[DataProvider('fixtures')]
    public function test_the_next_page_is_queued(string $slug, string $kind, string $url, string $bodyPath): void
    {
        $this->source($slug);
        $row = $this->claimedRow($slug, $url, $kind);
        $this->respondWithFixture($url, $bodyPath);
        $this->dispatchFor($row);

        self::assertSame(CrawlQueue::STATUS_DONE, $row->refresh()->status, "row failed: {$row->last_error}");
        self::assertTrue(
            CrawlQueue::query()->where('source_slug', $slug)->where('kind', $kind)->where('url', '!=', $url)->exists(),
            "{$slug}: no next page was queued after " . basename($bodyPath),
        );
    }
}
