<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawl;

use App\Enums\CrawlErrorCode;
use App\Jobs\FetchDetailJob;
use App\Models\CrawlQueue;
use App\Models\Source;
use App\Services\Crawl\CrawlQueueService;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class CrawlQueueServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_source_url_is_not_enqueued_twice(): void
    {
        $source = Source::factory()->create();
        $url = fake()->url();
        $service = new CrawlQueueService();

        $first = $service->enqueue($url, $source->slug, CrawlQueue::KIND_LISTING);
        $second = $service->enqueue($url, $source->slug, CrawlQueue::KIND_LISTING);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CrawlQueue::query()->where('source_slug', $source->slug)->where('url', $url)->count());
    }

    public function test_enqueue_uses_override_gap_for_the_first_due_time(): void
    {
        Carbon::setTestNow('2026-10-19 10:00:00');
        try {
            config()->set('javmeta_queues.source_gap_overrides', [
                'paced-site' => ['min' => 10.0, 'default' => 20.0, 'max' => 30.0],
            ]);
            $source = Source::factory()->create([
                'slug' => 'paced-site',
                'gap_seconds_min' => 5,
                'gap_seconds_default' => 10,
                'gap_seconds_max' => 60,
                'gap_seconds_current' => 5.0,
            ]);

            $row = (new CrawlQueueService())->enqueue('https://paced-site.test/one', $source->slug, CrawlQueue::KIND_LISTING);

            $this->assertSame(
                now()->addSeconds(10)->format('Y-m-d H:i:s.u'),
                $row->next_attempt_at?->format('Y-m-d H:i:s.u'),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_claimed_override_reserves_a_subsecond_request_start_slot(): void
    {
        config()->set('javmeta_queues.source_gap_overrides', [
            'paced-site' => ['min' => 0.25, 'default' => 0.25, 'max' => 0.25],
        ]);
        Source::factory()->create([
            'slug' => 'paced-site',
            'gap_seconds_min' => 0.25,
            'gap_seconds_default' => 0.25,
            'gap_seconds_max' => 0.25,
            'gap_seconds_current' => 0.25,
        ]);
        $row = CrawlQueue::factory()->create([
            'source_slug' => 'paced-site',
            'kind' => CrawlQueue::KIND_LISTING,
            'status' => CrawlQueue::STATUS_CLAIMED,
            'claimed_at' => now(),
            'started_at' => null,
            'locked_by' => null,
        ]);
        $redis = Mockery::mock();
        $redis->shouldReceive('eval')->once()->withArgs(static function (string $script, int $keyCount, string ...$arguments): bool {
            return str_contains($script, "redis.call('TIME')")
                && str_contains($script, 'last + gap')
                && $keyCount === 1
                && $arguments === ['javmeta:source-gap:start:direct:paced-site', '250000', '60000', '134000000'];
        })->andReturn(0);
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($redis);

        $started = (new CrawlQueueService())->startClaimedRow($row->id, CrawlQueue::KIND_LISTING, null);

        self::assertSame(CrawlQueue::STATUS_CLAIMED, $started?->status);
        self::assertNotNull($started?->started_at);
    }

    public function test_claimed_retry_is_released_when_reserved_pacing_wait_exceeds_the_worker_budget(): void
    {
        config()->set('javmeta_queues.source_gap_overrides', [
            'paced-retry' => ['min' => 10.0, 'default' => 10.0, 'max' => 10.0],
        ]);
        $source = Source::factory()->create([
            'slug' => 'paced-retry',
            'gap_seconds_default' => 10,
            'gap_seconds_min' => 10,
            'gap_seconds_max' => 10,
            'gap_seconds_current' => 10,
        ]);
        $row = CrawlQueue::factory()->create([
            'source_slug' => $source->slug,
            'kind' => CrawlQueue::KIND_DETAIL,
            'status' => CrawlQueue::STATUS_CLAIMED,
            'claimed_lane' => 'retry',
            'claimed_at' => now(),
            'started_at' => null,
            'locked_by' => 'retry-worker',
            'attempts' => 1,
        ]);
        $redis = Mockery::mock();
        $redis->shouldReceive('eval')->once()->withArgs(static function (string $script, int $keyCount, string ...$arguments): bool {
            $limitCheck = strpos($script, 'if wait > maximum_wait then');
            $slotWrite = strpos($script, "redis.call('SET'");

            return $keyCount === 1
                && $arguments[0] === 'javmeta:source-gap:start:retry:paced-retry'
                && $arguments[3] === '134000000'
                && is_int($limitCheck)
                && is_int($slotWrite)
                && $limitCheck < $slotWrite;
        })->andReturn(-1);
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($redis);

        $started = (new CrawlQueueService())->startClaimedRow($row->id, CrawlQueue::KIND_DETAIL, 'retry-worker');

        self::assertNull($started);
        self::assertSame(CrawlQueue::STATUS_RETRY_PENDING, $row->refresh()->status);
        self::assertSame(1, $row->attempts);
        self::assertNull($row->claimed_at);
        self::assertNull($row->started_at);
        self::assertNull($row->locked_by);
    }

    public function test_a_blocked_crawl_uses_backoff_then_enters_the_separate_retry_lane(): void
    {
        Carbon::setTestNow('2026-10-19 10:00:00');
        $service = new CrawlQueueService();
        $row = CrawlQueue::factory()->create(['status' => CrawlQueue::STATUS_CLAIMED, 'attempts' => 1, 'max_attempts' => 3]);

        foreach ([1 => 60, 2 => 120, 3 => 180] as $attempt => $delay) {
            $row->forceFill(['status' => CrawlQueue::STATUS_CLAIMED, 'attempts' => $attempt])->save();
            $service->markFailed($row, CrawlErrorCode::Blocked, 'Blocked.', true);

            $row->refresh();
            $this->assertSame($attempt < 3 ? CrawlQueue::STATUS_PENDING : CrawlQueue::STATUS_RETRY_PENDING, $row->status, "attempt {$attempt}");
            $expectedDelay = $attempt < 3 ? $delay : 1800;
            $this->assertSame(now()->addSeconds($expectedDelay)->getTimestamp(), $row->next_attempt_at?->getTimestamp(), "attempt {$attempt}");
        }
        $this->assertSame(1, $row->retry_round);
        $this->assertSame(0, $row->attempts);
        Carbon::setTestNow();
    }

    public function test_a_challenge_follows_the_same_delays_and_the_delays_are_configurable(): void
    {
        config()->set('javmeta_sources.defaults.queue.block_delays_seconds', [10, 20]);
        $service = new CrawlQueueService();
        $row = CrawlQueue::factory()->create(['status' => CrawlQueue::STATUS_CLAIMED, 'attempts' => 2, 'max_attempts' => 3]);

        $service->markFailed($row, CrawlErrorCode::Challenge, 'Challenge.', false);
        $this->assertSame(CrawlQueue::STATUS_PENDING, $row->refresh()->status);

        $row->forceFill(['status' => CrawlQueue::STATUS_CLAIMED, 'attempts' => 3])->save();
        $service->markFailed($row, CrawlErrorCode::Challenge, 'Challenge.', true);
        $this->assertSame(CrawlQueue::STATUS_RETRY_PENDING, $row->refresh()->status);
        $this->assertSame(1, $row->retry_round);
    }

    public function test_rows_of_a_disabled_source_are_not_claimed(): void
    {
        $off = Source::factory()->create(['enabled' => false]);
        $on = Source::factory()->create(['enabled' => true]);
        $skipped = CrawlQueue::factory()->create(['source_slug' => $off->slug, 'status' => CrawlQueue::STATUS_PENDING, 'next_attempt_at' => null]);
        $taken = CrawlQueue::factory()->create(['source_slug' => $on->slug, 'status' => CrawlQueue::STATUS_PENDING, 'next_attempt_at' => null]);

        $claimed = (new CrawlQueueService())->claimNext('worker-1');

        $this->assertSame($taken->id, $claimed?->id);
        $this->assertSame(CrawlQueue::STATUS_PENDING, $skipped->refresh()->status);
    }

    public function test_open_circuit_excludes_new_claims_until_automatic_resume(): void
    {
        $source = Source::factory()->create([
            'circuit_state' => 'open',
            'circuit_opened_at' => now(),
            'circuit_open_count' => 1,
            'circuit_cooldown_seconds' => 60,
            'consecutive_failures' => 3,
        ]);
        $row = CrawlQueue::factory()->create(['source_slug' => $source->slug, 'status' => CrawlQueue::STATUS_PENDING]);
        $service = new CrawlQueueService();

        $this->assertNull($service->claimNext('worker-paused'));
        $this->assertSame(CrawlQueue::STATUS_PENDING, $row->refresh()->status);

        $this->travel(61)->seconds();
        $this->assertSame($row->id, $service->claimNext('worker-resumed')?->id);
        $this->assertSame('closed', $source->refresh()->circuit_state);
    }

    public function test_already_claimed_job_is_returned_to_pending_if_circuit_opens_before_start(): void
    {
        $source = Source::factory()->create();
        $row = CrawlQueue::factory()->create([
            'source_slug' => $source->slug,
            'status' => CrawlQueue::STATUS_CLAIMED,
            'claimed_at' => now(),
            'locked_by' => 'worker-paused',
            'attempts' => 0,
        ]);
        $source->forceFill([
            'circuit_state' => 'open',
            'circuit_opened_at' => now(),
            'circuit_open_count' => 1,
            'circuit_cooldown_seconds' => 60,
            'consecutive_failures' => 3,
        ])->save();

        $this->assertNull((new CrawlQueueService())->startClaimedRow($row->id, $row->kind, 'worker-paused'));
        $row->refresh();
        $this->assertSame(CrawlQueue::STATUS_PENDING, $row->status);
        $this->assertSame(0, $row->attempts);
        $this->assertNull($row->locked_by);
    }

    public function test_a_site_waiting_out_a_block_delay_gets_no_new_claims_but_other_sites_do(): void
    {
        $blocked = Source::factory()->create();
        $other = Source::factory()->create();
        CrawlQueue::factory()->create([
            'source_slug' => $blocked->slug, 'status' => CrawlQueue::STATUS_PENDING,
            'error_code' => CrawlErrorCode::Blocked->value, 'next_attempt_at' => now()->addMinute(),
        ]);
        $waiting = CrawlQueue::factory()->create(['source_slug' => $blocked->slug, 'status' => CrawlQueue::STATUS_PENDING, 'next_attempt_at' => null]);
        $free = CrawlQueue::factory()->create(['source_slug' => $other->slug, 'status' => CrawlQueue::STATUS_PENDING, 'next_attempt_at' => null]);

        $claimed = (new CrawlQueueService())->claimNext('worker-1');

        $this->assertSame($free->id, $claimed?->id);
        $this->assertSame(CrawlQueue::STATUS_PENDING, $waiting->refresh()->status);
    }

    public function test_stale_claimed_rows_are_reclaimed_to_pending_when_attempts_remain(): void
    {
        $row = CrawlQueue::factory()->create([
            'status' => CrawlQueue::STATUS_CLAIMED,
            'attempts' => 1,
            'max_attempts' => 3,
            'claimed_at' => now()->subHour(),
            'started_at' => now()->subHour(),
            'locked_by' => fake()->uuid(),
        ]);

        $reclaimed = (new CrawlQueueService())->reclaimStaleClaimed(900);

        $this->assertSame(1, $reclaimed);
        $this->assertSame(CrawlQueue::STATUS_PENDING, $row->refresh()->status);
        $this->assertSame(CrawlErrorCode::WorkerLost->value, $row->error_code);
        $this->assertSame('Worker claim expired before the crawl row was completed.', $row->last_error);
        $this->assertNotNull($row->last_failed_at);
        $this->assertNull($row->claimed_at);
        $this->assertNull($row->locked_by);
    }

    public function test_stale_claimed_rows_exhausted_by_max_attempts_are_failed(): void
    {
        $row = CrawlQueue::factory()->create([
            'status' => CrawlQueue::STATUS_CLAIMED,
            'attempts' => 3,
            'max_attempts' => 3,
            'claimed_at' => now()->subHour(),
            'started_at' => now()->subHour(),
        ]);

        $reclaimed = (new CrawlQueueService())->reclaimStaleClaimed(900);

        $this->assertSame(1, $reclaimed);
        $this->assertSame(CrawlQueue::STATUS_RETRY_PENDING, $row->refresh()->status);
        $this->assertSame(CrawlErrorCode::WorkerLost->value, $row->error_code);
        $this->assertNotNull($row->last_error);
        $this->assertNotNull($row->last_failed_at);
    }

    public function test_unstarted_claims_wait_for_the_queue_backlog_window_before_reclaiming(): void
    {
        $claimTimeout = 900;
        $queueDepth = max(
            (int) config('javmeta_queues.depth_limit', 100),
            (int) config('javmeta_queues.browser_depth_limit', 100),
            (int) config('javmeta_queues.retry.depth_limit', 10),
        );
        $workerTimeout = (int) config('javmeta_queues.worker_timeout', 180);
        $queueBacklogWindow = $queueDepth * $workerTimeout;
        $waiting = CrawlQueue::factory()->create([
            'status' => CrawlQueue::STATUS_CLAIMED,
            'claimed_at' => now()->subSeconds($claimTimeout + 1),
            'started_at' => null,
        ]);
        $expired = CrawlQueue::factory()->create([
            'status' => CrawlQueue::STATUS_CLAIMED,
            'claimed_at' => now()->subSeconds($queueBacklogWindow + 1),
            'started_at' => null,
        ]);

        $reclaimed = (new CrawlQueueService())->reclaimStaleClaimed($claimTimeout);

        self::assertSame(1, $reclaimed);
        self::assertSame(CrawlQueue::STATUS_CLAIMED, $waiting->refresh()->status);
        self::assertSame(CrawlQueue::STATUS_PENDING, $expired->refresh()->status);
    }

    public function test_failed_handler_records_timeout_and_keeps_remaining_attempts(): void
    {
        Carbon::setTestNow('2026-10-16 10:00:00');

        try {
            $row = CrawlQueue::factory()->create([
                'status' => CrawlQueue::STATUS_CLAIMED,
                'attempts' => 1,
                'max_attempts' => 3,
                'started_at' => now(),
            ]);

            (new FetchDetailJob($row->id))->failed(new TimeoutExceededException('Worker timed out.'));

            $row->refresh();
            $this->assertSame(CrawlQueue::STATUS_PENDING, $row->status);
            $this->assertSame(CrawlErrorCode::Timeout->value, $row->error_code);
            $this->assertSame('Worker timed out while processing the crawl row.', $row->last_error);
            $this->assertNotNull($row->last_failed_at);
            $this->assertSame('2026-10-16 10:01:00', $row->next_attempt_at?->format('Y-m-d H:i:s'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_failed_handler_records_worker_exception_after_attempts_are_exhausted(): void
    {
        $row = CrawlQueue::factory()->create([
            'status' => CrawlQueue::STATUS_CLAIMED,
            'attempts' => 3,
            'max_attempts' => 3,
            'started_at' => now(),
        ]);

        (new FetchDetailJob($row->id))->failed(new RuntimeException('Request failed at https://user:pass@example.test/path?token=secret'));

        $row->refresh();
        $this->assertSame(CrawlQueue::STATUS_RETRY_PENDING, $row->status);
        $this->assertSame(1, $row->retry_round);
        $this->assertSame(CrawlErrorCode::WorkerFailed->value, $row->error_code);
        $this->assertStringNotContainsString('pass', $row->last_error);
        $this->assertStringNotContainsString('secret', $row->last_error);
        $this->assertNotNull($row->last_failed_at);
    }

    public function test_mark_failed_redacts_sensitive_content_and_limits_message_length(): void
    {
        $row = CrawlQueue::factory()->create([
            'status' => CrawlQueue::STATUS_CLAIMED,
            'attempts' => 1,
            'max_attempts' => 1,
        ]);

        (new CrawlQueueService())->markFailed(
            $row,
            CrawlErrorCode::Network,
            '<html><body>Bearer abc123 Basic dGVzdA== access_token=abc123 https://user:pass@example.test/path?token=secret ' . str_repeat('x', 600) . '</body></html>',
            false,
        );

        $message = $row->refresh()->last_error;
        $this->assertSame(CrawlQueue::STATUS_FAILED, $row->status);
        $this->assertLessThanOrEqual(500, mb_strlen($message));
        $this->assertStringNotContainsString('<html>', $message);
        $this->assertStringNotContainsString('abc123', $message);
        $this->assertStringNotContainsString('dGVzdA==', $message);
        $this->assertStringNotContainsString('pass', $message);
        $this->assertStringNotContainsString('?token=', $message);
        $this->assertStringNotContainsString('secret', $message);
    }

    public function test_mark_failed_uses_a_fallback_for_an_empty_message(): void
    {
        $row = CrawlQueue::factory()->create([
            'status' => CrawlQueue::STATUS_CLAIMED,
            'attempts' => 1,
            'max_attempts' => 1,
        ]);

        (new CrawlQueueService())->markFailed($row, CrawlErrorCode::Unknown, '', false);

        $this->assertSame('Crawl failed without an error message.', $row->refresh()->last_error);
    }

    public function test_retry_rounds_use_configured_delays_then_fail_permanently_with_the_last_error(): void
    {
        Carbon::setTestNow('2026-11-02 10:00:00');
        config([
            'javmeta_queues.retry.max_rounds' => 2,
            'javmeta_queues.retry.delays_seconds' => [60, 120],
            'javmeta_queues.retry.attempts_per_round' => 3,
        ]);
        $row = CrawlQueue::factory()->create([
            'status' => CrawlQueue::STATUS_CLAIMED,
            'attempts' => 3,
            'max_attempts' => 3,
            'claimed_lane' => 'direct',
        ]);
        $service = new CrawlQueueService();

        $service->markFailed($row, CrawlErrorCode::Network, 'normal lane failed', true, 240);
        self::assertSame(CrawlQueue::STATUS_RETRY_PENDING, $row->refresh()->status);
        self::assertSame(1, $row->retry_round);
        self::assertSame(0, $row->attempts);
        self::assertSame(now()->addSeconds(240)->getTimestamp(), $row->next_attempt_at?->getTimestamp());

        $row->forceFill(['status' => CrawlQueue::STATUS_CLAIMED, 'claimed_lane' => 'retry', 'attempts' => 3])->save();
        $service->markFailed($row, CrawlErrorCode::Network, 'retry round one failed', true, 300);
        self::assertSame(CrawlQueue::STATUS_RETRY_PENDING, $row->refresh()->status);
        self::assertSame(2, $row->retry_round);
        self::assertSame(0, $row->attempts);
        self::assertSame(now()->addSeconds(300)->getTimestamp(), $row->next_attempt_at?->getTimestamp());

        $row->forceFill(['status' => CrawlQueue::STATUS_CLAIMED, 'claimed_lane' => 'retry', 'attempts' => 3])->save();
        $service->markFailed($row, CrawlErrorCode::Network, 'final error retained', true);
        self::assertSame(CrawlQueue::STATUS_FAILED, $row->refresh()->status);
        self::assertSame(2, $row->retry_round);
        self::assertSame('final error retained', $row->last_error);
        $this->assertDatabaseHas('crawl_retry_metrics', [
            'source_slug' => $row->source_slug,
            'round' => 2,
            'outcome' => 'failed_permanent',
            'value' => 1,
        ]);
        Carbon::setTestNow();
    }

    public function test_retry_lane_claims_only_due_retry_rows(): void
    {
        $source = Source::factory()->create();
        $normal = CrawlQueue::factory()->create([
            'source_slug' => $source->slug,
            'kind' => CrawlQueue::KIND_DETAIL,
            'status' => CrawlQueue::STATUS_PENDING,
            'next_attempt_at' => null,
        ]);
        $retry = CrawlQueue::factory()->create([
            'source_slug' => $source->slug,
            'kind' => CrawlQueue::KIND_DETAIL,
            'status' => CrawlQueue::STATUS_RETRY_PENDING,
            'retry_round' => 1,
            'next_attempt_at' => null,
        ]);
        $service = new CrawlQueueService();

        $retryClaim = $service->claimNext('retry-worker', CrawlQueue::KIND_DETAIL, [], 'retry');
        $normalClaim = $service->claimNext('direct-worker', CrawlQueue::KIND_DETAIL, [], 'direct');

        self::assertSame($retry->id, $retryClaim?->id);
        self::assertSame('retry', $retryClaim?->claimed_lane);
        self::assertSame($normal->id, $normalClaim?->id);
        self::assertSame('direct', $normalClaim?->claimed_lane);
    }

    public function test_listing_loop_does_not_reset_a_retry_pending_seed(): void
    {
        // A frozen clock: the final timestamp assertion must not depend on the second the test crosses.
        Carbon::setTestNow('2026-10-19 10:00:00');
        $source = Source::factory()->create();
        $doneSeed = CrawlQueue::factory()->create([
            'source_slug' => $source->slug,
            'kind' => CrawlQueue::KIND_LISTING,
            'url' => 'https://example.test/listing/1',
            'status' => CrawlQueue::STATUS_DONE,
        ]);
        $retrySeed = CrawlQueue::factory()->create([
            'source_slug' => $source->slug,
            'kind' => CrawlQueue::KIND_LISTING,
            'url' => 'https://example.test/listing/2',
            'status' => CrawlQueue::STATUS_RETRY_PENDING,
            'retry_round' => 1,
            'next_attempt_at' => now()->addHour(),
        ]);

        $restarted = (new CrawlQueueService())->restartListingLoopIfFinished(
            $source->slug,
            CrawlQueue::KIND_LISTING,
            [$doneSeed->url, $retrySeed->url],
        );

        self::assertFalse($restarted);
        self::assertSame(CrawlQueue::STATUS_DONE, $doneSeed->refresh()->status);
        self::assertSame(CrawlQueue::STATUS_RETRY_PENDING, $retrySeed->refresh()->status);
        self::assertSame(1, $retrySeed->retry_round);
        self::assertSame(now()->addHour()->getTimestamp(), $retrySeed->next_attempt_at?->getTimestamp());
        Carbon::setTestNow();
    }

    public function test_requeue_does_not_overwrite_a_row_that_is_no_longer_failed(): void
    {
        $row = CrawlQueue::factory()->create([
            'status' => CrawlQueue::STATUS_FAILED,
            'error_code' => CrawlErrorCode::Network->value,
            'retryable' => true,
        ]);
        DB::table('crawl_queue')->where('id', $row->id)->update(['status' => CrawlQueue::STATUS_PENDING]);

        $requeued = (new CrawlQueueService())->requeuePermanentFailure($row);

        self::assertFalse($requeued);
        self::assertSame(CrawlQueue::STATUS_PENDING, $row->refresh()->status);
    }

    public function test_historical_import_is_sliced_and_does_not_touch_young_or_terminal_failures(): void
    {
        Carbon::setTestNow('2026-11-02 10:00:00');
        config(['javmeta_queues.retry.delays_seconds' => [60], 'javmeta_queues.retry.import_slice_size' => 1]);
        $source = Source::factory()->create();
        $old = CrawlQueue::factory()->create([
            'source_slug' => $source->slug, 'status' => CrawlQueue::STATUS_FAILED,
            'error_code' => CrawlErrorCode::Timeout->value, 'attempts' => 3,
            'last_failed_at' => now()->subSeconds(61),
        ]);
        $retryableNetwork = CrawlQueue::factory()->create([
            'source_slug' => $source->slug, 'status' => CrawlQueue::STATUS_FAILED,
            'error_code' => CrawlErrorCode::Network->value, 'retryable' => true,
            'attempts' => 3, 'last_failed_at' => now()->subSeconds(61),
        ]);
        $unknownNetwork = CrawlQueue::factory()->create([
            'source_slug' => $source->slug, 'status' => CrawlQueue::STATUS_FAILED,
            'error_code' => CrawlErrorCode::Network->value, 'retryable' => null,
            'attempts' => 3, 'last_failed_at' => now()->subSeconds(61),
        ]);
        $nonRetryableRateLimit = CrawlQueue::factory()->create([
            'source_slug' => $source->slug, 'status' => CrawlQueue::STATUS_FAILED,
            'error_code' => CrawlErrorCode::RateLimited->value, 'retryable' => false,
            'attempts' => 3, 'last_failed_at' => now()->subSeconds(61),
        ]);
        $young = CrawlQueue::factory()->create([
            'source_slug' => $source->slug, 'status' => CrawlQueue::STATUS_FAILED,
            'error_code' => CrawlErrorCode::Timeout->value, 'attempts' => 3,
            'last_failed_at' => now()->subSeconds(59),
        ]);
        $terminal = CrawlQueue::factory()->create([
            'source_slug' => $source->slug, 'status' => CrawlQueue::STATUS_FAILED,
            'error_code' => CrawlErrorCode::ParseFailed->value, 'attempts' => 1,
            'last_failed_at' => now()->subMinutes(10),
        ]);
        $service = new CrawlQueueService();

        self::assertSame(2, $service->importHistoricalFailures(true, $source->slug));
        self::assertSame(2, $service->importHistoricalFailures(false, $source->slug));
        self::assertSame(CrawlQueue::STATUS_RETRY_PENDING, $old->refresh()->status);
        self::assertSame(1, $old->retry_round);
        self::assertSame(CrawlQueue::STATUS_RETRY_PENDING, $retryableNetwork->refresh()->status);
        self::assertSame(CrawlQueue::STATUS_FAILED, $unknownNetwork->refresh()->status);
        self::assertSame(CrawlQueue::STATUS_FAILED, $nonRetryableRateLimit->refresh()->status);
        self::assertSame(CrawlQueue::STATUS_FAILED, $young->refresh()->status);
        self::assertSame(CrawlQueue::STATUS_FAILED, $terminal->refresh()->status);
        Carbon::setTestNow();
    }

    public function test_failed_status_writes_are_centralized_in_the_crawl_queue_service(): void
    {
        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getPathname() === app_path('Services/Crawl/CrawlQueueService.php')) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression(
                "/['\"]status['\"]\\s*=>\\s*CrawlQueue::STATUS_FAILED/",
                File::get($file->getPathname()),
                "Direct failed status write found in {$file->getRelativePathname()}.",
            );
        }
    }
}
