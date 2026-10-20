<?php

declare(strict_types=1);

namespace Tests\Feature\Crawl;

use App\Models\CrawlQueue;
use App\Models\Movie;
use App\Models\MovieCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RecrawlIncompleteCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_incomplete_movie_is_queued_again_after_seven_days(): void
    {
        [$movie, $row] = $this->movieWithDoneRow(tier: 1, crawledDaysAgo: 8);

        $this->artisan('crawler:recrawl-incomplete')->expectsOutputToContain('Queued 1')->assertSuccessful();

        $row->refresh();
        $this->assertSame(CrawlQueue::STATUS_PENDING, $row->status);
        $this->assertSame(0, $row->attempts);
        $this->assertNull($row->last_error);
        $this->assertNotNull($row->next_attempt_at);
        $this->assertTrue($row->is_recrawl);
    }

    public function test_a_recent_or_complete_movie_is_left_alone(): void
    {
        [, $recent] = $this->movieWithDoneRow(tier: 1, crawledDaysAgo: 3);
        [, $complete] = $this->movieWithDoneRow(tier: 3, crawledDaysAgo: 30);

        $this->artisan('crawler:recrawl-incomplete')->assertSuccessful();

        $this->assertSame(CrawlQueue::STATUS_DONE, $recent->refresh()->status);
        $this->assertSame(CrawlQueue::STATUS_DONE, $complete->refresh()->status);
    }

    public function test_the_number_of_days_and_the_tier_are_configurable(): void
    {
        config()->set('javmeta_sources.defaults.recrawl.incomplete_after_days', 2);
        config()->set('javmeta_sources.defaults.recrawl.min_tier', 2);
        [, $tierOne] = $this->movieWithDoneRow(tier: 1, crawledDaysAgo: 3);
        [, $tierTwo] = $this->movieWithDoneRow(tier: 2, crawledDaysAgo: 3);

        $this->artisan('crawler:recrawl-incomplete')->assertSuccessful();

        $this->assertSame(CrawlQueue::STATUS_PENDING, $tierOne->refresh()->status);
        $this->assertSame(CrawlQueue::STATUS_DONE, $tierTwo->refresh()->status);
    }

    public function test_one_run_queues_at_most_the_batch_limit(): void
    {
        $this->movieWithDoneRow(tier: 0, crawledDaysAgo: 9);
        $this->movieWithDoneRow(tier: 0, crawledDaysAgo: 10);

        $this->artisan('crawler:recrawl-incomplete', ['--limit' => 1])->expectsOutputToContain('Queued 1')->assertSuccessful();

        $this->assertSame(1, CrawlQueue::query()->where('status', CrawlQueue::STATUS_PENDING)->count());
    }

    public function test_a_stopped_movie_is_not_queued_again(): void
    {
        [$movie, $row] = $this->movieWithDoneRow(tier: 1, crawledDaysAgo: 8);
        $movie->forceFill([
            'recrawl_attempts' => 3,
            'recrawl_status' => Movie::RECRAWL_STATUS_COMPLETE_AS_POSSIBLE,
        ])->save();

        $this->artisan('crawler:recrawl-incomplete')->assertSuccessful();

        $this->assertSame(CrawlQueue::STATUS_DONE, $row->refresh()->status);
        $this->assertFalse($row->is_recrawl);
    }

    public function test_stopped_movies_can_be_listed_and_reset(): void
    {
        [$movie, $row] = $this->movieWithDoneRow(tier: 1, crawledDaysAgo: 8);
        $movie->forceFill([
            'display_code' => 'STP-001',
            'recrawl_attempts' => 3,
            'recrawl_status' => Movie::RECRAWL_STATUS_COMPLETE_AS_POSSIBLE,
        ])->save();

        $this->artisan('crawler:recrawl-stopped')
            ->expectsOutputToContain('STP-001')
            ->assertSuccessful();

        $this->artisan('crawler:recrawl-stopped', ['--reset' => true])
            ->expectsOutputToContain('Reset 1')
            ->assertSuccessful();

        $this->assertSame(0, $movie->refresh()->recrawl_attempts);
        $this->assertNull($movie->recrawl_status);

        $this->artisan('crawler:recrawl-incomplete')->assertSuccessful();
        $this->assertSame(CrawlQueue::STATUS_PENDING, $row->refresh()->status);
        $this->assertTrue($row->is_recrawl);
    }

    /** @return array{0: Movie, 1: CrawlQueue} */
    private function movieWithDoneRow(int $tier, int $crawledDaysAgo): array
    {
        $movie = Movie::factory()->create(['completeness_tier' => $tier]);
        $url = fake()->unique()->url();
        MovieCode::factory()->create([
            'movie_id' => $movie->id,
            'source_slug' => 'onejav',
            'source_url' => $url,
            'crawled_at' => now()->subDays($crawledDaysAgo),
        ]);
        $row = CrawlQueue::factory()->create([
            'source_slug' => 'onejav',
            'url' => $url,
            'kind' => CrawlQueue::KIND_DETAIL,
            'status' => CrawlQueue::STATUS_DONE,
            'attempts' => 1,
        ]);

        return [$movie, $row];
    }
}
