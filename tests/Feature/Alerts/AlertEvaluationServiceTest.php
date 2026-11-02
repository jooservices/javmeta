<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Contracts\AlertNotifier;
use App\Models\AlertEvent;
use App\Services\Alerts\AlertChannel;
use App\Services\Alerts\AlertChannelDispatcher;
use App\Services\Alerts\AlertDispatcher;
use App\Services\Alerts\AlertSnapshotReader;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AlertEvaluationServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{string, string, array<string, mixed>}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-11-02 10:00:00 UTC');
        Cache::flush();
        $this->sent = [];
        $channel = new class ($this->sent) implements AlertChannel {
            /** @param list<array{string, string, array<string, mixed>}> $sent */
            public function __construct(private array &$sent) {}

            public function send(string $subject, string $body, array $context = []): bool
            {
                $this->sent[] = [$subject, $body, $context];

                return true;
            }
        };
        $this->app->instance(AlertChannelDispatcher::class, new AlertChannelDispatcher(['telegram' => $channel]));
        $this->app->instance(AlertDispatcher::class, new AlertDispatcher(
            $this->app->make(AlertNotifier::class),
            $this->app->make(AlertChannelDispatcher::class),
        ));
        config()->set([
            'javmeta_alerts.enabled' => true,
            'javmeta_alerts.channels' => ['telegram'],
            'javmeta_alerts.evaluate_interval_seconds' => 60,
            'javmeta_alerts.window_minutes' => 15,
            'javmeta_alerts.success_rate_min_percent' => 85,
            'javmeta_alerts.blocked_ratio_max_percent' => 10,
            'javmeta_alerts.min_attempts_in_window' => 100,
            'javmeta_alerts.pg_connections_max' => 45,
            'javmeta_alerts.dispatch_age_max_seconds' => 60,
            'javmeta_alerts.backlog_due_min_per_hot_source' => 10,
            'javmeta_alerts.cooldown_minutes' => 30,
            'javmeta_alerts.recovery_notice' => true,
            'javmeta_alerts.history_retention_days' => 90,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_low_success_rate_notifies_once_per_cooldown_and_sends_one_recovery(): void
    {
        $snapshot = $this->snapshot(100, 80);
        $this->useSnapshot($snapshot);

        $this->artisan('alerts:evaluate')->assertSuccessful();
        $this->artisan('alerts:evaluate')->assertSuccessful();
        self::assertCount(1, $this->sent);
        self::assertNotNull(AlertEvent::query()->where('rule', 'success_rate')->firstOrFail()->getAttribute('notified_at'));
        self::assertNull(AlertEvent::query()->where('rule', 'source_success_rate:javdb')->firstOrFail()->getAttribute('notified_at'));
        self::assertNull(AlertEvent::query()->firstOrFail()->getAttribute('resolved_at'));

        $this->travel(29)->minutes();
        $this->artisan('alerts:evaluate')->assertSuccessful();
        self::assertCount(1, $this->sent);

        $this->travel(1)->minutes();
        $this->artisan('alerts:evaluate')->assertSuccessful();
        self::assertCount(2, $this->sent);

        $this->useSnapshot($this->snapshot(100, 90));
        $this->travel(1)->minute();
        $this->artisan('alerts:evaluate')->assertSuccessful();
        $this->artisan('alerts:evaluate')->assertSuccessful();

        self::assertNotNull(AlertEvent::query()->where('rule', 'success_rate')->firstOrFail()->getAttribute('resolved_at'));
        self::assertNotNull(AlertEvent::query()->where('rule', 'source_success_rate:javdb')->firstOrFail()->getAttribute('resolved_at'));
        self::assertCount(3, $this->sent);
        self::assertNotNull(AlertEvent::query()->firstOrFail()->getAttribute('resolved_at'));
        self::assertSame('resolved', $this->sent[2][2]['alert_state']);
    }

    public function test_small_attempt_sample_does_not_open_an_event(): void
    {
        $this->useSnapshot($this->snapshot(99, 10));

        $this->artisan('alerts:evaluate')->assertSuccessful();

        self::assertSame([], $this->sent);
        self::assertDatabaseCount('alert_events', 0);
    }

    public function test_small_sample_does_not_resolve_an_open_rate_alert(): void
    {
        $this->useSnapshot($this->snapshot(100, 80));
        $this->artisan('alerts:evaluate')->assertSuccessful();

        $this->travel(1)->minute();
        $this->useSnapshot($this->snapshot(99, 99));
        $this->artisan('alerts:evaluate')->assertSuccessful();

        self::assertCount(1, $this->sent);
        self::assertNull(AlertEvent::query()->where('rule', 'success_rate')->firstOrFail()->getAttribute('resolved_at'));
    }

    public function test_database_snapshot_excludes_skipped_attempts_and_uses_two_hundred_responses(): void
    {
        DB::table('crawl_fetch_attempts')->insert([
            $this->fetchAttemptRow('javdb', 200, 'ok'),
            $this->fetchAttemptRow('javdb', 403, 'error'),
            $this->fetchAttemptRow('javdb', null, 'skipped'),
        ]);

        $snapshot = app(\App\Services\Alerts\DatabaseAlertSnapshotReader::class)
            ->snapshot(CarbonImmutable::now()->subMinutes(15));

        self::assertSame(2, $snapshot['attempts_by_source']['javdb']['attempts']);
        self::assertSame(1, $snapshot['attempts_by_source']['javdb']['successes']);
        self::assertSame(1, $snapshot['attempts_by_source']['javdb']['blocked']);
    }

    /** @param array<string, mixed> $snapshot */
    private function useSnapshot(array $snapshot): void
    {
        $this->app->instance(AlertSnapshotReader::class, new class ($snapshot) implements AlertSnapshotReader {
            /** @param array<string, mixed> $snapshot */
            public function __construct(private array $snapshot) {}

            public function snapshot(CarbonImmutable $cutoff): array
            {
                return $this->snapshot;
            }
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(int $attempts, int $successes): array
    {
        return [
            'attempts_by_source' => ['javdb' => ['attempts' => $attempts, 'successes' => $successes, 'blocked' => 0]],
            'enabled_sources' => ['javdb'],
            'hot_sources' => ['javdb'],
            'due_by_source' => ['javdb' => 20],
            'postgres_connections' => null,
            'dispatch_age_seconds' => 0,
            'has_due_work' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function fetchAttemptRow(string $source, ?int $status, string $outcome): array
    {
        return [
            'created_at' => now(),
            'source_slug' => $source,
            'crawl_queue_id' => null,
            'url_hash' => hash('sha256', $source . $status . $outcome),
            'method' => 'http',
            'started_at' => now(),
            'duration_ms' => 1,
            'http_status' => $status,
            'cf_mitigated' => $status === 403 ? 'challenge' : null,
            'outcome' => $outcome,
            'error_code' => null,
            'error' => null,
        ];
    }
}
