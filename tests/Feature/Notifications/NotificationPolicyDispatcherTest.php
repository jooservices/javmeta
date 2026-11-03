<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Services\Notifications\NotificationPolicyDispatcher;
use App\Services\Notifications\NotificationPolicyService;
use App\Services\Settings\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;
use Tests\TestCase;

final class NotificationPolicyDispatcherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(ConfigStore::class, new FakeConfigStore());
        $this->app->forgetInstance(SettingsService::class);
        CarbonImmutable::setTestNow('2026-11-03 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_warning_is_digest_only_then_escalates_at_the_configured_age(): void
    {
        $dispatcher = app(NotificationPolicyDispatcher::class);
        $first = $dispatcher->prepare('crawl.success_rate_low', ['rule' => 'success_rate', 'value' => 82, 'threshold' => 85]);
        self::assertFalse($first['allowed']);
        self::assertSame('warning_digest', $first['reason']);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(60));
        $escalated = $dispatcher->prepare('crawl.success_rate_low', ['rule' => 'success_rate', 'value' => 82, 'threshold' => 85]);
        self::assertTrue($escalated['allowed']);
        self::assertSame('critical', $escalated['tier']);
        $dispatcher->recordSent($escalated);

        $repeat = $dispatcher->prepare('crawl.success_rate_low', ['rule' => 'success_rate', 'value' => 81, 'threshold' => 85]);
        self::assertFalse($repeat['allowed']);
        self::assertSame('cooldown', $repeat['reason']);
    }

    public function test_critical_cause_is_sent_once_per_configured_cooldown(): void
    {
        $dispatcher = app(NotificationPolicyDispatcher::class);
        $first = $dispatcher->prepare('crawl.stalled', ['rule' => 'crawl_stalled']);
        self::assertTrue($first['allowed']);
        self::assertSame('critical', $first['tier']);
        $dispatcher->recordSent($first);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(119));
        $repeat = $dispatcher->prepare('crawl.stalled', ['rule' => 'crawl_stalled']);
        self::assertFalse($repeat['allowed']);
        self::assertSame('cooldown', $repeat['reason']);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(1));
        $due = $dispatcher->prepare('crawl.stalled', ['rule' => 'crawl_stalled']);
        self::assertTrue($due['allowed']);
    }

    public function test_periodic_and_daily_digest_sends_obey_their_configured_cooldowns(): void
    {
        $dispatcher = app(NotificationPolicyDispatcher::class);
        foreach ([['report.periodic', 'periodic_report', 120], ['report.daily', 'daily_report', 1440]] as [$key, $cause, $minutes]) {
            $decision = $dispatcher->prepare($key, [
                'notification_cause' => $cause,
                'notification_digest' => true,
            ]);
            self::assertTrue($decision['allowed']);
            $dispatcher->recordSent($decision);

            $repeat = $dispatcher->prepare($key, [
                'notification_cause' => $cause,
                'notification_digest' => true,
            ]);
            self::assertFalse($repeat['allowed']);
            self::assertSame('cooldown', $repeat['reason']);

            CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes($minutes));
            $due = $dispatcher->prepare($key, [
                'notification_cause' => $cause,
                'notification_digest' => true,
            ]);
            self::assertTrue($due['allowed']);
            $dispatcher->release($due);
        }
    }

    public function test_quiet_mode_allows_only_critical_and_security_events(): void
    {
        app(SettingsService::class)->set('notifications.quiet_mode', true);
        $service = app(NotificationPolicyService::class);
        $notice = $service->get('node.joined');
        $security = $service->get('security.guest_mode_toggled');
        self::assertNotNull($notice);
        self::assertNotNull($security);
        $service->save('node.joined', [...$notice, 'enabled' => true], null);
        $service->save('security.guest_mode_toggled', [...$security, 'enabled' => true], null);

        $dispatcher = app(NotificationPolicyDispatcher::class);
        self::assertSame('quiet_mode', $dispatcher->prepare('node.joined', ['notification_cause' => 'node:worker-1'])['reason']);
        $critical = $dispatcher->prepare('crawl.stalled', ['rule' => 'crawl_stalled']);
        self::assertTrue($critical['allowed']);
        $securityEvent = $dispatcher->prepare('security.guest_mode_toggled', ['notification_cause' => 'guest-mode']);
        self::assertTrue($securityEvent['allowed']);

        $warning = $dispatcher->prepare('crawl.success_rate_low', [
            'rule' => 'success_rate',
            'value' => 82,
            'threshold' => 85,
        ]);
        self::assertFalse($warning['allowed']);
        self::assertSame('quiet_mode', $warning['reason']);
        self::assertSame('crawl.success_rate_low', app(NotificationPolicyService::class)->openWarnings()[0]['key']);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(60));
        $escalated = $dispatcher->prepare('crawl.success_rate_low', [
            'rule' => 'success_rate',
            'value' => 82,
            'threshold' => 85,
        ]);
        self::assertTrue($escalated['allowed']);
        self::assertSame('critical', $escalated['tier']);
    }

    public function test_resolved_is_sent_only_after_a_critical_message_for_the_same_cause(): void
    {
        $dispatcher = app(NotificationPolicyDispatcher::class);
        $resolvedContext = ['alert_state' => 'resolved', 'rule' => 'crawl_stalled'];
        self::assertSame('resolved_without_critical', $dispatcher->prepare('crawl.stalled', $resolvedContext)['reason']);

        $critical = $dispatcher->prepare('crawl.stalled', ['rule' => 'crawl_stalled']);
        $dispatcher->recordSent($critical);
        $resolved = $dispatcher->prepare('crawl.stalled', $resolvedContext);
        self::assertTrue($resolved['allowed']);
        self::assertTrue($resolved['resolved']);
        $dispatcher->recordSent($resolved);

        $duplicate = $dispatcher->prepare('crawl.stalled', $resolvedContext);
        self::assertFalse($duplicate['allowed']);
        self::assertSame('already_resolved', $duplicate['reason']);
    }

    public function test_backup_recovery_uses_the_original_stale_or_missing_cause(): void
    {
        $dispatcher = app(NotificationPolicyDispatcher::class);
        foreach (['backup:stale' => 'backup.stale', 'backup:missing' => 'backup.manifest_missing'] as $cause => $key) {
            $open = $dispatcher->prepare($key, ['notification_cause' => $cause]);
            self::assertTrue($open['allowed']);
            $dispatcher->recordSent($open);

            $resolved = $dispatcher->prepare('backup.recovered', [
                'alert_state' => 'resolved',
                'notification_cause' => $cause,
            ]);
            self::assertTrue($resolved['allowed']);
            self::assertSame($key, $resolved['policy_key']);
            $dispatcher->recordSent($resolved);
        }
    }

    public function test_only_opted_in_warning_events_appear_in_the_next_digest(): void
    {
        $dispatcher = app(NotificationPolicyDispatcher::class);
        $dispatcher->prepare('crawl.success_rate_low', ['rule' => 'success_rate', 'value' => 82, 'threshold' => 85]);
        $warnings = app(NotificationPolicyService::class)->openWarnings();

        self::assertCount(1, $warnings);
        self::assertSame('crawl.success_rate_low', $warnings[0]['key']);
        self::assertSame(82, (int) $warnings[0]['value']);
        self::assertSame(85, (int) $warnings[0]['threshold']);
    }

    public function test_notice_event_is_sent_only_once_for_the_same_event(): void
    {
        $service = app(NotificationPolicyService::class);
        $notice = $service->get('node.joined');
        self::assertNotNull($notice);
        $service->save('node.joined', [...$notice, 'enabled' => true], null);

        $dispatcher = app(NotificationPolicyDispatcher::class);
        $context = ['notification_cause' => 'node:worker-1'];
        $first = $dispatcher->prepare('node.joined', $context);
        self::assertTrue($first['allowed']);
        $dispatcher->recordSent($first);

        $repeat = $dispatcher->prepare('node.joined', $context);
        self::assertFalse($repeat['allowed']);
        self::assertSame('duplicate_event', $repeat['reason']);
    }

    public function test_egress_notice_uses_a_per_node_cooldown_and_can_repeat_after_six_hours(): void
    {
        $service = app(NotificationPolicyService::class);
        $notice = $service->get('egress.changed');
        self::assertNotNull($notice);
        $service->save('egress.changed', [...$notice, 'enabled' => true], null);

        $dispatcher = app(NotificationPolicyDispatcher::class);
        $first = $dispatcher->prepare('egress.changed', ['node' => 'worker-1', 'current_ip' => '203.0.113.10']);
        self::assertTrue($first['allowed']);
        $dispatcher->recordSent($first);

        $sameNode = $dispatcher->prepare('egress.changed', ['node' => 'worker-1', 'current_ip' => '203.0.113.11']);
        self::assertFalse($sameNode['allowed']);
        self::assertSame('cooldown', $sameNode['reason']);

        $otherNode = $dispatcher->prepare('egress.changed', ['node' => 'worker-2', 'current_ip' => '203.0.113.12']);
        self::assertTrue($otherNode['allowed']);
        $dispatcher->recordSent($otherNode);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(360));
        $afterCooldown = $dispatcher->prepare('egress.changed', ['node' => 'worker-1', 'current_ip' => '203.0.113.13']);
        self::assertTrue($afterCooldown['allowed']);
    }
}
