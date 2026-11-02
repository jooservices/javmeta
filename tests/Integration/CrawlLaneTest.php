<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\CrawlQueue;
use App\Models\Source;
use App\Jobs\FetchDetailJob;
use App\Services\Crawl\CrawlLaneService;
use App\Services\Crawl\CrawlQueueService;
use App\Support\Crawl\LaravelCrawlQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Support\Jobs\RedisQueueProbeJob;

final class CrawlLaneTest extends IntegrationTestCase
{
    public function test_postgres_classification_and_claims_route_to_independent_redis_queues(): void
    {
        $this->freezeTime();
        $suffix = Str::lower(Str::random(12));
        $directQueue = 'direct_lane_' . $suffix;
        $browserQueue = 'browser_lane_' . $suffix;
        config([
            'queue.default' => 'redis',
            'javmeta_queues.names' => ['listing' => $directQueue],
            'javmeta_queues.browser_names' => ['listing' => $browserQueue],
            'javmeta_queues.feed_pool_names' => [$directQueue, $browserQueue],
            'javmeta_queues.depth_limit' => 1,
            'javmeta_queues.browser_depth_limit' => 2,
            'javmeta_queues.browser_inflight' => 1,
        ]);
        Source::factory()->create(['slug' => 'heyzo', 'enabled' => true]);
        for ($i = 0; $i < 40; $i++) {
            DB::table('crawl_fetch_attempts')->insert([
                'source_slug' => 'heyzo', 'created_at' => now()->subHour(), 'started_at' => now()->subHour(),
                'url_hash' => hash('sha256', (string) $i), 'method' => $i < 10 ? 'flaresolverr' : 'http',
                'duration_ms' => 1, 'outcome' => 'ok',
            ]);
        }
        app(CrawlLaneService::class)->recompute();
        $this->assertSame('browser', app(CrawlLaneService::class)->lane('heyzo'));
        $direct = CrawlQueue::factory()->create(['kind' => 'listing', 'source_slug' => 'direct']);
        $browser = CrawlQueue::factory()->create(['kind' => 'listing', 'source_slug' => 'heyzo']);
        $queue = Queue::connection('redis');
        try {
            $queue->push(new RedisQueueProbeJob('gate', 'probe:' . $suffix), '', $directQueue);
            $this->artisan('crawler:feed-pool', ['--limit' => 1])->assertSuccessful();
            $this->assertSame('pending', $direct->refresh()->status);
            $this->assertSame('claimed', $browser->refresh()->status);
            $this->assertSame(1, LaravelCrawlQueue::readyDepth($browserQueue));
            $payload = json_decode((string) Redis::connection()->lindex('queues:' . $browserQueue, 0), true);
            $this->assertSame($browserQueue, unserialize($payload['data']['command'])->queue);
            config(['javmeta_queues.lane_direct_pins' => ['heyzo']]);
            $next = CrawlQueue::factory()->create(['kind' => 'detail', 'source_slug' => 'javbus']);
            $claims = app(CrawlQueueService::class);
            $this->assertNull($claims->claimNext('second', 'detail', [], 'browser'));
            $this->assertSame('pending', $next->refresh()->status);
            $browser->forceFill(['claimed_at' => now()->subHour(), 'started_at' => null])->save();
            $this->assertSame(1, $claims->reclaimStaleClaimed(900));
            $this->assertNull($claims->startClaimedRow($browser->id, 'listing', $browser->locked_by));
        } finally {
            foreach ([$directQueue, $browserQueue] as $name) {
                Redis::connection()->del(['queues:' . $name, 'queues:' . $name . ':notify', 'queues:' . $name . ':delayed', 'queues:' . $name . ':reserved']);
            }
        }
    }

    public function test_concurrent_feeder_cannot_read_capacity_until_the_first_dispatch_finishes(): void
    {
        Queue::fake();
        config(['database.connections.lane_competitor' => config('database.connections.pgsql')]);
        $competitor = DB::connection('lane_competitor');
        $row = CrawlQueue::factory()->create(['source_slug' => 'javbus', 'kind' => 'listing']);
        $competitor->select('SELECT pg_advisory_lock(?)', [282]);
        try {
            $this->artisan('crawler:feed-pool')->expectsOutputToContain('Another feed pool is dispatching.')->assertSuccessful();
            $this->assertSame('pending', $row->refresh()->status);
            Queue::assertNothingPushed();
        } finally {
            $competitor->select('SELECT pg_advisory_unlock(?)', [282]);
        }
        $this->artisan('crawler:feed-pool')->assertSuccessful();
        $this->assertSame('claimed', $row->refresh()->status);
        $this->assertTrue($competitor->selectOne('SELECT pg_try_advisory_lock(?) AS acquired', [282])->acquired);
        $competitor->select('SELECT pg_advisory_unlock(?)', [282]);
        DB::purge('lane_competitor');
    }

    public function test_postgres_feeder_respects_the_source_budget_across_six_ten_second_runs(): void
    {
        $this->freezeTime();
        $start = now()->startOfMinute();
        $this->travelTo($start);
        $slug = 'paced_' . Str::lower(Str::random(8));
        Source::factory()->create([
            'slug' => $slug,
            'name' => $slug,
            'base_url' => 'https://' . $slug . '.test',
            'enabled' => true,
            'gap_seconds_default' => 5,
            'gap_seconds_min' => 1,
            'gap_seconds_max' => 60,
            'gap_seconds_current' => 5,
        ]);
        config([
            'queue.default' => 'sync',
            'javmeta_queues.feed_interval_seconds' => 10,
            'javmeta_queues.feed_pool_limit' => 50,
            'javmeta_queues.per_source_inflight' => 12,
            'javmeta_queues.pacing_horizon_seconds' => 60,
        ]);
        CrawlQueue::factory()->count(30)->create(['source_slug' => $slug, 'kind' => CrawlQueue::KIND_DETAIL]);
        Queue::fake();

        for ($run = 0; $run < 6; $run++) {
            $this->travelTo($start->copy()->addSeconds($run * 10));
            $this->artisan('crawler:feed-pool')->assertSuccessful();

            $claimed = CrawlQueue::query()
                ->where('source_slug', $slug)
                ->where('status', CrawlQueue::STATUS_CLAIMED)
                ->count();
            $this->assertLessThanOrEqual(12, $claimed);
            $this->assertLessThanOrEqual((int) floor(60 / 5), $claimed);
        }

        Queue::assertPushed(FetchDetailJob::class, 12);
        $delays = Queue::pushed(FetchDetailJob::class)
            ->map(static fn(FetchDetailJob $job): int => (int) $job->delay)
            ->values()
            ->all();
        $this->assertSame(range(0, 55, 5), $delays);

        $attemptSource = 'attempted_' . Str::lower(Str::random(8));
        Source::factory()->create([
            'slug' => $attemptSource,
            'name' => $attemptSource,
            'base_url' => 'https://' . $attemptSource . '.test',
            'enabled' => true,
            'gap_seconds_default' => 5,
            'gap_seconds_min' => 1,
            'gap_seconds_max' => 60,
            'gap_seconds_current' => 5,
        ]);
        $attempts = [];
        for ($index = 0; $index < 11; $index++) {
            $attempts[] = [
                'created_at' => now(),
                'source_slug' => $attemptSource,
                'crawl_queue_id' => null,
                'url_hash' => hash('sha256', $attemptSource . $index),
                'method' => 'http',
                'started_at' => now()->subSeconds($index),
                'duration_ms' => 1,
                'outcome' => 'ok',
            ];
        }
        DB::table('crawl_fetch_attempts')->insert($attempts);
        $pending = CrawlQueue::factory()->count(3)->create(['source_slug' => $attemptSource, 'kind' => CrawlQueue::KIND_DETAIL]);

        $this->artisan('crawler:feed-pool', ['--limit' => 50])->assertSuccessful();

        $this->assertSame(1, $pending->filter(static fn(CrawlQueue $row): bool => $row->refresh()->status === CrawlQueue::STATUS_CLAIMED)->count());
        Queue::assertPushed(FetchDetailJob::class, 13);
    }
}
