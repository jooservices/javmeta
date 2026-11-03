<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Contracts\AlertNotifier;
use App\Models\AlertEvent;
use App\Services\Alerts\AlertChannel;
use App\Services\Alerts\AlertChannelDispatcher;
use App\Services\Alerts\AlertDispatcher;
use App\Services\Alerts\AlertSnapshotReader;
use App\Services\Notifications\NotificationTemplateService;
use App\Services\Notifications\NotificationPolicyService;
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
            'javmeta_alerts.success_rate_critical_percent' => 70,
            'javmeta_alerts.blocked_ratio_max_percent' => 10,
            'javmeta_alerts.min_attempts_in_window' => 100,
            'javmeta_alerts.pg_connections_max' => 45,
            'javmeta_alerts.dispatch_age_max_seconds' => 60,
            'javmeta_alerts.backlog_due_min_per_hot_source' => 10,
            'javmeta_alerts.cooldown_minutes' => 30,
            'javmeta_alerts.recovery_notice' => true,
            'javmeta_alerts.recovery_retry_max_attempts' => 3,
            'javmeta_alerts.history_retention_days' => 90,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_low_success_rate_is_collected_without_a_standalone_or_recovery_message(): void
    {
        $snapshot = $this->snapshot(100, 80);
        $this->useSnapshot($snapshot);

        $this->artisan('alerts:evaluate')->assertSuccessful();
        $this->artisan('alerts:evaluate')->assertSuccessful();
        self::assertCount(0, $this->sent);
        self::assertNull(AlertEvent::query()->where('rule', 'success_rate')->firstOrFail()->getAttribute('notified_at'));
        self::assertNull(AlertEvent::query()->where('rule', 'source_success_rate:javdb')->firstOrFail()->getAttribute('notified_at'));
        self::assertNull(AlertEvent::query()->firstOrFail()->getAttribute('resolved_at'));

        $this->travel(29)->minutes();
        $this->artisan('alerts:evaluate')->assertSuccessful();
        self::assertCount(0, $this->sent);

        $this->travel(1)->minutes();
        $this->artisan('alerts:evaluate')->assertSuccessful();
        self::assertCount(0, $this->sent);

        $this->useSnapshot($this->snapshot(100, 90));
        $this->travel(1)->minute();
        $this->artisan('alerts:evaluate')->assertSuccessful();
        $this->artisan('alerts:evaluate')->assertSuccessful();

        self::assertNotNull(AlertEvent::query()->where('rule', 'success_rate')->firstOrFail()->getAttribute('resolved_at'));
        self::assertNotNull(AlertEvent::query()->where('rule', 'source_success_rate:javdb')->firstOrFail()->getAttribute('resolved_at'));
        self::assertCount(0, $this->sent);
        self::assertNotNull(AlertEvent::query()->firstOrFail()->getAttribute('resolved_at'));
    }

    public function test_safety_alerts_render_database_template_and_use_its_default_severity(): void
    {
        $this->setSuccessRateTier('critical');
        app(NotificationTemplateService::class)->save(
            'crawl.success_rate_low',
            '{level_marker} {level} {success_rate}',
            'Attempts: {attempts}',
            true,
            'critical',
            null,
        );
        $this->useSnapshot($this->snapshot(100, 80));

        $this->artisan('alerts:evaluate')->assertSuccessful();

        self::assertSame('🔴 CRITICAL 80.0', $this->sent[0][0]);
        self::assertSame('Attempts: 100', $this->sent[0][1]);
        self::assertSame('crawl.success_rate_low', $this->sent[0][2]['template_key']);
        self::assertTrue($this->sent[0][2]['template_rendered']);
    }

    public function test_escalated_alert_renders_critical_title_without_an_injected_renderer(): void
    {
        $this->setSuccessRateTier('warning');
        $this->useSnapshot($this->snapshot(100, 80));

        $this->artisan('alerts:evaluate')->assertSuccessful();
        self::assertSame([], $this->sent);

        $this->travel(60)->minutes();
        $this->artisan('alerts:evaluate')->assertSuccessful();

        self::assertCount(1, $this->sent);
        self::assertStringContainsString('CRITICAL', $this->sent[0][0]);
        self::assertStringNotContainsString('WARNING', $this->sent[0][0]);
    }

    public function test_small_attempt_sample_does_not_open_an_event(): void
    {
        $this->useSnapshot($this->snapshot(99, 10));

        $this->artisan('alerts:evaluate')->assertSuccessful();

        self::assertSame([], $this->sent);
        self::assertDatabaseCount('alert_events', 0);
    }

    public function test_critical_cooldown_stops_retries_and_recovery_targets_delivered_channels(): void
    {
        $this->setSuccessRateTier('critical');
        $telegramOpenAttempts = 0;
        $telegramRecoveryAttempts = 0;
        $telegram = new class ($telegramOpenAttempts, $telegramRecoveryAttempts) implements AlertChannel {
            public function __construct(private int &$openAttempts, private int &$recoveryAttempts) {}

            public function send(string $subject, string $body, array $context = []): bool
            {
                if (($context['alert_state'] ?? '') === 'resolved') {
                    $this->recoveryAttempts++;

                    return true;
                }

                $this->openAttempts++;

                return $this->openAttempts >= 2;
            }
        };
        $webhookOpenAttempts = 0;
        $webhookRecoveryAttempts = 0;
        $webhook = new class ($webhookOpenAttempts, $webhookRecoveryAttempts) implements AlertChannel {
            public function __construct(private int &$openAttempts, private int &$recoveryAttempts) {}

            public function send(string $subject, string $body, array $context = []): bool
            {
                if (($context['alert_state'] ?? '') === 'resolved') {
                    $this->recoveryAttempts++;

                    return true;
                }

                $this->openAttempts++;

                return true;
            }
        };
        config(['javmeta_alerts.channels' => ['telegram', 'webhook']]);
        $this->bindChannels(['telegram' => $telegram, 'webhook' => $webhook]);
        $this->useSnapshot($this->snapshot(100, 80));
        $this->artisan('alerts:evaluate')->assertSuccessful();

        $this->travel(1)->minute();
        $this->artisan('alerts:evaluate')->assertSuccessful();
        self::assertSame(1, $telegramOpenAttempts);
        self::assertSame(1, $webhookOpenAttempts);

        $this->travel(29)->minutes();
        $this->artisan('alerts:evaluate')->assertSuccessful();
        self::assertSame(1, $telegramOpenAttempts);
        self::assertSame(1, $webhookOpenAttempts);

        $this->useSnapshot($this->snapshot(100, 90));
        $this->travel(1)->minute();
        $this->artisan('alerts:evaluate')->assertSuccessful();
        self::assertSame(0, $telegramRecoveryAttempts);
        self::assertSame(1, $webhookRecoveryAttempts);
    }

    public function test_small_sample_does_not_resolve_an_open_rate_alert(): void
    {
        $this->useSnapshot($this->snapshot(100, 80));
        $this->artisan('alerts:evaluate')->assertSuccessful();

        $this->travel(1)->minute();
        $this->useSnapshot($this->snapshot(99, 99));
        $this->artisan('alerts:evaluate')->assertSuccessful();

        self::assertCount(0, $this->sent);
        self::assertNull(AlertEvent::query()->where('rule', 'success_rate')->firstOrFail()->getAttribute('resolved_at'));
    }

    public function test_recovery_only_goes_to_channels_that_received_the_open_notice(): void
    {
        $this->setSuccessRateTier('critical');
        $telegramContexts = [];
        $telegram = new class ($telegramContexts) implements AlertChannel {
            /** @param list<array<string, mixed>> $contexts */
            public function __construct(private array &$contexts) {}

            public function send(string $subject, string $body, array $context = []): bool
            {
                $this->contexts[] = $context;

                return ($context['alert_state'] ?? '') === 'resolved';
            }
        };
        $webhook = new class ($this->sent) implements AlertChannel {
            /** @param list<array{string, string, array<string, mixed>}> $sent */
            public function __construct(private array &$sent) {}

            public function send(string $subject, string $body, array $context = []): bool
            {
                $this->sent[] = [$subject, $body, $context];

                return true;
            }
        };
        config(['javmeta_alerts.channels' => ['telegram', 'webhook']]);
        $this->bindChannels(['telegram' => $telegram, 'webhook' => $webhook]);
        $this->useSnapshot($this->snapshot(100, 80));
        $this->artisan('alerts:evaluate')->assertSuccessful();

        $this->travel(1)->minute();
        $this->useSnapshot($this->snapshot(100, 90));
        $this->artisan('alerts:evaluate')->assertSuccessful();

        self::assertCount(1, $telegramContexts);
        self::assertSame('open', $telegramContexts[0]['alert_state']);
        self::assertCount(2, $this->sent);
        self::assertSame('resolved', $this->sent[1][2]['alert_state']);
        self::assertSame('info', $this->sent[1][2]['severity']);
    }

    public function test_failed_recovery_retries_at_cooldown_and_stops_at_the_configured_attempt_limit(): void
    {
        $this->setSuccessRateTier('critical');
        $recoveryAttempts = 0;
        $channel = new class ($recoveryAttempts) implements AlertChannel {
            public function __construct(private int &$recoveryAttempts) {}

            public function send(string $subject, string $body, array $context = []): bool
            {
                if (($context['alert_state'] ?? '') !== 'resolved') {
                    return true;
                }

                $this->recoveryAttempts++;

                return false;
            }
        };
        $this->bindChannels(['telegram' => $channel]);
        $this->useSnapshot($this->snapshot(100, 80));
        $this->artisan('alerts:evaluate')->assertSuccessful();
        $this->travel(1)->minute();
        $this->useSnapshot($this->snapshot(100, 90));
        $this->artisan('alerts:evaluate')->assertSuccessful();
        self::assertSame(1, $recoveryAttempts);

        $event = AlertEvent::query()->where('rule', 'success_rate')->firstOrFail();
        self::assertNull($event->getAttribute('recovery_completed_at'));
        $this->travel(30)->minutes();
        $this->artisan('alerts:evaluate')->assertSuccessful();
        $this->travel(30)->minutes();
        $this->artisan('alerts:evaluate')->assertSuccessful();
        self::assertSame(3, $recoveryAttempts);
        self::assertNotNull($event->fresh()->getAttribute('recovery_completed_at'));
        $policyEvent = DB::table('notification_policy_events')->where('notification_key', 'crawl.success_rate_low')->first();
        self::assertNotNull($policyEvent?->resolved_at);

        $this->travel(30)->minutes();
        $this->artisan('alerts:evaluate')->assertSuccessful();
        self::assertSame(3, $recoveryAttempts);
    }

    public function test_superseded_recovery_does_not_resolve_a_new_episode_policy_cause(): void
    {
        $this->setSuccessRateTier('critical');
        $policyService = app(NotificationPolicyService::class);
        $policy = $policyService->get('crawl.success_rate_low');
        self::assertNotNull($policy);
        $policyService->save('crawl.success_rate_low', [...$policy, 'cooldown_minutes' => 0], null);
        config(['javmeta_alerts.recovery_retry_max_attempts' => 2]);
        $openAttempts = 0;
        $recoveryAttempts = 0;
        $channel = new class ($openAttempts, $recoveryAttempts) implements AlertChannel {
            public function __construct(private int &$openAttempts, private int &$recoveryAttempts) {}

            public function send(string $subject, string $body, array $context = []): bool
            {
                if (($context['alert_state'] ?? '') === 'resolved') {
                    $this->recoveryAttempts++;

                    return false;
                }

                $this->openAttempts++;

                return true;
            }
        };
        $this->bindChannels(['telegram' => $channel]);
        $this->useSnapshot($this->snapshot(100, 80));
        $this->artisan('alerts:evaluate')->assertSuccessful();

        $this->travel(31)->minutes();
        $this->useSnapshot($this->snapshot(100, 90));
        $this->artisan('alerts:evaluate')->assertSuccessful();
        $superseded = AlertEvent::query()->where('rule', 'success_rate')->firstOrFail();
        self::assertNotNull($superseded->getAttribute('resolved_at'));
        self::assertNull($superseded->getAttribute('recovery_completed_at'));
        self::assertSame(1, $recoveryAttempts);

        $this->travel(31)->minutes();
        $this->useSnapshot($this->snapshot(100, 80));
        $this->artisan('alerts:evaluate')->assertSuccessful();

        $active = AlertEvent::query()->where('rule', 'success_rate')->whereNull('resolved_at')->firstOrFail();
        self::assertNotNull($superseded->fresh()->getAttribute('recovery_completed_at'));
        self::assertSame(1, $recoveryAttempts);
        self::assertSame(2, $openAttempts);
        self::assertNull(DB::table('notification_policy_events')->where('notification_key', 'crawl.success_rate_low')->value('resolved_at'));
        self::assertNull($active->getAttribute('resolved_at'));
    }

    public function test_alert_evaluation_lock_prevents_parallel_episode_creation(): void
    {
        $this->useSnapshot($this->snapshot(100, 80));
        $lock = Cache::lock('javmeta:alerts:evaluation', 180);
        self::assertTrue($lock->get());

        try {
            $this->artisan('alerts:evaluate')->assertSuccessful();
        } finally {
            $lock->release();
        }

        self::assertDatabaseCount('alert_events', 0);
        self::assertSame([], $this->sent);
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

    /** @param array<string, AlertChannel> $channels */
    private function bindChannels(array $channels): void
    {
        $dispatcher = new AlertChannelDispatcher($channels);
        $this->app->instance(AlertChannelDispatcher::class, $dispatcher);
        $this->app->instance(AlertDispatcher::class, new AlertDispatcher(
            $this->app->make(AlertNotifier::class),
            $dispatcher,
        ));
    }

    private function setSuccessRateTier(string $tier): void
    {
        $service = app(NotificationPolicyService::class);
        $policy = $service->get('crawl.success_rate_low');
        self::assertNotNull($policy);
        $service->save('crawl.success_rate_low', [...$policy, 'tier' => $tier, 'send_resolved' => true], null);
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
