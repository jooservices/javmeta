<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use App\Models\AlertEvent;
use App\Models\Source;
use App\Observability\MetricStreams;
use App\Services\Alerts\AlertMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AlertMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_metrics_publish_firing_state_and_retained_event_counts(): void
    {
        Source::factory()->create(['slug' => 'javdb', 'enabled' => true]);
        AlertEvent::query()->create([
            'rule' => 'success_rate',
            'severity' => 'warning',
            'value' => 80,
            'threshold' => 85,
            'started_at' => now()->subMinute(),
            'notified_at' => now(),
        ]);
        AlertEvent::query()->create([
            'rule' => 'dispatch_age',
            'severity' => 'warning',
            'value' => 61,
            'threshold' => 60,
            'started_at' => now()->subMinutes(2),
            'resolved_at' => now()->subMinute(),
            'notified_at' => now()->subMinutes(2),
        ]);

        $points = app(AlertMetrics::class)->points();
        $firing = array_values(array_filter($points, static fn(array $point): bool => $point['name'] === MetricStreams::NAMES['alert_firing']));
        $counts = array_values(array_filter($points, static fn(array $point): bool => $point['name'] === MetricStreams::NAMES['alert_event_count']));

        self::assertContains(['name' => 'javmeta_alert_firing', 'value' => 1, 'labels' => ['rule' => 'success_rate']], $firing);
        self::assertContains(['name' => 'javmeta_alert_firing', 'value' => 0, 'labels' => ['rule' => 'source_success_rate:javdb']], $firing);
        self::assertContains(['name' => 'javmeta_alert_event_count', 'value' => 1, 'labels' => ['state' => 'firing', 'severity' => 'warning']], $counts);
        self::assertContains(['name' => 'javmeta_alert_event_count', 'value' => 1, 'labels' => ['state' => 'resolved', 'severity' => 'warning']], $counts);
    }
}
