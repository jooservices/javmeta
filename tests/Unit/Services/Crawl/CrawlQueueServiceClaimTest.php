<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawl;

use App\Models\CrawlQueue;
use App\Models\Source;
use App\Services\Crawl\CrawlQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_pacing_waiting_counts_only_claims_that_have_not_started(): void
    {
        $this->source('waiting');
        $this->row('waiting', CrawlQueue::STATUS_CLAIMED)->forceFill(['started_at' => null])->save();
        $this->row('waiting', CrawlQueue::STATUS_CLAIMED)->forceFill(['started_at' => now()])->save();

        $this->assertSame(['waiting' => 1], app(CrawlQueueService::class)->claimedWaitingBySource());
    }

    public function test_pacing_activity_uses_fetch_start_time_for_the_rolling_horizon(): void
    {
        $this->source('activity');
        $this->row('activity', CrawlQueue::STATUS_CLAIMED)->forceFill(['started_at' => null])->save();
        $this->row('activity', CrawlQueue::STATUS_CLAIMED)->forceFill(['started_at' => now()->subSeconds(5)])->save();
        DB::table('crawl_fetch_attempts')->insert([
            [
                'created_at' => now(),
                'source_slug' => 'activity',
                'crawl_queue_id' => null,
                'url_hash' => hash('sha256', 'recent-request'),
                'method' => 'http',
                'started_at' => now()->subSeconds(10),
                'duration_ms' => 1,
                'outcome' => 'ok',
            ],
            [
                'created_at' => now(),
                'source_slug' => 'activity',
                'crawl_queue_id' => null,
                'url_hash' => hash('sha256', 'expired-request'),
                'method' => 'http',
                'started_at' => now()->subSeconds(61),
                'duration_ms' => 1,
                'outcome' => 'ok',
            ],
        ]);

        $activity = app(CrawlQueueService::class)->pacingActivityBySource(now()->subSeconds(60));

        $this->assertSame(1, $activity['activity']['waiting']);
        $this->assertSame(2, $activity['activity']['started']);
        $this->assertSame(now()->subSeconds(5)->timestamp, $activity['activity']['latest_started_at']?->timestamp);
    }

    public function test_pacing_activity_counts_an_active_queue_row_once_when_its_attempt_is_recorded(): void
    {
        $this->source('deduplicated');
        $row = $this->row('deduplicated', CrawlQueue::STATUS_CLAIMED)
            ->forceFill(['started_at' => now()->subSeconds(5)]);
        $row->save();
        DB::table('crawl_fetch_attempts')->insert([
            'created_at' => now(),
            'source_slug' => 'deduplicated',
            'crawl_queue_id' => $row->id,
            'url_hash' => hash('sha256', 'active-request'),
            'method' => 'http',
            'started_at' => now()->subSeconds(5),
            'duration_ms' => 1,
            'outcome' => 'ok',
        ]);

        $activity = app(CrawlQueueService::class)->pacingActivityBySource(now()->subSeconds(60));

        $this->assertSame(1, $activity['deduplicated']['started']);
    }

    public function test_pacing_activity_counts_retries_for_an_active_queue_row_without_double_counting_its_current_attempt(): void
    {
        $this->source('retried');
        $row = $this->row('retried', CrawlQueue::STATUS_CLAIMED)
            ->forceFill(['started_at' => now()->subSeconds(5)]);
        $row->save();
        DB::table('crawl_fetch_attempts')->insert([
            'created_at' => now(),
            'source_slug' => 'retried',
            'crawl_queue_id' => $row->id,
            'url_hash' => hash('sha256', 'previous-request'),
            'method' => 'http',
            'started_at' => now()->subSeconds(20),
            'duration_ms' => 1,
            'outcome' => 'error',
        ]);

        $service = app(CrawlQueueService::class);
        $since = now()->subSeconds(60);
        $this->assertSame(2, $service->pacingActivityBySource($since)['retried']['started']);

        DB::table('crawl_fetch_attempts')->insert([
            'created_at' => now(),
            'source_slug' => 'retried',
            'crawl_queue_id' => $row->id,
            'url_hash' => hash('sha256', 'current-request'),
            'method' => 'http',
            'started_at' => now()->subSeconds(4),
            'duration_ms' => 1,
            'outcome' => 'ok',
        ]);

        $this->assertSame(2, $service->pacingActivityBySource($since)['retried']['started']);
    }

    public function test_pacing_activity_counts_each_completed_request_and_ignores_skipped_attempts(): void
    {
        $this->source('completed');
        $row = $this->row('completed', CrawlQueue::STATUS_DONE);
        DB::table('crawl_fetch_attempts')->insert([
            [
                'created_at' => now(),
                'source_slug' => 'completed',
                'crawl_queue_id' => $row->id,
                'url_hash' => hash('sha256', 'completed-request-one'),
                'method' => 'http',
                'started_at' => now()->subSeconds(10),
                'duration_ms' => 1,
                'outcome' => 'error',
            ],
            [
                'created_at' => now(),
                'source_slug' => 'completed',
                'crawl_queue_id' => $row->id,
                'url_hash' => hash('sha256', 'completed-request-two'),
                'method' => 'http',
                'started_at' => now()->subSeconds(5),
                'duration_ms' => 1,
                'outcome' => 'ok',
            ],
            [
                'created_at' => now(),
                'source_slug' => 'completed',
                'crawl_queue_id' => $row->id,
                'url_hash' => hash('sha256', 'completed-request-skipped'),
                'method' => 'http',
                'started_at' => now()->subSeconds(2),
                'duration_ms' => 1,
                'outcome' => 'skipped',
            ],
        ]);

        $activity = app(CrawlQueueService::class)->pacingActivityBySource(now()->subSeconds(60));

        $this->assertSame(2, $activity['completed']['started']);
        $this->assertSame(now()->subSeconds(5)->timestamp, $activity['completed']['latest_started_at']?->timestamp);
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

    public function test_the_limit_is_shared_across_kinds_for_a_source(): void
    {
        config()->set('javmeta_queues.per_source_inflight', 1);
        $this->source('mixed');
        $this->row('mixed', CrawlQueue::STATUS_CLAIMED, CrawlQueue::KIND_DETAIL);
        $listing = $this->row('mixed', CrawlQueue::STATUS_PENDING, CrawlQueue::KIND_LISTING);

        $claimed = app(CrawlQueueService::class)->claimNext('worker', CrawlQueue::KIND_LISTING);

        $this->assertNull($claimed);
        $this->assertSame(CrawlQueue::STATUS_PENDING, $listing->refresh()->status);
    }
}
