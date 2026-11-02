<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Observability\MetricStreams;
use App\Observability\NotificationMetrics;
use App\Observability\NotificationPolicyMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NotificationPolicyMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_counters_are_readable_from_the_notification_metrics_command(): void
    {
        $metrics = app(NotificationPolicyMetrics::class);
        $metrics->sent('critical', 'crawl.stalled');
        $metrics->suppressed('cooldown');

        $points = collect(app(NotificationMetrics::class)->points())->keyBy('name');

        self::assertSame(1, $points[MetricStreams::NAMES['notifications_sent_total']]['value']);
        self::assertSame(['tier' => 'critical', 'key' => 'crawl.stalled'], $points[MetricStreams::NAMES['notifications_sent_total']]['labels']);
        self::assertSame(1, $points[MetricStreams::NAMES['notifications_suppressed_total']]['value']);
        self::assertSame(['reason' => 'cooldown'], $points[MetricStreams::NAMES['notifications_suppressed_total']]['labels']);

        $this->artisan('notifications:metrics')
            ->expectsOutputToContain('javmeta_notifications_sent_total')
            ->expectsOutputToContain('javmeta_notifications_suppressed_total')
            ->assertSuccessful();
    }
}
