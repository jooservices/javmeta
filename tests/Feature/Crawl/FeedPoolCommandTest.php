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
use Illuminate\Support\Facades\Redis;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
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
        $this->assertNull($commands['crawler:feed-pool']->getDefinition()->getOption('schedule-interval')->getDefault());
        $this->assertSame(50, config('javmeta_queues.feed_pool_limit'));
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

    public function test_scheduled_feed_pool_reads_the_current_database_interval_without_scheduler_restart(): void
    {
        config(['cache.default' => 'array', 'javmeta_queues.feed_interval_seconds' => 15]);
        Cache::store('array')->flush();
        $row = CrawlQueue::factory()->create([
            'source_slug' => fake()->slug(2),
            'kind' => CrawlQueue::KIND_LISTING,
        ]);
        Queue::fake();

        $this->artisan('crawler:feed-pool', ['--scheduled' => true])->assertSuccessful();
        $this->travel(14)->seconds();
        config(['javmeta_queues.feed_interval_seconds' => 10]);
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
            if ($run === 1) {
                config()->set('javmeta_queues.feed_interval_seconds', 60);
            }
            $before = CrawlQueue::query()->where('status', CrawlQueue::STATUS_CLAIMED)->count();
            $this->artisan('crawler:feed-pool', ['--schedule-interval' => 10])->assertSuccessful();
            $after = CrawlQueue::query()->where('status', CrawlQueue::STATUS_CLAIMED)->count();
            $dispatchedPerRun[] = $after - $before;
            $this->assertLessThanOrEqual(50, $after);
        }

        $this->assertSame([8, 8, 9, 8, 8, 9], $dispatchedPerRun);
        $this->assertSame(50, CrawlQueue::query()->where('status', CrawlQueue::STATUS_CLAIMED)->count());
        Queue::assertPushed(FetchListingJob::class, 50);
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
