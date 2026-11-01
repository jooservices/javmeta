<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\CrawlQueue;
use App\Models\Source;
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
                'source_slug' => 'heyzo', 'created_at' => now(), 'started_at' => now(),
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
}
