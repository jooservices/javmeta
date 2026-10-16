<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Models\CrawlEvent;
use App\Models\CrawlQueue;
use App\Models\Movie;
use App\Models\MovieObservation;
use App\Models\Performer;
use App\Models\PerformerSource;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every live-captured crawlerx fixture (tests/Fixtures/crawlerx, synced by
 * tools/sync-crawlerx-fixtures.php) goes through the matching javmeta job and
 * must end up stored. Breaks when a crawlerx upgrade changes what javmeta gets.
 */
final class CrawlerxFixtureContractTest extends CrawlerxContractTestCase
{
    private const FIXTURE_DIR = __DIR__ . '/../../Fixtures/crawlerx';

    /** @return array<string, array{string, string, string, string}> */
    public static function fixtures(): array
    {
        return self::crawlerxFixtures();
    }

    #[DataProvider('fixtures')]
    public function test_fixture_is_crawled_and_stored(string $slug, string $kind, string $url, string $bodyPath): void
    {
        $this->source($slug);
        $row = $this->claimedRow($slug, $url, $kind);
        $this->respondWithFixture($url, $bodyPath);

        $this->dispatchFor($row);

        self::assertSame($url, $this->requestedUrls()[0] ?? null, 'crawlerx must request the queued URL first');
        $row->refresh();
        self::assertSame(CrawlQueue::STATUS_DONE, $row->status, "row failed: {$row->last_error}");
        self::assertNull($row->last_error);

        match ($kind) {
            CrawlQueue::KIND_DETAIL => $this->assertMovieStored($slug, $url),
            CrawlQueue::KIND_PERFORMER_DETAIL => $this->assertPerformerStored($slug),
            CrawlQueue::KIND_LISTING, CrawlQueue::KIND_PERFORMER_LISTING => $this->assertChildrenQueued($slug),
            CrawlQueue::KIND_GALLERY => $this->assertDatabaseHas('crawl_events', ['source_slug' => $slug, 'kind' => 'gallery_parsed']),
        };
    }

    public function test_every_configured_source_has_a_fixture(): void
    {
        $configured = array_keys((array) config('javmeta_sources.sources'));
        $withFixtures = array_map('basename', glob(self::FIXTURE_DIR . '/*', GLOB_ONLYDIR) ?: []);

        self::assertSame([], array_values(array_diff($configured, $withFixtures)), 'sources without a crawlerx fixture');
        self::assertSame([], array_values(array_diff($withFixtures, $configured)), 'fixtures for unknown sources');
    }

    private function assertMovieStored(string $slug, string $url): void
    {
        self::assertSame(1, Movie::query()->count());
        $movie = Movie::query()->firstOrFail();
        self::assertNotSame('', (string) $movie->code_normalized);
        // movie_sources needs the Mongo archive id, so it is covered by the
        // integration suite; here the per-source observations prove provenance.
        self::assertTrue(
            MovieObservation::query()->where(['movie_id' => $movie->id, 'source_slug' => $slug, 'source_url' => $url])->exists(),
            "movie_observations missing for {$slug} {$url}",
        );
    }

    private function assertPerformerStored(string $slug): void
    {
        self::assertGreaterThanOrEqual(1, Performer::query()->count());
        self::assertTrue(PerformerSource::query()->where('site_slug', $slug)->exists(), "performer_sources row missing for {$slug}");
    }

    private function assertChildrenQueued(string $slug): void
    {
        self::assertGreaterThan(1, CrawlQueue::query()->where('source_slug', $slug)->count(), 'listing queued no child URLs');
        self::assertTrue(
            CrawlEvent::query()->where('source_slug', $slug)->where('kind', 'like', '%_parsed')->exists(),
            'listing did not record a *_parsed event',
        );
    }
}
