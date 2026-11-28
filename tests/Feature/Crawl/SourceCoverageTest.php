<?php

declare(strict_types=1);

namespace Tests\Feature\Crawl;

use App\Models\CrawlQueue;
use App\Models\Source;
use App\Services\Crawl\SourceCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_a_source_that_never_stored_a_new_item_is_exhausted_only_when_the_history_is_long_enough(): void
    {
        $this->source('alpha');
        $this->source('beta');
        $this->rows('alpha', 'listing', ['done' => 4]);
        $this->rows('alpha', 'detail', ['done' => 10]);
        $this->rows('beta', 'listing', ['done' => 4]);
        $this->rows('beta', 'detail', ['done' => 10]);
        $this->newItems('beta', hoursAgo: 24 * 20);

        // The hourly statistics start 20 days ago (beta's row), so 7 days without a new item of alpha is proof.
        self::assertSame('exhausted', $this->report('alpha')['verdict']);
        self::assertNull($this->report('alpha')['last_new_item_age_seconds']);

        DB::table('crawl_stats_hourly')->delete();
        $this->newItems('beta', hoursAgo: 24 * 3);
        self::assertSame('unknown', $this->report('alpha')['verdict'], 'Only 3 days of statistics cannot prove 7 days without a new item.');
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

    private function newItems(string $slug, int $hoursAgo): void
    {
        DB::table('crawl_stats_hourly')->insert([
            'hour_at' => now()->startOfHour()->subHours($hoursAgo), 'source_slug' => $slug, 'lane' => 'direct', 'method' => 'http', 'attempts' => 1, 'ok' => 1,
            'movies_new' => 3, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
