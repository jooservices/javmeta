<?php

declare(strict_types=1);

namespace Tests\Feature\Crawl;

use App\Jobs\FetchListingJob;
use App\Jobs\FetchDetailJob;
use App\Jobs\FetchGalleryJob;
use App\Jobs\FetchPerformerListingJob;
use App\Jobs\FetchPerformerDetailJob;
use App\Models\CrawlQueue;
use App\Models\Source;
use App\Services\Crawl\CrawlQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CrawlLaneServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_routes_each_kind_and_disabled_split_uses_old_names(): void
    {
        Queue::fake();
        foreach (array_keys(config('javmeta_queues.names')) as $kind) {
            foreach (['javbus', 'direct'] as $slug) {
                CrawlQueue::factory()->create(['source_slug' => $slug, 'kind' => $kind]);
            }
        }
        config(['javmeta_queues.browser_inflight' => 0]);
        $this->artisan('crawler:feed-pool')->assertSuccessful();
        foreach (config('javmeta_queues.names') as $kind => $name) {
            $job = match ($kind) {
                'listing' => FetchListingJob::class,
                'detail' => FetchDetailJob::class,
                'performer_listing' => FetchPerformerListingJob::class,
                'performer_detail' => FetchPerformerDetailJob::class,
                default => FetchGalleryJob::class,
            };
            Queue::assertPushedOn($name, $job);
            Queue::assertPushedOn('browser_' . $name, $job);
        }
        Queue::fake();
        CrawlQueue::factory()->create(['source_slug' => 'missav', 'kind' => 'listing']);
        config(['javmeta_queues.lanes_enabled' => false]);
        $this->artisan('crawler:feed-pool')->assertSuccessful();
        Queue::assertPushedOn('listing', FetchListingJob::class);
        Queue::assertNotPushed(FetchListingJob::class, static fn(FetchListingJob $job): bool => $job->queue === 'browser_listing');
    }

    public function test_computed_browser_sources_share_global_cap_across_kinds_and_legacy_claims(): void
    {
        Source::factory()->create(['slug' => 'heyzo', 'browser_lane' => true]);
        config(['javmeta_queues.browser_inflight' => 1]);
        CrawlQueue::factory()->create(['source_slug' => 'heyzo', 'kind' => 'detail', 'status' => 'claimed', 'claimed_at' => now()]);
        $browser = CrawlQueue::factory()->create(['source_slug' => 'javbus', 'kind' => 'listing']);
        $direct = CrawlQueue::factory()->create(['source_slug' => 'direct', 'kind' => 'listing']);
        Queue::fake();
        $this->artisan('crawler:feed-pool')->assertSuccessful();
        $this->assertSame('pending', $browser->refresh()->status);
        $this->assertSame('claimed', $direct->refresh()->status);
    }

    /** @return array<string, array{string, string}> */
    public static function fullLaneProvider(): array
    {
        return ['direct full' => ['listing', 'direct'], 'browser full' => ['browser_listing', 'javbus']];
    }

    #[DataProvider('fullLaneProvider')]
    public function test_depth_limit_only_gates_its_own_lane(string $fullQueue, string $heldSource): void
    {
        config(['queue.default' => 'redis', 'javmeta_queues.depth_limit' => 1, 'javmeta_queues.browser_depth_limit' => 2]);
        Queue::fake();
        $redis = Mockery::mock();
        $redis->shouldReceive('llen')->andReturnUsing(static fn(string $key): int => $key === 'queues:' . $fullQueue ? 2 : 0);
        Redis::shouldReceive('connection')->andReturn($redis);
        $direct = CrawlQueue::factory()->create(['source_slug' => 'direct', 'kind' => 'listing']);
        $browser = CrawlQueue::factory()->create(['source_slug' => 'javbus', 'kind' => 'listing']);
        $this->artisan('crawler:feed-pool', ['--limit' => 1])->assertSuccessful();
        $this->assertSame($heldSource === 'direct' ? 'pending' : 'claimed', $direct->refresh()->status);
        $this->assertSame($heldSource === 'javbus' ? 'pending' : 'claimed', $browser->refresh()->status);
    }

    public function test_each_lane_has_a_dispatch_budget_and_per_source_fairness_still_applies(): void
    {
        Queue::fake();
        config(['javmeta_queues.per_source_inflight' => 1]);
        foreach (['direct', 'javbus'] as $slug) {
            CrawlQueue::factory()->count(2)->create(['source_slug' => $slug, 'kind' => 'listing']);
        }
        $this->artisan('crawler:feed-pool', ['--limit' => 1])->assertSuccessful();
        Queue::assertPushed(FetchListingJob::class, 2);
        Queue::assertPushedOn('listing', FetchListingJob::class);
        Queue::assertPushedOn('browser_listing', FetchListingJob::class);
        $this->artisan('crawler:feed-pool', ['--limit' => 1])->assertSuccessful();
        Queue::assertPushed(FetchListingJob::class, 2);
        $this->assertSame(2, CrawlQueue::query()->where('status', 'pending')->count());
    }

    public function test_old_queue_job_can_start_and_reclaimed_job_cannot_start_twice(): void
    {
        $this->freezeTime();
        $queue = app(CrawlQueueService::class);
        $row = CrawlQueue::factory()->create(['source_slug' => 'javbus', 'kind' => 'listing', 'status' => 'claimed', 'claimed_at' => now()->subHour(), 'started_at' => null, 'locked_by' => 'old-token']);
        $this->assertSame(1, $queue->reclaimStaleClaimed(900));
        $this->assertNull($queue->startClaimedRow($row->id, 'listing', 'old-token'));
        $claim = $queue->claimNext('new-token', 'listing', [], 'browser');
        $this->assertSame($row->id, $claim?->id);
        $this->assertNull($queue->startClaimedRow($row->id, 'listing', 'old-token'));
        $this->assertNotNull($queue->startClaimedRow($row->id, 'listing', 'new-token'));
        $this->assertNull($queue->startClaimedRow($row->id, 'listing', 'new-token'));
    }

    public function test_browser_claims_keep_consuming_the_cap_after_a_source_moves_to_direct(): void
    {
        config(['javmeta_queues.browser_inflight' => 1]);
        $source = Source::factory()->create(['slug' => 'heyzo', 'browser_lane' => true, 'enabled' => true]);
        $row = CrawlQueue::factory()->create(['source_slug' => 'heyzo', 'kind' => 'listing']);
        $claims = app(CrawlQueueService::class);
        $this->assertSame($row->id, $claims->claimNext('first', 'listing', [], 'browser')?->id);
        $this->assertSame('browser', $row->refresh()->getAttribute('claimed_lane'));
        $source->forceFill(['browser_lane' => false])->save();
        config(['javmeta_queues.lane_direct_pins' => ['heyzo']]);
        CrawlQueue::factory()->create(['source_slug' => 'javbus', 'kind' => 'detail']);
        $this->assertNull($claims->claimNext('second', 'detail', [], 'browser'));
        $direct = CrawlQueue::factory()->create(['source_slug' => 'direct', 'kind' => 'detail']);
        $this->assertSame($direct->id, $claims->claimNext('second', 'detail', [], 'direct')?->id);
    }
}
