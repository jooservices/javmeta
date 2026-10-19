<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawl;

use App\Models\CrawlQueue;
use App\Models\Source;
use App\Services\Crawl\CrawlQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CrawlQueueServiceClaimTest extends TestCase
{
    use RefreshDatabase;

    public function test_claim_next_marks_row_claimed_without_counting_an_attempt(): void
    {
        Source::query()->create([
            'slug' => 'onejav',
            'name' => 'OneJav',
            'base_url' => 'https://onejav.com',
            'enabled' => true,
            'priority' => 1,
            'needs_proxy' => false,
            'gap_seconds_default' => 1,
            'gap_seconds_min' => 1,
            'gap_seconds_max' => 10,
            'gap_seconds_current' => 1,
        ]);

        CrawlQueue::query()->create([
            'source_slug' => 'onejav',
            'url' => 'https://onejav.com/new',
            'kind' => CrawlQueue::KIND_LISTING,
            'status' => CrawlQueue::STATUS_PENDING,
            'attempts' => 0,
            'max_attempts' => 3,
            'next_attempt_at' => now()->subSecond(),
        ]);

        $service = app(CrawlQueueService::class);
        $row = $service->claimNext('test-worker');

        $this->assertInstanceOf(CrawlQueue::class, $row);
        $this->assertSame(CrawlQueue::STATUS_CLAIMED, $row->status);
        $this->assertSame('test-worker', $row->locked_by);
        $this->assertSame(0, $row->attempts);
        $this->assertNull($row->started_at);
    }

    public function test_starting_a_claimed_row_counts_once_and_rejects_a_duplicate_start(): void
    {
        Source::query()->create([
            'slug' => 'onejav',
            'name' => 'OneJav',
            'base_url' => 'https://onejav.com',
            'enabled' => true,
            'priority' => 1,
            'needs_proxy' => false,
            'gap_seconds_default' => 1,
            'gap_seconds_min' => 1,
            'gap_seconds_max' => 10,
            'gap_seconds_current' => 1,
        ]);
        $row = CrawlQueue::factory()->create([
            'source_slug' => 'onejav',
            'kind' => CrawlQueue::KIND_LISTING,
            'status' => CrawlQueue::STATUS_CLAIMED,
            'locked_by' => 'test-worker',
            'attempts' => 2,
        ]);

        $service = app(CrawlQueueService::class);
        $started = $service->startClaimedRow($row->id, CrawlQueue::KIND_LISTING, 'test-worker');

        $this->assertSame(3, $started?->attempts);
        $this->assertNotNull($started?->started_at);
        $this->assertNull($service->startClaimedRow($row->id, CrawlQueue::KIND_LISTING, 'test-worker'));
        $this->assertSame(3, $row->refresh()->attempts);
    }
}
