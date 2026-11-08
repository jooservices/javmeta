<?php

declare(strict_types=1);

namespace Tests\Feature\Crawl;

use App\Console\Commands\Crawler\WatchdogCommand;
use App\Events\CrawlSourceUnhealthy;
use App\Events\WorkerHeartbeatStale;
use App\Models\CrawlQueue;
use App\Models\Source;
use App\Services\Crawl\WorkerHeartbeat;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class WatchdogCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_watchdog_command_is_registered_with_legacy_alias(): void
    {
        $commands = app(Kernel::class)->all();
        $command = $commands['crawler:watchdog'];

        $this->assertInstanceOf(WatchdogCommand::class, $command);
        $this->assertArrayHasKey('javmeta:watchdog', $commands);
        $this->assertSame($command, $commands['javmeta:watchdog']);
    }

    public function test_watchdog_dispatches_stale_heartbeat_event(): void
    {
        Event::fake([WorkerHeartbeatStale::class]);
        config(['javmeta_alerts.watchdog.heartbeat_stale_seconds' => 60]);
        cache()->forget(WorkerHeartbeat::CACHE_KEY);

        $this->artisan('crawler:watchdog')->assertSuccessful();

        Event::assertDispatched(WorkerHeartbeatStale::class);
    }

    public function test_watchdog_alerts_for_stale_sources_and_reports_crawl_health_checks(): void
    {
        Event::fake([WorkerHeartbeatStale::class, CrawlSourceUnhealthy::class]);
        config([
            'javmeta_alerts.enabled' => false,
            'javmeta_alerts.watchdog.heartbeat_stale_seconds' => 60,
            'javmeta_alerts.watchdog.source_stale_seconds' => 120,
            'javmeta_alerts.watchdog.crawl_stall_seconds' => 120,
            'javmeta_alerts.watchdog.queue_stuck_seconds' => 120,
            'javmeta_alerts.watchdog.pending_queue_limit' => 1,
        ]);
        (new WorkerHeartbeat())->beat();
        Source::factory()->create(['slug' => 'stale-watchdog-site', 'last_success_at' => now()->subMinutes(10)]);
        CrawlQueue::factory()->create(['source_slug' => 'stale-watchdog-site', 'status' => CrawlQueue::STATUS_PENDING]);
        CrawlQueue::factory()->create([
            'source_slug' => 'stale-watchdog-site',
            'status' => CrawlQueue::STATUS_CLAIMED,
            'claimed_at' => now()->subMinutes(10),
        ]);

        $this->artisan('crawler:watchdog')
            ->expectsOutputToContain('Worker heartbeat ok.')
            ->expectsOutputToContain('Source stale: stale-watchdog-site')
            ->expectsOutputToContain('Crawl made no progress.')
            ->expectsOutputToContain('Crawl queue is stuck.')
            ->expectsOutputToContain('Pending crawl backlog is high.')
            ->assertSuccessful();

        Event::assertDispatched(CrawlSourceUnhealthy::class, static fn(CrawlSourceUnhealthy $event): bool => $event->sourceSlug === 'stale-watchdog-site');
        self::assertSame(['stale-watchdog-site'], Cache::get('javmeta:watchdog:stale-sources'));
    }
}
