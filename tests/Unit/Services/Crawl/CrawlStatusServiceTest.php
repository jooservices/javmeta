<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawl;

use App\Models\CrawlQueue;
use App\Models\CrawlRun;
use App\Models\Movie;
use App\Models\Performer;
use App\Models\Source;
use App\Services\Crawl\CrawlStatusService;
use App\Services\Crawl\WorkerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class CrawlStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_runs_aggregate_keeps_zero_totals_and_excludes_old_runs(): void
    {
        $service = app(CrawlStatusService::class);
        $this->assertSame(['runs' => 0, 'pages_fetched' => 0, 'movies_new' => 0, 'movies_updated' => 0, 'failures' => 0], $service->status()['runs_24h']);
        $source = Source::factory()->create();
        foreach ([now()->subHours(25), now()->subHour(), now()] as $started) {
            CrawlRun::query()->create(['source_slug' => $source->slug, 'started_at' => $started, 'status' => 'completed', 'pages_fetched' => 3, 'movies_new' => 2, 'movies_updated' => 1, 'failures' => 4]);
        }
        $this->assertSame(['runs' => 2, 'pages_fetched' => 6, 'movies_new' => 4, 'movies_updated' => 2, 'failures' => 8], $service->status()['runs_24h']);
    }

    public function test_status_reports_counts_queue_sources_workers_and_runs(): void
    {
        Carbon::setTestNow('2026-09-30 10:00:00');
        Movie::factory()->count(2)->create(['first_seen_at' => now()]);
        Performer::factory()->count(1)->create(['first_seen_at' => now()]);
        $source = Source::factory()->create([
            'slug' => 'javdb',
        ]);
        CrawlQueue::factory()->create([
            'source_slug' => $source->slug,
            'kind' => CrawlQueue::KIND_DETAIL,
            'status' => CrawlQueue::STATUS_PENDING,
        ]);
        CrawlQueue::factory()->create([
            'source_slug' => $source->slug,
            'kind' => CrawlQueue::KIND_LISTING,
            'status' => CrawlQueue::STATUS_CLAIMED,
            'claimed_at' => now(),
            'locked_by' => 'node-a:100',
        ]);
        CrawlRun::query()->create([
            'source_slug' => $source->slug,
            'started_at' => now(),
            'status' => 'completed',
            'pages_fetched' => 10,
            'movies_new' => 4,
            'movies_updated' => 2,
            'failures' => 1,
        ]);
        app(WorkerHeartbeat::class)->beat('node-a:100');

        $payload = app(CrawlStatusService::class)->status();

        $this->assertSame(2, $payload['counts']['movies']);
        $this->assertSame(1, $payload['counts']['performers']);
        $this->assertSame(2, $payload['ingest']['movies_24h']);
        $this->assertSame(1, $payload['ingest']['performers_1h']);
        $this->assertSame(1, $payload['queue']['by_status']['pending']);
        $this->assertSame(1, $payload['queue']['by_status']['claimed']);
        $this->assertSame(0, $payload['queue']['stuck_claimed']);
        $this->assertSame('closed', $payload['sources'][0]['circuit_state'], 'deprecated, constant');
        $this->assertSame(0, $payload['sources'][0]['consecutive_failures'], 'deprecated, constant');
        $this->assertSame('node-a:100', $payload['workers'][0]['instance']);
        $this->assertSame(1, $payload['workers'][0]['active_claims']);
        $this->assertSame(1, $payload['runs_24h']['runs']);
        $this->assertSame(4, $payload['runs_24h']['movies_new']);
        Carbon::setTestNow();
    }

    public function test_status_marks_stuck_claims_and_stale_workers(): void
    {
        Carbon::setTestNow('2026-09-30 10:00:00');
        $source = Source::factory()->create(['slug' => 'missav']);
        CrawlQueue::factory()->create([
            'source_slug' => $source->slug,
            'kind' => CrawlQueue::KIND_DETAIL,
            'status' => CrawlQueue::STATUS_CLAIMED,
            'claimed_at' => now()->subHours(2),
            'locked_by' => 'dead:1',
        ]);

        Carbon::setTestNow('2026-09-30 10:10:00');
        app(WorkerHeartbeat::class)->beat('alive:2');

        $payload = app(CrawlStatusService::class)->status();

        $this->assertSame(1, $payload['queue']['stuck_claimed']);
        $this->assertSame(['alive:2'], array_column($payload['workers'], 'instance'));
        $this->assertSame(0, $payload['workers'][0]['active_claims']);
        Carbon::setTestNow();
    }
}
