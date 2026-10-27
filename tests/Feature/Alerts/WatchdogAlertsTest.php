<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Contracts\AlertNotifier;
use App\Models\CrawlQueue;
use App\Models\Source;
use App\Services\Alerts\AlertDispatcher;
use App\Services\Crawl\WorkerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class WatchdogAlertsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{string, string, array<string, mixed>}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->sent = [];
        $fake = new class ($this->sent) implements AlertNotifier {
            /** @param list<array{string, string, array<string, mixed>}> $sent */
            public function __construct(private array &$sent) {}

            public function notify(string $subject, string $body, array $context = []): void
            {
                $this->sent[] = [$subject, $body, $context];
            }
        };
        $this->app->instance(AlertDispatcher::class, new AlertDispatcher($fake));
        config(['javmeta_alerts.enabled' => true, 'javmeta_alerts.throttle_seconds' => 3600]);
        (new WorkerHeartbeat())->beat();
    }

    /** @return list<string> */
    private function subjects(): array
    {
        return array_map(static fn(array $row): string => $row[0], $this->sent);
    }

    public function test_dead_source_alerts_throttles_and_recovers(): void
    {
        $source = Source::factory()->create(['slug' => 'javdb', 'last_success_at' => now()->subDays(2)]);

        $this->artisan('crawler:watchdog')->assertSuccessful();
        $this->assertSame(['Source unhealthy: javdb'], $this->subjects());

        $this->travel(30)->minutes();
        (new WorkerHeartbeat())->beat();
        $this->artisan('crawler:watchdog')->assertSuccessful();
        $this->assertCount(1, $this->sent, 'throttled within the hour');

        $this->travel(31)->minutes();
        (new WorkerHeartbeat())->beat();
        $this->artisan('crawler:watchdog')->assertSuccessful();
        $this->assertCount(2, $this->sent, 'repeats after the throttle window');

        $source->update(['last_success_at' => now()]);
        $this->artisan('crawler:watchdog')->assertSuccessful();
        $this->assertSame('Source recovered: javdb', $this->subjects()[2]);

        $this->artisan('crawler:watchdog')->assertSuccessful();
        $this->assertCount(3, $this->sent, 'recovery is sent once');
    }

    public function test_manifest_cycle_prevents_false_stale_alert_until_the_source_is_past_due(): void
    {
        $source = Source::factory()->create(['slug' => 'eporner', 'last_success_at' => now()->subDays(3)]);

        $this->artisan('crawler:watchdog')->assertSuccessful();
        $this->assertSame([], $this->sent);

        $source->update(['last_success_at' => now()->subDays(32)]);
        (new WorkerHeartbeat())->beat();
        $this->artisan('crawler:watchdog')->assertSuccessful();
        $this->assertSame(['Source unhealthy: eporner'], $this->subjects());
    }

    public function test_stalled_crawl_alerts_and_recovers(): void
    {
        $source = Source::factory()->create(['slug' => 'javbus', 'last_success_at' => now()->subHours(2)]);
        CrawlQueue::factory()->create(['source_slug' => 'javbus', 'status' => CrawlQueue::STATUS_PENDING]);
        config(['javmeta_alerts.watchdog.source_stale_seconds' => 86400 * 7]);

        $this->artisan('crawler:watchdog')->assertSuccessful();
        $this->assertSame(['Crawl made no progress'], $this->subjects());

        $source->update(['last_success_at' => now()]);
        $this->artisan('crawler:watchdog')->assertSuccessful();
        $this->assertSame(['Crawl made no progress', 'Crawl progress recovered'], $this->subjects());
    }

    public function test_idle_crawl_without_due_work_does_not_alert(): void
    {
        Source::factory()->create(['slug' => 'javbus', 'last_success_at' => now()->subHours(2)]);
        config(['javmeta_alerts.watchdog.source_stale_seconds' => 86400 * 7]);

        $this->artisan('crawler:watchdog')->assertSuccessful();

        $this->assertSame([], $this->sent);
    }

    public function test_stuck_queue_alerts_and_recovers(): void
    {
        $row = CrawlQueue::factory()->create([
            'status' => CrawlQueue::STATUS_CLAIMED,
            'claimed_at' => now()->subHours(2),
        ]);

        $this->artisan('crawler:watchdog')->assertSuccessful();
        $this->assertSame(['Crawl queue is stuck'], $this->subjects());
        $this->assertSame(1, $this->sent[0][2]['stuck_claimed']);

        $row->update(['status' => CrawlQueue::STATUS_DONE]);
        $this->artisan('crawler:watchdog')->assertSuccessful();
        $this->assertSame(['Crawl queue is stuck', 'Crawl queue recovered'], $this->subjects());
    }

    public function test_due_pending_backlog_alerts_and_recovers_below_the_configured_limit(): void
    {
        Source::factory()->create(['slug' => 'javdb', 'last_success_at' => now()]);
        config(['javmeta_alerts.watchdog.pending_queue_limit' => 2]);
        $dueOne = CrawlQueue::factory()->create([
            'source_slug' => 'javdb',
            'status' => CrawlQueue::STATUS_PENDING,
            'next_attempt_at' => null,
        ]);
        $dueTwo = CrawlQueue::factory()->create([
            'source_slug' => 'javdb',
            'status' => CrawlQueue::STATUS_PENDING,
            'next_attempt_at' => now()->subMinute(),
        ]);
        CrawlQueue::factory()->create([
            'source_slug' => 'javdb',
            'status' => CrawlQueue::STATUS_PENDING,
            'next_attempt_at' => now()->addHour(),
        ]);

        $this->artisan('crawler:watchdog')->assertSuccessful();
        $this->assertSame(['Pending crawl backlog is high'], $this->subjects());
        $this->assertSame(2, $this->sent[0][2]['pending_due']);
        $this->assertSame('open', $this->sent[0][2]['alert_state']);

        $dueTwo->update(['status' => CrawlQueue::STATUS_DONE]);
        $this->artisan('crawler:watchdog')->assertSuccessful();
        $this->assertSame(['Pending crawl backlog is high', 'Pending crawl backlog recovered'], $this->subjects());
        $this->assertSame(1, $this->sent[1][2]['pending_due']);
        $this->assertSame('resolved', $this->sent[1][2]['alert_state']);
    }

    public function test_stale_worker_heartbeat_recovers(): void
    {
        Cache::forget(WorkerHeartbeat::CACHE_KEY);
        $this->artisan('crawler:watchdog')->assertSuccessful();
        $this->assertSame(['Worker heartbeat stale'], $this->subjects());

        (new WorkerHeartbeat())->beat();
        $this->artisan('crawler:watchdog')->assertSuccessful();
        $this->assertSame(['Worker heartbeat stale', 'Worker heartbeat recovered'], $this->subjects());
    }

    public function test_alerts_disabled_sends_nothing(): void
    {
        config(['javmeta_alerts.enabled' => false]);
        CrawlQueue::factory()->create(['status' => CrawlQueue::STATUS_CLAIMED, 'claimed_at' => now()->subHours(2)]);

        $this->artisan('crawler:watchdog')->assertSuccessful();

        $this->assertSame([], $this->sent);
    }

    public function test_stalled_and_stuck_alerts_name_the_sources(): void
    {
        Source::factory()->create(['slug' => 'javbus', 'last_success_at' => now()->subHours(2)]);
        CrawlQueue::factory()->create(['source_slug' => 'javbus', 'status' => CrawlQueue::STATUS_PENDING]);
        CrawlQueue::factory()->create([
            'source_slug' => 'javdb',
            'status' => CrawlQueue::STATUS_CLAIMED,
            'claimed_at' => now()->subHours(2),
        ]);
        config(['javmeta_alerts.watchdog.source_stale_seconds' => 86400 * 7]);

        $this->artisan('crawler:watchdog')->assertSuccessful();

        $this->assertSame(['Crawl made no progress', 'Crawl queue is stuck'], $this->subjects());
        $this->assertStringContainsString('javbus', $this->sent[0][1]);
        $this->assertSame('javbus', $this->sent[0][2]['source_slugs']);
        $this->assertStringContainsString('javdb', $this->sent[1][1]);
        $this->assertSame('javdb', $this->sent[1][2]['source_slugs']);
    }

    public function test_throttled_recurrence_does_not_create_orphan_recovery(): void
    {
        $dispatcher = $this->app->make(AlertDispatcher::class);

        $this->assertTrue($dispatcher->send('k', 'a', 'b', [], 3600));
        $this->assertTrue($dispatcher->resolve('k', 'ok', 'ok'));
        $this->assertTrue($dispatcher->send('k', 'a', 'b', [], 3600) === false, 'still throttled after recovery');
        $this->assertFalse($dispatcher->resolve('k', 'ok', 'ok'), 'suppressed alert is not active');
        $this->assertCount(2, $this->sent);
    }

    public function test_disabled_source_recovers(): void
    {
        $source = Source::factory()->create(['slug' => 'javdb', 'last_success_at' => now()->subDays(2)]);
        $this->artisan('crawler:watchdog')->assertSuccessful();

        $source->update(['enabled' => false]);
        $this->artisan('crawler:watchdog')->assertSuccessful();

        $this->assertSame(['Source unhealthy: javdb', 'Source recovered: javdb'], $this->subjects());
    }

    public function test_deleted_source_recovers(): void
    {
        $source = Source::factory()->create(['slug' => 'javdb', 'last_success_at' => now()->subDays(2)]);
        $this->artisan('crawler:watchdog')->assertSuccessful();

        $source->delete();
        $this->artisan('crawler:watchdog')->assertSuccessful();

        $this->assertSame(['Source unhealthy: javdb', 'Source recovered: javdb'], $this->subjects());
    }
}
