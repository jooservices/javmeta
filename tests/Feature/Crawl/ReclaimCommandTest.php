<?php

declare(strict_types=1);

namespace Tests\Feature\Crawl;

use App\Models\CrawlQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReclaimCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reclaim_returns_stale_rows_to_pending_and_moves_exhausted_rows_to_retry(): void
    {
        $pendingRow = CrawlQueue::factory()->create([
            'status' => CrawlQueue::STATUS_CLAIMED,
            'attempts' => 1,
            'max_attempts' => 3,
            'claimed_at' => now()->subHour(),
            'started_at' => now()->subHour(),
            'locked_by' => fake()->uuid(),
        ]);
        $exhaustedRow = CrawlQueue::factory()->create([
            'status' => CrawlQueue::STATUS_CLAIMED,
            'attempts' => 3,
            'max_attempts' => 3,
            'claimed_at' => now()->subHour(),
            'started_at' => now()->subHour(),
            'locked_by' => fake()->uuid(),
        ]);
        $unstartedRetryRow = CrawlQueue::factory()->create([
            'status' => CrawlQueue::STATUS_CLAIMED,
            'claimed_lane' => 'retry',
            'retry_round' => 1,
            'attempts' => 1,
            'max_attempts' => 3,
            'claimed_at' => now()->subHour(),
            'started_at' => null,
            'locked_by' => fake()->uuid(),
            'next_attempt_at' => now()->subMinute(),
        ]);

        $this->artisan('crawler:reclaim')
            ->expectsOutputToContain('Reclaimed 3 stale queue rows.')
            ->assertSuccessful();

        $pendingRow->refresh();
        $exhaustedRow->refresh();
        $unstartedRetryRow->refresh();
        $this->assertSame(CrawlQueue::STATUS_PENDING, $pendingRow->status);
        $this->assertNull($pendingRow->claimed_at);
        $this->assertNull($pendingRow->locked_by);
        $this->assertSame(CrawlQueue::STATUS_RETRY_PENDING, $exhaustedRow->status);
        $this->assertSame(1, $exhaustedRow->retry_round);
        $this->assertSame(0, $exhaustedRow->attempts);
        $this->assertNull($exhaustedRow->claimed_at);
        $this->assertNull($exhaustedRow->locked_by);
        $this->assertSame(CrawlQueue::STATUS_RETRY_PENDING, $unstartedRetryRow->status);
        $this->assertSame(1, $unstartedRetryRow->retry_round);
        $this->assertSame(1, $unstartedRetryRow->attempts);
        $this->assertNull($unstartedRetryRow->claimed_at);
        $this->assertNull($unstartedRetryRow->locked_by);
    }
}
