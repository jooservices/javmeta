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

    private function source(string $slug): void
    {
        Source::query()->create([
            'slug' => $slug,
            'name' => $slug,
            'base_url' => 'https://' . $slug . '.test',
            'enabled' => true,
            'priority' => 1,
            'needs_proxy' => false,
            'gap_seconds_default' => 1,
            'gap_seconds_min' => 1,
            'gap_seconds_max' => 10,
            'gap_seconds_current' => 1,
        ]);
    }

    private function row(string $slug, string $status, string $kind = CrawlQueue::KIND_DETAIL): CrawlQueue
    {
        return CrawlQueue::factory()->create([
            'source_slug' => $slug,
            'kind' => $kind,
            'status' => $status,
            'attempts' => 0,
            'max_attempts' => 3,
            'next_attempt_at' => now()->subSecond(),
            'claimed_at' => $status === CrawlQueue::STATUS_CLAIMED ? now() : null,
        ]);
    }

    public function test_a_source_at_its_inflight_limit_is_skipped_so_other_sources_get_their_turn(): void
    {
        config()->set('javmeta_queues.per_source_inflight', 2);
        $this->source('big');
        $this->source('small');
        $this->row('big', CrawlQueue::STATUS_CLAIMED);
        $this->row('big', CrawlQueue::STATUS_CLAIMED);
        $this->row('big', CrawlQueue::STATUS_PENDING);   // oldest pending row belongs to the saturated source
        $wanted = $this->row('small', CrawlQueue::STATUS_PENDING);

        $claimed = app(CrawlQueueService::class)->claimNext('worker', CrawlQueue::KIND_DETAIL);

        $this->assertInstanceOf(CrawlQueue::class, $claimed);
        $this->assertSame($wanted->id, $claimed->id);
    }

    public function test_a_source_alone_can_still_use_its_limit_and_is_released_when_a_row_finishes(): void
    {
        config()->set('javmeta_queues.per_source_inflight', 2);
        $this->source('only');
        $first = $this->row('only', CrawlQueue::STATUS_PENDING);
        $second = $this->row('only', CrawlQueue::STATUS_PENDING);
        $third = $this->row('only', CrawlQueue::STATUS_PENDING);
        $service = app(CrawlQueueService::class);

        $this->assertSame($first->id, $service->claimNext('worker', CrawlQueue::KIND_DETAIL)?->id);
        $this->assertSame($second->id, $service->claimNext('worker', CrawlQueue::KIND_DETAIL)?->id);
        $this->assertNull($service->claimNext('worker', CrawlQueue::KIND_DETAIL));

        $first->forceFill(['status' => CrawlQueue::STATUS_DONE])->save();
        $this->assertSame($third->id, $service->claimNext('worker', CrawlQueue::KIND_DETAIL)?->id);
    }

    public function test_the_limit_is_per_kind(): void
    {
        config()->set('javmeta_queues.per_source_inflight', 1);
        $this->source('mixed');
        $this->row('mixed', CrawlQueue::STATUS_CLAIMED, CrawlQueue::KIND_DETAIL);
        $listing = $this->row('mixed', CrawlQueue::STATUS_PENDING, CrawlQueue::KIND_LISTING);

        $claimed = app(CrawlQueueService::class)->claimNext('worker', CrawlQueue::KIND_LISTING);

        $this->assertSame($listing->id, $claimed?->id);
    }
}
