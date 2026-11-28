<?php

declare(strict_types=1);

namespace Tests\Feature\Crawl;

use App\Models\CrawlQueue;
use App\Models\Movie;
use App\Models\MovieSource;
use App\Models\Source;
use App\Services\Crawl\SourceCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SourceCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-28 12:00:00'));
        config()->set('javmeta_queues.coverage_exhausted_after_days', 7);
    }

    public function test_the_figures_count_listing_pages_and_detail_rows_of_a_source(): void
    {
        $this->source('alpha');
        $this->rows('alpha', 'listing', ['done' => 3, 'pending' => 1, 'failed' => 1]);
        $this->rows('alpha', 'detail', ['done' => 5, 'pending' => 2, 'retry_pending' => 1, 'claimed' => 1, 'failed' => 2]);
        $this->rows('alpha', 'performer_detail', ['pending' => 9]);
        $this->newItems('alpha', hoursAgo: 30);

        $row = $this->report('alpha');

        self::assertSame(5, $row['listing_discovered']);
        self::assertSame(3, $row['listing_crawled']);
        self::assertSame(4, $row['detail_pending'], 'Pending, retry pending and claimed rows are not finished.');
        self::assertSame(5, $row['detail_done']);
        self::assertSame(30 * 3600, $row['last_new_item_age_seconds']);
    }

    public function test_a_source_with_open_work_is_not_fully_crawled(): void
    {
        $this->source('alpha');
        $this->rows('alpha', 'listing', ['done' => 4]);
        $this->rows('alpha', 'detail', ['done' => 10, 'pending' => 1]);
        $this->newItems('alpha', hoursAgo: 24 * 30);

        self::assertSame('not_fully_crawled', $this->report('alpha')['verdict']);
    }

    public function test_a_source_with_an_open_listing_page_is_not_fully_crawled(): void
    {
        $this->source('alpha');
        $this->rows('alpha', 'listing', ['done' => 4, 'retry_pending' => 1]);
        $this->rows('alpha', 'detail', ['done' => 10]);
        $this->newItems('alpha', hoursAgo: 24 * 30);

        self::assertSame('not_fully_crawled', $this->report('alpha')['verdict']);
    }

    public function test_a_source_that_still_finds_new_items_is_not_fully_crawled_even_with_nothing_pending(): void
    {
        $this->source('alpha');
        $this->rows('alpha', 'listing', ['done' => 4]);
        $this->rows('alpha', 'detail', ['done' => 10]);
        $this->newItems('alpha', hoursAgo: 24 * 6);

        self::assertSame('not_fully_crawled', $this->report('alpha')['verdict']);
    }

    public function test_a_source_with_nothing_pending_and_no_new_item_for_the_configured_days_is_exhausted(): void
    {
        $this->source('alpha');
        $this->rows('alpha', 'listing', ['done' => 4]);
        $this->rows('alpha', 'detail', ['done' => 10]);
        $this->newItems('alpha', hoursAgo: 24 * 8);

        self::assertSame('exhausted', $this->report('alpha')['verdict']);
    }

    public function test_a_movie_is_new_for_the_source_that_stored_it_first_not_for_a_later_one(): void
    {
        $this->source('first');
        $this->source('later');
        foreach (['first', 'later'] as $slug) {
            $this->rows($slug, 'listing', ['done' => 2]);
            $this->rows($slug, 'detail', ['done' => 5]);
        }
        $movie = $this->newItems('first', hoursAgo: 24 * 2);
        $this->storedBy($movie, 'later', now()->subHour());

        self::assertSame('not_fully_crawled', $this->report('first')['verdict']);
        self::assertSame(2 * 24 * 3600, $this->report('first')['last_new_item_age_seconds']);
        self::assertSame('exhausted', $this->report('later')['verdict'], 'It only found a movie another source already had.');
        self::assertNull($this->report('later')['last_new_item_age_seconds']);
    }

    public function test_the_time_of_the_first_sighting_is_exact_so_a_movie_just_inside_the_window_is_not_missed(): void
    {
        $this->source('alpha');
        $this->rows('alpha', 'listing', ['done' => 2]);
        $this->rows('alpha', 'detail', ['done' => 5]);
        $movie = Movie::factory()->create(['first_seen_at' => now()->subDays(7)->addMinutes(59)]);
        $this->storedBy($movie, 'alpha', now());

        self::assertSame('not_fully_crawled', $this->report('alpha')['verdict'], 'A movie seen 6 days 23 hours ago is inside the 7 days.');

        Movie::query()->whereKey($movie->id)->update(['first_seen_at' => now()->subDays(7)->subMinute()]);
        self::assertSame('exhausted', $this->report('alpha')['verdict']);
    }

    public function test_a_source_without_a_listing_page_has_no_verdict(): void
    {
        $this->source('alpha');
        $this->rows('alpha', 'detail', ['done' => 1]);

        self::assertSame('unknown', $this->report('alpha')['verdict']);
    }

    public function test_only_enabled_sources_are_reported_and_one_source_can_be_asked_for(): void
    {
        $this->source('alpha');
        $this->source('beta');
        $this->source('off', enabled: false);

        $all = app(SourceCoverage::class)->report();
        self::assertSame(['alpha', 'beta'], array_column($all, 'source_slug'));
        self::assertSame(['beta'], array_column(app(SourceCoverage::class)->report('beta'), 'source_slug'));
    }

    /** @return array<string, mixed> */
    private function report(string $slug): array
    {
        return app(SourceCoverage::class)->report($slug)[0];
    }

    private function source(string $slug, bool $enabled = true): void
    {
        Source::factory()->create(['slug' => $slug, 'enabled' => $enabled]);
    }

    /** @param array<string, int> $byStatus */
    private function rows(string $slug, string $kind, array $byStatus): void
    {
        foreach ($byStatus as $status => $count) {
            for ($index = 0; $index < $count; $index++) {
                CrawlQueue::query()->create([
                    'source_slug' => $slug, 'url' => 'https://example.test/' . $slug . '/' . $kind . '/' . $status . '/' . $index . '/' . CrawlQueue::query()->count(),
                    'kind' => $kind, 'status' => $status, 'attempts' => 0, 'max_attempts' => 3,
                ]);
            }
        }
    }

    /** A movie that this source stored first, seen for the first time the given number of hours ago. */
    private function newItems(string $slug, int $hoursAgo): Movie
    {
        $movie = Movie::factory()->create(['first_seen_at' => now()->subHours($hoursAgo)]);
        $this->storedBy($movie, $slug, now()->subHours($hoursAgo));

        return $movie;
    }

    private function storedBy(Movie $movie, string $slug, \DateTimeInterface $at): void
    {
        MovieSource::query()->create(['movie_id' => $movie->id, 'site_slug' => $slug, 'mongo_id' => 'm' . $movie->id . $slug, 'source_url' => 'https://example.test/' . $slug . '/' . $movie->id, 'source_code' => 'C-' . $movie->id, 'crawled_at' => $at]);
    }
}
