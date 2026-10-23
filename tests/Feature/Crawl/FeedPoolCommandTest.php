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
