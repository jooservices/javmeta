<?php

declare(strict_types=1);

namespace Tests\Feature\Crawl;

use App\Jobs\FetchDetailJob;
use App\Models\CrawlEvent;
use App\Models\CrawlQueue;
use App\Models\Source;
use App\Services\Crawl\CrawlQueueService;
use App\Services\Crawl\SourceActivityRecorder;
use App\Services\Crawl\SourceThrottle;
use App\Services\Crawler\CrawlerxClient;
use App\Services\Crawler\CrawlerxFetchResult;
use App\Services\Normalize\SourceNormalizerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

final class QueueAttemptLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_claim_left_in_the_buffer_past_timeout_is_reclaimed_without_counting_an_attempt(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');

        try {
            $source = Source::factory()->create();
            $row = CrawlQueue::factory()->create([
                'source_slug' => $source->slug,
                'status' => CrawlQueue::STATUS_PENDING,
                'attempts' => 2,
                'next_attempt_at' => null,
            ]);
            $queue = app(CrawlQueueService::class);
            $claim = $queue->claimNext('old-feed');

            self::assertSame($row->id, $claim?->id);
            self::assertSame(2, $claim?->attempts);
            self::assertNull($claim?->started_at);

            Carbon::setTestNow(now()->addSeconds(901));

            self::assertSame(1, $queue->reclaimStaleClaimed(900));
            $row->refresh();
            self::assertSame(CrawlQueue::STATUS_PENDING, $row->status);
            self::assertSame(2, $row->attempts);
            self::assertNull($row->started_at);
            self::assertNull($row->locked_by);
            $this->assertDatabaseHas('crawl_events', [
                'source_slug' => $source->slug,
                'kind' => CrawlEvent::KIND_QUEUE_RECLAIMED_WITHOUT_START,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_a_started_job_that_dies_keeps_its_attempt_and_old_claim_token_cannot_restart_it(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');

        try {
            $source = Source::factory()->create();
            $row = CrawlQueue::factory()->create([
                'source_slug' => $source->slug,
                'kind' => CrawlQueue::KIND_DETAIL,
                'status' => CrawlQueue::STATUS_PENDING,
                'attempts' => 0,
                'next_attempt_at' => null,
            ]);
            $queue = app(CrawlQueueService::class);
            $claim = $queue->claimNext('old-feed');
            self::assertSame($row->id, $claim?->id);

            $job = new FetchDetailJob($row->id, null, 'old-feed');
            $client = new class extends CrawlerxClient {
                public function fetchDetail(string $sourceSlug, string $url): CrawlerxFetchResult
                {
                    throw new RuntimeException('Worker died.');
                }
            };

            try {
                $job->handle(
                    $client,
                    app(SourceNormalizerRegistry::class),
                    app(SourceActivityRecorder::class),
                    app(SourceThrottle::class),
                );
                self::fail('The worker failure was not raised.');
            } catch (RuntimeException $exception) {
                self::assertSame('Worker died.', $exception->getMessage());
            }

            $row->refresh();
            self::assertSame(1, $row->attempts);
            self::assertNotNull($row->started_at);

            Carbon::setTestNow(now()->addSeconds(901));
            self::assertSame(1, $queue->reclaimStaleClaimed(900));
            self::assertSame(CrawlQueue::STATUS_PENDING, $row->refresh()->status);
            self::assertSame(1, $row->attempts);
            $this->assertDatabaseMissing('crawl_events', [
                'source_slug' => $source->slug,
                'kind' => CrawlEvent::KIND_QUEUE_RECLAIMED_WITHOUT_START,
            ]);

            $newClaim = $queue->claimNext('new-feed');
            self::assertSame($row->id, $newClaim?->id);
            self::assertSame(1, $newClaim?->attempts);
            self::assertNull($queue->startClaimedRow($row->id, CrawlQueue::KIND_DETAIL, 'old-feed'));
            self::assertSame(1, $row->refresh()->attempts);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_request_start_gate_runs_after_source_validation_and_before_the_fetch(): void
    {
        $source = Source::factory()->create([
            'gap_seconds_default' => 600,
            'gap_seconds_min' => 1,
            'gap_seconds_max' => 600,
            'gap_seconds_current' => 600,
        ]);
        $row = CrawlQueue::factory()->create([
            'source_slug' => $source->slug,
            'kind' => CrawlQueue::KIND_DETAIL,
            'status' => CrawlQueue::STATUS_CLAIMED,
            'locked_by' => 'feed',
            'started_at' => null,
            'attempts' => 0,
        ]);
        config(['cache.default' => 'array']);
        Cache::store('array')->flush();
        $client = new class extends CrawlerxClient {
            public function fetchDetail(string $sourceSlug, string $url): CrawlerxFetchResult
            {
                throw new RuntimeException('Stop after request-start ordering check.');
            }
        };

        try {
            (new FetchDetailJob($row->id, null, 'feed'))->handle(
                $client,
                app(SourceNormalizerRegistry::class),
                app(SourceActivityRecorder::class),
                app(SourceThrottle::class),
            );
            self::fail('The expected fetch sentinel was not raised.');
        } catch (RuntimeException $exception) {
            self::assertSame('Stop after request-start ordering check.', $exception->getMessage());
        }

        self::assertTrue(Cache::store('array')->has('javmeta:crawl:source-last-start:' . $source->slug));
        self::assertNotNull($row->refresh()->started_at);
    }

    public function test_long_throttle_wait_is_rescheduled_without_counting_a_crawl_attempt(): void
    {
        $source = Source::factory()->create([
            'gap_seconds_default' => 600,
            'gap_seconds_min' => 1,
            'gap_seconds_max' => 600,
            'gap_seconds_current' => 600,
        ]);
        $row = CrawlQueue::factory()->create([
            'source_slug' => $source->slug,
            'kind' => CrawlQueue::KIND_DETAIL,
            'status' => CrawlQueue::STATUS_CLAIMED,
            'locked_by' => 'feed',
            'started_at' => null,
            'attempts' => 0,
        ]);
        config(['cache.default' => 'array']);
        Cache::store('array')->flush();
        $nowMicroseconds = (int) floor(microtime(true) * 1_000_000);
        Cache::store('array')->put(
            'javmeta:crawl:source-last-start:' . $source->slug,
            $nowMicroseconds - 420_000_000,
            1200,
        );
        $client = new class extends CrawlerxClient {
            public function fetchDetail(string $sourceSlug, string $url): CrawlerxFetchResult
            {
                throw new RuntimeException('A delayed request must not reach the client.');
            }
        };
        Queue::fake();

        (new FetchDetailJob($row->id, 'trace', 'feed'))
            ->onConnection('redis')
            ->onQueue('browser_detail')
            ->handle(
                $client,
                app(SourceNormalizerRegistry::class),
                app(SourceActivityRecorder::class),
                app(SourceThrottle::class),
            );

        Queue::assertPushed(FetchDetailJob::class, static fn(FetchDetailJob $job): bool => $job->crawlQueueId === $row->id
            && $job->traceparent === 'trace'
            && $job->claimToken === 'feed'
            && $job->connection === 'redis'
            && $job->queue === 'browser_detail'
            && (int) $job->delay === 180);
        self::assertNull($row->refresh()->started_at);
        self::assertSame(0, $row->attempts);
        self::assertSame(CrawlQueue::STATUS_CLAIMED, $row->status);
    }

    public function test_unknown_source_claim_follows_the_existing_failure_path_without_throttling(): void
    {
        $row = CrawlQueue::factory()->create([
            'source_slug' => 'removed-source',
            'kind' => CrawlQueue::KIND_DETAIL,
            'status' => CrawlQueue::STATUS_CLAIMED,
            'locked_by' => 'feed',
            'started_at' => null,
            'attempts' => 0,
        ]);
        config(['cache.default' => 'array']);
        Cache::store('array')->flush();
        $client = new class extends CrawlerxClient {
            public function fetchDetail(string $sourceSlug, string $url): CrawlerxFetchResult
            {
                throw new RuntimeException('Unknown sources must not be fetched.');
            }
        };

        (new FetchDetailJob($row->id, null, 'feed'))->handle(
            $client,
            app(SourceNormalizerRegistry::class),
            app(SourceActivityRecorder::class),
            app(SourceThrottle::class),
        );

        self::assertSame('unknown_source', $row->refresh()->error_code);
        self::assertFalse(Cache::store('array')->has('javmeta:crawl:source-last-start:removed-source'));
    }
}
