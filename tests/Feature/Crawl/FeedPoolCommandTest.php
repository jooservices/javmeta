<?php

declare(strict_types=1);

namespace Tests\Feature\Crawl;

use App\Console\Commands\Crawler\FeedPoolCommand;
use App\Jobs\FetchDetailJob;
use App\Jobs\FetchGalleryJob;
use App\Jobs\FetchListingJob;
use App\Jobs\FetchPerformerDetailJob;
use App\Jobs\FetchPerformerListingJob;
use App\Models\CrawlQueue;
use App\Models\Source;
use App\Services\Health\CrawlDispatchHeartbeat;
use App\Services\Settings\SettingsService;
use App\Services\Crawl\SourceThrottle;
use App\Services\Crawl\FeedPoolMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class FeedPoolCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_pool_command_has_legacy_dispatch_alias_and_limit_default(): void
    {
        $commands = app(Kernel::class)->all();
        $legacyAlias = $commands['crawler:feed-pool']->getAliases()[0] ?? null;

        $this->assertArrayHasKey('crawler:feed-pool', $commands);
        $this->assertIsString($legacyAlias);
        $this->assertArrayHasKey($legacyAlias, $commands);
        $this->assertInstanceOf(FeedPoolCommand::class, $commands['crawler:feed-pool']);
        $this->assertSame($commands['crawler:feed-pool'], $commands[$legacyAlias]);
        $this->assertNull($commands['crawler:feed-pool']->getDefinition()->getOption('limit')->getDefault());
        $this->assertFalse($commands['crawler:feed-pool']->getDefinition()->getOption('scheduled')->getDefault());
        $this->assertSame(60, config('javmeta_queues.feed_pool_limit'));
        $this->assertSame(16, config('javmeta_queues.per_source_inflight'));
    }

    public function test_feed_pool_claims_and_dispatches_only_up_to_the_requested_limit(): void
    {
        $first = CrawlQueue::factory()->create([
            'source_slug' => fake()->slug(2),
            'kind' => CrawlQueue::KIND_LISTING,
        ]);
        $second = CrawlQueue::factory()->create([
            'source_slug' => fake()->slug(2),
            'kind' => CrawlQueue::KIND_DETAIL,
        ]);
        Queue::fake();

        $this->artisan('crawler:feed-pool', ['--limit' => 1])->assertSuccessful();

        Queue::assertPushed(FetchListingJob::class, 1);
        Queue::assertNotPushed(FetchDetailJob::class);
        $this->assertSame(CrawlQueue::STATUS_CLAIMED, $first->refresh()->status);
        $this->assertSame(CrawlQueue::STATUS_PENDING, $second->refresh()->status);
    }

    public function test_feed_pool_records_the_time_of_a_successful_job_dispatch(): void
    {
        config(['cache.default' => 'array']);
        Cache::store('array')->flush();
        CrawlQueue::factory()->create([
            'source_slug' => fake()->slug(2),
            'kind' => CrawlQueue::KIND_LISTING,
        ]);
        Queue::fake();

        $this->artisan('crawler:feed-pool', ['--limit' => 1])->assertSuccessful();

        $this->assertSame(0, app(CrawlDispatchHeartbeat::class)->ageSeconds());
    }

    public function test_feed_pool_does_not_record_dispatch_when_the_queue_push_fails(): void
    {
        config(['cache.default' => 'array']);
        Cache::store('array')->flush();
        $sourceSlug = 'queue-push-failure';
        CrawlQueue::factory()->create([
            'source_slug' => $sourceSlug,
            'kind' => CrawlQueue::KIND_LISTING,
        ]);
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue push failed.'));
        $this->app->instance(Dispatcher::class, $dispatcher);
        Queue::fake();

        try {
            $this->artisan('crawler:feed-pool', ['--limit' => 1]);
            self::fail('The queue push failure was not raised.');
        } catch (RuntimeException $exception) {
            self::assertSame('Queue push failed.', $exception->getMessage());
        }

        $minute = intdiv(now()->timestamp, 60);
        self::assertSame(
            0,
            Cache::store('array')->get("javmeta:crawl:feed-metric:dispatched:{$sourceSlug}:{$minute}", 0),
        );
    }

    public function test_scheduled_feed_pool_reads_the_current_database_interval_without_scheduler_restart(): void
    {
        config(['cache.default' => 'array']);
        $settings = new SettingsService(new FakeConfigStore());
        $settings->set('queue.feed_interval_seconds', 15);
        Cache::store('array')->flush();
        $row = CrawlQueue::factory()->create([
            'source_slug' => fake()->slug(2),
            'kind' => CrawlQueue::KIND_LISTING,
        ]);
        Queue::fake();

        $this->artisan('crawler:feed-pool', ['--scheduled' => true])->assertSuccessful();
        $this->travel(9)->seconds();
        $settings->set('queue.feed_interval_seconds', 10);
        $this->artisan('crawler:feed-pool', ['--scheduled' => true])->expectsOutputToContain('Feed interval has not elapsed.')->assertSuccessful();
        $this->travel(1)->seconds();
        $this->artisan('crawler:feed-pool', ['--scheduled' => true])->assertSuccessful();

        Queue::assertPushed(FetchListingJob::class, 1);
        $this->assertSame(CrawlQueue::STATUS_CLAIMED, $row->refresh()->status);
    }

    public function test_feed_pool_dispatches_each_configured_crawl_kind(): void
    {
        foreach (array_keys(config('javmeta_queues.names')) as $kind) {
            CrawlQueue::factory()->create([
                'source_slug' => fake()->slug(2),
                'kind' => $kind,
            ]);
        }
        Queue::fake();

        $this->artisan('crawler:feed-pool')->assertSuccessful();

        foreach ([
            FetchListingJob::class,
            FetchDetailJob::class,
            FetchPerformerListingJob::class,
            FetchPerformerDetailJob::class,
            FetchGalleryJob::class,
        ] as $jobClass) {
            Queue::assertPushed($jobClass, 1);
        }
    }

    public function test_search_is_in_the_feed_pool_queue_list_but_is_not_claimed_as_crawl_work(): void
    {
        $row = CrawlQueue::factory()->create([
            'source_slug' => fake()->slug(2),
            'kind' => 'search',
        ]);
        Queue::fake();

        $this->assertContains('search', config('javmeta_queues.feed_pool_names'));
        $this->assertArrayNotHasKey('search', config('javmeta_queues.names'));

        $this->artisan('crawler:feed-pool')->assertSuccessful();

        $this->assertSame(CrawlQueue::STATUS_PENDING, $row->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_feed_pool_leaves_rows_pending_and_emits_pool_gated_when_queue_depth_is_full(): void
    {
        $row = CrawlQueue::factory()->create([
            'source_slug' => fake()->slug(2),
            'kind' => CrawlQueue::KIND_LISTING,
        ]);
        Queue::fake();
        config([
            'queue.default' => 'redis',
            'queue.connections.redis.driver' => 'redis',
            'queue.connections.redis.connection' => 'default',
            'javmeta_queues.depth_limit' => 2,
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.stream_logs' => 'javmeta_logs',
            'openobserve.email' => 'root@javmeta.local',
            'openobserve.password' => 'secret',
            'openobserve.timeout' => 1,
        ]);
        Http::fake(['openobserve.test/*' => Http::response(['code' => 200], 200)]);
        $redis = Mockery::mock();
        $redis->shouldReceive('llen')->andReturnUsing(static fn(string $key): int => $key === 'queues:listing' ? 2 : 0);
        Redis::shouldReceive('connection')->andReturn($redis);

        $this->artisan('crawler:feed-pool')->assertSuccessful();

        $this->assertSame(CrawlQueue::STATUS_PENDING, $row->refresh()->status);
        Queue::assertNothingPushed();
        Http::assertSent(static function ($request): bool {
            if (! str_contains($request->url(), '/_json')) {
                return false;
            }

            $payload = json_decode($request->body(), true);
            if (! is_array($payload)) {
                return false;
            }
            $records = array_is_list($payload) ? $payload : [$payload];

            return collect($records)->contains(
                static fn(mixed $record): bool
                => is_array($record)
                && ($record['event'] ?? null) === 'ops'
                && ($record['kind'] ?? null) === 'pool_gated'
                && ($record['queue'] ?? null) === 'listing'
                && ($record['depth'] ?? null) === 2
                && ($record['threshold'] ?? null) === 2,
            );
        });
        Http::assertSent(static function ($request): bool {
            if (! str_contains($request->url(), '/_json')) {
                return false;
            }

            $payload = json_decode($request->body(), true);
            $records = is_array($payload) && array_is_list($payload) ? $payload : [$payload];

            return collect($records)->contains(
                static fn(mixed $record): bool
                => is_array($record)
                && ($record['event'] ?? null) === 'ops'
                && ($record['kind'] ?? null) === 'feed_gated'
                && ($record['reason'] ?? null) === 'depth'
                && ($record['lane'] ?? null) === 'direct',
            );
        });
    }

    public function test_feed_pool_counts_budget_and_inflight_stops_for_the_same_source(): void
    {
        config([
            'cache.default' => 'array',
            'javmeta_queues.pacing_horizon_seconds' => 60,
            'javmeta_queues.per_source_inflight' => 1,
        ]);
        Cache::store('array')->flush();
        $this->pacedSource('combined-stop', 60.0);
        CrawlQueue::factory()->create([
            'source_slug' => 'combined-stop',
            'kind' => CrawlQueue::KIND_DETAIL,
            'status' => CrawlQueue::STATUS_CLAIMED,
            'started_at' => now()->subSeconds(5),
            'claimed_at' => now()->subSeconds(5),
        ]);
        CrawlQueue::factory()->create([
            'source_slug' => 'combined-stop',
            'kind' => CrawlQueue::KIND_DETAIL,
            'status' => CrawlQueue::STATUS_PENDING,
            'next_attempt_at' => now()->subSecond(),
        ]);
        Queue::fake();

        $this->artisan('crawler:feed-pool', ['--limit' => 1])->assertSuccessful();

        $minute = intdiv(now()->timestamp, 60);
        foreach (['exhausted_budget', 'per_source_inflight'] as $reason) {
            $this->assertSame(
                1,
                Cache::store('array')->get("javmeta:crawl:feed-metric:stopped:{$reason}:combined-stop:{$minute}", 0),
            );
        }
    }

    public function test_feed_pool_emits_depth_gate_when_existing_jobs_reduce_the_queue_capacity(): void
    {
        $rows = CrawlQueue::factory()->count(2)->create([
            'source_slug' => fake()->slug(2),
            'kind' => CrawlQueue::KIND_LISTING,
        ]);
        Queue::fake();
        config([
            'queue.default' => 'redis',
            'queue.connections.redis.driver' => 'redis',
            'queue.connections.redis.connection' => 'default',
            'javmeta_queues.depth_limit' => 2,
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.stream_logs' => 'javmeta_logs',
            'openobserve.email' => 'root@javmeta.local',
            'openobserve.password' => 'secret',
            'openobserve.timeout' => 1,
        ]);
        Http::fake(['openobserve.test/*' => Http::response(['code' => 200], 200)]);
        $redis = Mockery::mock();
        $redis->shouldReceive('llen')->andReturnUsing(static fn(string $key): int => $key === 'queues:listing' ? 1 : 0);
        Redis::shouldReceive('connection')->andReturn($redis);

        $this->artisan('crawler:feed-pool')->assertSuccessful();

        Queue::assertPushed(FetchListingJob::class, 1);
        $this->assertSame(1, $rows->filter(static fn(CrawlQueue $row): bool => $row->refresh()->status === CrawlQueue::STATUS_PENDING)->count());
        Http::assertSent(static fn($request): bool => str_contains($request->url(), '/_json')
            && str_contains($request->body(), 'feed_gated')
            && str_contains($request->body(), '"reason":"depth"'));
    }

    public function test_feed_pool_claims_only_the_remaining_capacity_for_each_queue(): void
    {
        $rows = CrawlQueue::factory()->count(5)->create([
            'source_slug' => fake()->slug(2),
            'kind' => CrawlQueue::KIND_LISTING,
        ]);
        Queue::fake();
        config([
            'queue.default' => 'redis',
            'queue.connections.redis.driver' => 'redis',
            'queue.connections.redis.connection' => 'default',
            'javmeta_queues.depth_limit' => 5,
        ]);
        $redis = Mockery::mock();
        $redis->shouldReceive('llen')->andReturnUsing(static fn(string $key): int => $key === 'queues:listing' ? 2 : 0);
        Redis::shouldReceive('connection')->andReturn($redis);

        $this->artisan('crawler:feed-pool')->assertSuccessful();

        Queue::assertPushed(FetchListingJob::class, 3);
        $this->assertSame(3, $rows->filter(static fn(CrawlQueue $row): bool => $row->refresh()->status === CrawlQueue::STATUS_CLAIMED)->count());
        $this->assertSame(2, $rows->filter(static fn(CrawlQueue $row): bool => $row->refresh()->status === CrawlQueue::STATUS_PENDING)->count());
    }

    public function test_feed_pool_uses_redis_depth_instead_of_the_database_jobs_table(): void
    {
        $rows = CrawlQueue::factory()->count(2)->create([
            'source_slug' => fake()->slug(2),
            'kind' => CrawlQueue::KIND_LISTING,
        ]);
        $now = now()->getTimestamp();
        DB::table('jobs')->insert([
            'queue' => 'listing',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $now,
            'created_at' => $now,
        ]);
        Queue::fake();
        config([
            'queue.default' => 'redis',
            'queue.connections.redis.driver' => 'redis',
            'queue.connections.redis.connection' => 'default',
            'javmeta_queues.depth_limit' => 2,
        ]);
        $redis = Mockery::mock();
        $redis->shouldReceive('llen')->andReturnUsing(static fn(string $key): int => $key === 'queues:listing' ? 1 : 0);
        Redis::shouldReceive('connection')->andReturn($redis);

        $this->artisan('crawler:feed-pool')->assertSuccessful();

        Queue::assertPushed(FetchListingJob::class, 1);
        $this->assertSame(1, $rows->filter(static fn(CrawlQueue $row): bool => $row->refresh()->status === CrawlQueue::STATUS_CLAIMED)->count());
        $this->assertSame(1, $rows->filter(static fn(CrawlQueue $row): bool => $row->refresh()->status === CrawlQueue::STATUS_PENDING)->count());
    }

    private function pacedSource(string $slug, float $gap): void
    {
        Source::query()->create([
            'slug' => $slug, 'name' => $slug, 'base_url' => 'https://' . $slug . '.test', 'enabled' => true, 'priority' => 1,
            'needs_proxy' => false, 'gap_seconds_default' => $gap, 'gap_seconds_min' => 1, 'gap_seconds_max' => 60, 'gap_seconds_current' => $gap,
        ]);
    }

    public function test_feed_pool_spaces_the_rows_of_one_source_one_gap_apart_and_stops_at_its_budget(): void
    {
        config()->set('javmeta_queues.pacing_horizon_seconds', 60);
        config()->set('javmeta_queues.per_source_inflight', 50);
        $this->pacedSource('paced', 10.0);
        CrawlQueue::factory()->count(10)->create(['source_slug' => 'paced', 'kind' => CrawlQueue::KIND_DETAIL]);
        Queue::fake();

        $this->artisan('crawler:feed-pool')->assertSuccessful();

        // floor(60 / 10) = 6 rows in this minute, starting 0, 10, 20 ... seconds apart.
        Queue::assertPushed(FetchDetailJob::class, 6);
        $delays = Queue::pushed(FetchDetailJob::class)->map(static fn(FetchDetailJob $job): int => (int) $job->delay)->values()->all();
        $this->assertSame([0, 10, 20, 30, 40, 50], $delays);
        $this->assertSame(4, CrawlQueue::query()->where('status', CrawlQueue::STATUS_PENDING)->count());
    }

    public function test_six_feed_runs_share_the_source_budget_and_keep_waiting_delays_contiguous(): void
    {
        $start = now()->startOfMinute();
        $this->travelTo($start);
        config([
            'javmeta_queues.feed_interval_seconds' => 10,
            'javmeta_queues.feed_pool_limit' => 50,
            'javmeta_queues.per_source_inflight' => 12,
            'javmeta_queues.pacing_horizon_seconds' => 60,
        ]);
        $this->pacedSource('paced', 5.0);
        CrawlQueue::factory()->count(30)->create(['source_slug' => 'paced', 'kind' => CrawlQueue::KIND_DETAIL]);
        Queue::fake();

        for ($run = 0; $run < 6; $run++) {
            $this->travelTo($start->copy()->addSeconds($run * 10));
            $this->artisan('crawler:feed-pool')->assertSuccessful();

            $claimed = CrawlQueue::query()
                ->where('source_slug', 'paced')
                ->where('status', CrawlQueue::STATUS_CLAIMED)
                ->count();
            $this->assertLessThanOrEqual(12, $claimed);
            if ($run === 0) {
                $this->assertSame(8, $claimed);
            }
        }

        Queue::assertPushed(FetchDetailJob::class, 12);
        $delays = Queue::pushed(FetchDetailJob::class)
            ->map(static fn(FetchDetailJob $job): int => (int) $job->delay)
            ->values()
            ->all();
        $this->assertSame(range(0, 55, 5), $delays);
        $this->assertSame(12, CrawlQueue::query()->where('source_slug', 'paced')->where('status', CrawlQueue::STATUS_CLAIMED)->count());
    }

    public function test_source_with_gap_beyond_horizon_can_dispatch_after_its_old_activity_expires(): void
    {
        config([
            'javmeta_queues.pacing_horizon_seconds' => 60,
            'javmeta_queues.per_source_inflight' => 4,
        ]);
        $this->pacedSource('long-gap', 120.0);
        CrawlQueue::factory()->create([
            'source_slug' => 'long-gap',
            'kind' => CrawlQueue::KIND_DETAIL,
            'status' => CrawlQueue::STATUS_CLAIMED,
            'claimed_at' => now()->subSeconds(120),
            'started_at' => now()->subSeconds(120),
        ]);
        $pending = CrawlQueue::factory()->create([
            'source_slug' => 'long-gap',
            'kind' => CrawlQueue::KIND_DETAIL,
        ]);
        Queue::fake();

        $this->artisan('crawler:feed-pool')->assertSuccessful();

        Queue::assertPushed(FetchDetailJob::class, 1);
        $this->assertSame(CrawlQueue::STATUS_CLAIMED, $pending->refresh()->status);
    }

    public function test_per_minute_lane_limit_distributes_a_single_job_across_ten_second_slots(): void
    {
        $start = now()->startOfMinute();
        config([
            'javmeta_queues.feed_interval_seconds' => 10,
            'javmeta_queues.feed_pool_limit' => 1,
            'javmeta_queues.per_source_inflight' => 50,
        ]);
        $this->pacedSource('slow-lane', 1.0);
        CrawlQueue::factory()->count(6)->create(['source_slug' => 'slow-lane', 'kind' => CrawlQueue::KIND_LISTING]);
        Queue::fake();

        for ($run = 0; $run < 6; $run++) {
            $this->travelTo($start->copy()->addSeconds($run * 10));
            $this->artisan('crawler:feed-pool')->assertSuccessful();
            $this->assertSame($run === 5 ? 1 : 0, CrawlQueue::query()->where('status', CrawlQueue::STATUS_CLAIMED)->count());
        }

        Queue::assertPushed(FetchListingJob::class, 1);
    }

    public function test_six_scheduled_runs_keep_the_lane_limit_when_the_setting_changes_mid_minute(): void
    {
        $start = now()->startOfMinute();
        config([
            'javmeta_queues.feed_interval_seconds' => 10,
            'javmeta_queues.feed_pool_limit' => 50,
            'javmeta_queues.per_source_inflight' => 64,
            'javmeta_queues.pacing_horizon_seconds' => 60,
        ]);
        $this->pacedSource('capped-minute', 1.0);
        CrawlQueue::factory()->count(60)->create([
            'source_slug' => 'capped-minute',
            'kind' => CrawlQueue::KIND_LISTING,
        ]);
        Queue::fake();
        $dispatchedPerRun = [];

        for ($run = 0; $run < 6; $run++) {
            $this->travelTo($start->copy()->addSeconds($run * 10));
            $before = CrawlQueue::query()->where('status', CrawlQueue::STATUS_CLAIMED)->count();
            $this->artisan('crawler:feed-pool')->assertSuccessful();
            $after = CrawlQueue::query()->where('status', CrawlQueue::STATUS_CLAIMED)->count();
            $dispatchedPerRun[] = $after - $before;
            $this->assertLessThanOrEqual(50, $after);
        }

        $this->assertSame([8, 8, 9, 8, 8, 9], $dispatchedPerRun);
        $this->assertSame(50, CrawlQueue::query()->where('status', CrawlQueue::STATUS_CLAIMED)->count());
        Queue::assertPushed(FetchListingJob::class, 50);
    }

    public function test_large_backlog_reaches_at_least_ninety_percent_of_the_per_source_start_budget(): void
    {
        config([
            'cache.default' => 'array',
            'javmeta_queues.feed_interval_seconds' => 10,
            'javmeta_queues.pacing_horizon_seconds' => 60,
            'javmeta_queues.min_effective_gap_seconds' => 1.0,
        ]);
        Cache::store('array')->flush();
        $start = now()->startOfMinute();
        $this->travelTo($start);
        $this->pacedSource('steady', 1.0);
        CrawlQueue::factory()->count(100)->create(['source_slug' => 'steady', 'kind' => CrawlQueue::KIND_DETAIL]);
        Queue::fake();

        $events = [];
        $completions = [];
        $startedAt = [];
        for ($run = 0; $run < 6; $run++) {
            $runAt = $start->copy()->addSeconds($run * 10);
            $this->simulatePacedJobsUntil($events, $completions, $startedAt, $runAt->timestamp * 1_000_000);
            $this->travelTo($runAt);
            $before = Queue::pushed(FetchDetailJob::class)->count();
            $this->artisan('crawler:feed-pool', ['--scheduled' => true])->assertSuccessful();

            foreach (Queue::pushed(FetchDetailJob::class)->slice($before) as $job) {
                $events[] = [
                    'at' => $runAt->timestamp * 1_000_000 + ((int) ($job->delay ?? 0) * 1_000_000),
                    'row_id' => $job->crawlQueueId,
                ];
            }
        }

        $this->simulatePacedJobsUntil($events, $completions, $startedAt, $start->copy()->addMinute()->timestamp * 1_000_000);

        $budget = (int) floor(60 / app(SourceThrottle::class)->effectiveGap('steady'));
        $this->assertGreaterThanOrEqual((int) ceil($budget * 0.9), count($startedAt));
        $this->assertSame(60, count($startedAt));

        config()->set([
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.email' => 'root@javmeta.local',
            'openobserve.password' => 'secret',
            'openobserve.timeout' => 1,
        ]);
        Http::fake(['openobserve.test/*' => Http::response(['code' => 200], 200)]);
        $this->travelTo($start->copy()->addMinute());
        $this->artisan('obs:publish-metrics')->assertSuccessful();

        Http::assertSent(static function ($request): bool {
            if (! str_contains($request->url(), '/v1/metrics')) {
                return false;
            }

            $sourceValues = [];
            $metrics = $request->data()['resourceMetrics'][0]['scopeMetrics'][0]['metrics'] ?? [];
            foreach ($metrics as $metric) {
                if (! in_array($metric['name'] ?? '', ['javmeta_feed_source_budget', 'javmeta_feed_source_started'], true)) {
                    continue;
                }

                $point = $metric['gauge']['dataPoints'][0] ?? [];
                foreach ($point['attributes'] ?? [] as $attribute) {
                    if (($attribute['key'] ?? null) === 'source_slug' && ($attribute['value']['stringValue'] ?? null) === 'steady') {
                        $sourceValues[$metric['name']] = $point['asDouble'] ?? null;
                    }
                }
            }

            $budget = $sourceValues['javmeta_feed_source_budget'] ?? 0.0;
            $started = $sourceValues['javmeta_feed_source_started'] ?? 0.0;

            return $budget === 60.0 && $started >= 54.0 && $started / $budget >= 0.9;
        });
    }

    public function test_feed_pool_counts_global_cap_stops_per_source(): void
    {
        config([
            'cache.default' => 'array',
            'javmeta_queues.feed_interval_seconds' => 10,
            'javmeta_queues.feed_pool_limit' => 60,
            'javmeta_queues.per_source_inflight' => 16,
        ]);
        Cache::store('array')->flush();
        $start = now()->startOfMinute()->addSeconds(20);
        $this->travelTo($start);
        $this->pacedSource('global-cap', 1.0);
        CrawlQueue::factory()->count(2)->create(['source_slug' => 'global-cap', 'kind' => CrawlQueue::KIND_DETAIL]);
        Queue::fake();

        $this->artisan('crawler:feed-pool', ['--limit' => 1])->assertSuccessful();
        $this->travelTo($start->copy()->addMinute());

        $this->assertSame(1, app(FeedPoolMetrics::class)->stoppedPreviousMinute('global-cap', 'global_cap'));
    }

    public function test_feed_pool_counts_queue_depth_stops_per_source(): void
    {
        config([
            'cache.default' => 'array',
            'queue.default' => 'database',
            'javmeta_queues.depth_limit' => 1,
            'javmeta_queues.feed_interval_seconds' => 10,
            'javmeta_queues.feed_pool_limit' => 60,
        ]);
        Cache::store('array')->flush();
        $start = now()->startOfMinute()->addSeconds(20);
        $this->travelTo($start);
        $this->pacedSource('depth-stop', 1.0);
        CrawlQueue::factory()->create(['source_slug' => 'depth-stop', 'kind' => CrawlQueue::KIND_DETAIL]);
        DB::table('jobs')->insert([
            'queue' => config('javmeta_queues.names.detail'),
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);
        Queue::fake();

        $this->artisan('crawler:feed-pool')->assertSuccessful();
        $this->travelTo($start->copy()->addMinute());

        $this->assertSame(1, app(FeedPoolMetrics::class)->stoppedPreviousMinute('depth-stop', 'depth'));
    }

    /**
     * @param  list<array{at: int, row_id: int}>  $events
     * @param  list<array{at: int, row_id: int}>  $completions
     * @param  list<int>  $startedAt
     */
    private function simulatePacedJobsUntil(array &$events, array &$completions, array &$startedAt, int $until): void
    {
        while ($events !== []) {
            usort($events, static fn(array $first, array $second): int => $first['at'] <=> $second['at']);
            $event = array_shift($events);
            if ($event['at'] > $until) {
                $events[] = $event;

                break;
            }

            $delay = app(SourceThrottle::class)->requestStartDelayMicroseconds('steady', $event['at']);
            if ($delay > 0) {
                $event['at'] += $delay;
                $events[] = $event;

                continue;
            }

            $startedAt[] = $event['at'];
            $timestamp = CarbonImmutable::createFromTimestamp((int) floor($event['at'] / 1_000_000))
                ->addMicroseconds($event['at'] % 1_000_000);
            $row = CrawlQueue::query()->findOrFail($event['row_id']);
            $row->forceFill(['started_at' => $timestamp, 'claimed_at' => $timestamp])->save();
            DB::table('crawl_fetch_attempts')->insert([
                'created_at' => $timestamp,
                'source_slug' => 'steady',
                'crawl_queue_id' => $row->id,
                'url_hash' => hash('sha256', (string) $row->id),
                'method' => 'http',
                'started_at' => $timestamp,
                'duration_ms' => 4_700,
                'outcome' => 'ok',
            ]);
            $completions[] = ['at' => $event['at'] + 4_700_000, 'row_id' => $row->id];
        }

        foreach ($completions as $index => $completion) {
            if ($completion['at'] > $until) {
                continue;
            }

            CrawlQueue::query()->whereKey($completion['row_id'])->update([
                'status' => CrawlQueue::STATUS_DONE,
                'started_at' => null,
                'claimed_at' => null,
                'locked_by' => null,
            ]);
            unset($completions[$index]);
        }
        $completions = array_values($completions);
    }

    public function test_requested_run_limit_cannot_exceed_the_scaled_per_minute_lane_cap(): void
    {
        $start = now()->startOfMinute();
        config([
            'javmeta_queues.feed_interval_seconds' => 10,
            'javmeta_queues.feed_pool_limit' => 1,
            'javmeta_queues.per_source_inflight' => 50,
        ]);
        $this->pacedSource('capped-lane', 1.0);
        CrawlQueue::factory()->count(6)->create(['source_slug' => 'capped-lane', 'kind' => CrawlQueue::KIND_LISTING]);
        Queue::fake();

        for ($run = 0; $run < 6; $run++) {
            $this->travelTo($start->copy()->addSeconds($run * 10));
            $this->artisan('crawler:feed-pool', ['--limit' => 50])->assertSuccessful();
        }

        Queue::assertPushed(FetchListingJob::class, 1);
        $this->assertSame(1, CrawlQueue::query()->where('status', CrawlQueue::STATUS_CLAIMED)->count());
    }

    public function test_recent_source_requests_and_unstarted_claims_share_the_pacing_budget(): void
    {
        config([
            'javmeta_queues.feed_interval_seconds' => 60,
            'javmeta_queues.per_source_inflight' => 50,
            'javmeta_queues.pacing_horizon_seconds' => 60,
        ]);
        $this->pacedSource('paced', 5.0);
        $attempts = [];
        for ($index = 0; $index < 11; $index++) {
            $attempts[] = [
                'created_at' => now(),
                'source_slug' => 'paced',
                'crawl_queue_id' => null,
                'url_hash' => hash('sha256', (string) $index),
                'method' => 'http',
                'started_at' => now()->subSeconds($index),
                'duration_ms' => 1,
                'outcome' => 'ok',
            ];
        }
        DB::table('crawl_fetch_attempts')->insert($attempts);
        CrawlQueue::factory()->count(3)->create(['source_slug' => 'paced', 'kind' => CrawlQueue::KIND_DETAIL]);
        Queue::fake();

        $this->artisan('crawler:feed-pool', ['--limit' => 50])->assertSuccessful();

        Queue::assertPushed(FetchDetailJob::class, 1);
        $this->assertSame(1, CrawlQueue::query()->where('source_slug', 'paced')->where('status', CrawlQueue::STATUS_CLAIMED)->count());
    }

    public function test_fetch_attempts_persisted_recently_do_not_count_when_the_request_started_before_the_horizon(): void
    {
        config([
            'javmeta_queues.feed_interval_seconds' => 60,
            'javmeta_queues.per_source_inflight' => 50,
            'javmeta_queues.pacing_horizon_seconds' => 60,
        ]);
        $this->pacedSource('old-attempts', 5.0);
        $attempts = [];
        for ($index = 0; $index < 12; $index++) {
            $attempts[] = [
                'created_at' => now(),
                'source_slug' => 'old-attempts',
                'crawl_queue_id' => null,
                'url_hash' => hash('sha256', 'old-attempt-' . $index),
                'method' => 'http',
                'started_at' => now()->subSeconds(61 + $index),
                'duration_ms' => 1,
                'outcome' => 'ok',
            ];
        }
        DB::table('crawl_fetch_attempts')->insert($attempts);
        $rows = CrawlQueue::factory()->count(2)->create(['source_slug' => 'old-attempts', 'kind' => CrawlQueue::KIND_DETAIL]);
        Queue::fake();

        $this->artisan('crawler:feed-pool', ['--limit' => 50])->assertSuccessful();

        Queue::assertPushed(FetchDetailJob::class, 2);
        $this->assertSame(2, $rows->filter(static fn(CrawlQueue $row): bool => $row->refresh()->status === CrawlQueue::STATUS_CLAIMED)->count());
    }

    public function test_feed_pool_emits_lane_dispatch_metrics_and_global_cap_gate(): void
    {
        config([
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.stream_logs' => 'javmeta_logs',
            'openobserve.email' => 'root@javmeta.local',
            'openobserve.password' => 'secret',
            'openobserve.timeout' => 1,
        ]);
        Http::fake(['openobserve.test/*' => Http::response(['code' => 200], 200)]);
        $this->pacedSource('direct-source', 1.0);
        CrawlQueue::factory()->create(['source_slug' => 'direct-source', 'kind' => CrawlQueue::KIND_LISTING]);
        CrawlQueue::factory()->create(['source_slug' => 'direct-source', 'kind' => CrawlQueue::KIND_DETAIL]);
        Queue::fake();

        $this->artisan('crawler:feed-pool', ['--limit' => 1])->assertSuccessful();

        Queue::assertPushed(FetchListingJob::class, 1);
        Queue::assertNotPushed(FetchDetailJob::class);
        foreach ([['direct', 1], ['browser', 0]] as [$lane, $dispatched]) {
            Http::assertSent(static fn($request): bool => str_contains($request->url(), '/_json')
                && str_contains($request->body(), 'feed_dispatched')
                && str_contains($request->body(), '"lane":"' . $lane . '"')
                && str_contains($request->body(), '"dispatched":' . $dispatched));
        }
        Http::assertSent(static fn($request): bool => str_contains($request->url(), '/_json')
            && str_contains($request->body(), '"kind":"crawl_dispatch"')
            && str_contains($request->body(), '"dispatched":1'));
        Http::assertSent(static fn($request): bool => str_contains($request->url(), '/_json')
            && str_contains($request->body(), '"kind":"feed_gated"')
            && str_contains($request->body(), '"reason":"global_cap"'));
    }

    public function test_feed_pool_emits_source_budget_gate_metrics(): void
    {
        config([
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.stream_logs' => 'javmeta_logs',
            'openobserve.email' => 'root@javmeta.local',
            'openobserve.password' => 'secret',
            'openobserve.timeout' => 1,
            'javmeta_queues.per_source_inflight' => 50,
        ]);
        Http::fake(['openobserve.test/*' => Http::response(['code' => 200], 200)]);
        $this->pacedSource('paced', 5.0);
        CrawlQueue::factory()->count(12)->create([
            'source_slug' => 'paced',
            'kind' => CrawlQueue::KIND_DETAIL,
            'status' => CrawlQueue::STATUS_CLAIMED,
            'started_at' => null,
            'claimed_at' => now(),
        ]);
        CrawlQueue::factory()->create(['source_slug' => 'paced', 'kind' => CrawlQueue::KIND_DETAIL]);
        Queue::fake();

        $this->artisan('crawler:feed-pool', ['--limit' => 10])->assertSuccessful();

        Queue::assertNothingPushed();
        Http::assertSent(static fn($request): bool => str_contains($request->url(), '/_json')
            && str_contains($request->body(), '"kind":"feed_gated"')
            && str_contains($request->body(), '"reason":"source_budget"'));
    }

    public function test_rows_still_waiting_from_an_earlier_run_count_against_the_budget_and_push_the_next_start_back(): void
    {
        config()->set('javmeta_queues.pacing_horizon_seconds', 60);
        config()->set('javmeta_queues.per_source_inflight', 50);
        $this->pacedSource('paced', 10.0);
        CrawlQueue::factory()->count(4)->create(['source_slug' => 'paced', 'kind' => CrawlQueue::KIND_DETAIL, 'status' => CrawlQueue::STATUS_CLAIMED, 'claimed_at' => now()]);
        CrawlQueue::factory()->count(5)->create(['source_slug' => 'paced', 'kind' => CrawlQueue::KIND_DETAIL]);
        Queue::fake();

        $this->artisan('crawler:feed-pool')->assertSuccessful();

        Queue::assertPushed(FetchDetailJob::class, 2);   // budget 6, 4 already waiting
        $delays = Queue::pushed(FetchDetailJob::class)->map(static fn(FetchDetailJob $job): int => (int) $job->delay)->values()->all();
        $this->assertSame([40, 50], $delays);
    }

    public function test_the_budget_is_per_source_so_a_second_source_still_gets_its_turn(): void
    {
        config()->set('javmeta_queues.pacing_horizon_seconds', 20);
        config()->set('javmeta_queues.per_source_inflight', 50);
        $this->pacedSource('first', 10.0);
        $this->pacedSource('second', 10.0);
        CrawlQueue::factory()->count(5)->create(['source_slug' => 'first', 'kind' => CrawlQueue::KIND_DETAIL]);
        CrawlQueue::factory()->count(5)->create(['source_slug' => 'second', 'kind' => CrawlQueue::KIND_DETAIL]);
        Queue::fake();

        $this->artisan('crawler:feed-pool')->assertSuccessful();

        Queue::assertPushed(FetchDetailJob::class, 4);   // 2 per source
        $this->assertSame(2, CrawlQueue::query()->where('source_slug', 'second')->where('status', CrawlQueue::STATUS_CLAIMED)->count());
    }
}
