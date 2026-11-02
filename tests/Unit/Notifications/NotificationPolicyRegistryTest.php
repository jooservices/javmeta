<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Services\Notifications\NotificationPolicyRegistry;
use App\Services\Notifications\NotificationRegistry;
use PHPUnit\Framework\TestCase;

final class NotificationPolicyRegistryTest extends TestCase
{
    public function test_every_template_has_a_complete_policy_default(): void
    {
        $templates = (new NotificationRegistry())->all();
        $policies = (new NotificationPolicyRegistry(new NotificationRegistry()))->all();

        self::assertSame(array_keys($templates), array_keys($policies));
        foreach ($policies as $key => $policy) {
            self::assertContains($policy['tier'], ['critical', 'warning', 'notice', 'info'], $key);
            self::assertGreaterThanOrEqual(0, $policy['cooldown_minutes'], $key);
            self::assertGreaterThanOrEqual(0, $policy['escalation_minutes'], $key);
            self::assertIsBool($policy['include_periodic_report'], $key);
            self::assertIsBool($policy['include_daily_report'], $key);
            self::assertIsBool($policy['send_resolved'], $key);
        }
    }

    public function test_owner_decision_defaults_cover_alerts_reports_and_entity_rules(): void
    {
        $policies = (new NotificationPolicyRegistry(new NotificationRegistry()))->all();

        self::assertSame(['critical', 120], [$policies['crawl.stalled']['tier'], $policies['crawl.stalled']['cooldown_minutes']]);
        self::assertSame(['warning', 60], [$policies['crawl.success_rate_low']['tier'], $policies['crawl.success_rate_low']['escalation_minutes']]);
        self::assertSame(['info', false, true], [$policies['source.exhausted']['tier'], $policies['source.exhausted']['include_periodic_report'], $policies['source.exhausted']['include_daily_report']]);
        self::assertSame(['notice', 360], [$policies['egress.changed']['tier'], $policies['egress.changed']['cooldown_minutes']]);
        self::assertSame(720, $policies['backup.stale']['cooldown_minutes']);
        self::assertSame(['warning', 60], [$policies['database.connections_high']['tier'], $policies['database.connections_high']['escalation_minutes']]);
        self::assertSame(['warning', 60], [$policies['sentry.new_critical_group']['tier'], $policies['sentry.new_critical_group']['escalation_minutes']]);
        self::assertSame(['critical', 120], [$policies['resource.disk_critical']['tier'], $policies['resource.disk_critical']['cooldown_minutes']]);
        self::assertSame('warning', $policies['crawl.success_rate_low.performers']['tier']);
        self::assertSame('info', $policies['report.periodic']['tier']);
        self::assertSame('info', $policies['report.daily']['tier']);
        self::assertFalse($policies['report.periodic']['include_periodic_report']);
        self::assertFalse($policies['report.daily']['include_daily_report']);
    }
}
