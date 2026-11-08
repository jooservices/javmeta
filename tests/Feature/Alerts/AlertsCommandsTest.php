<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Services\Alerts\AlertChannel;
use App\Services\Alerts\AlertChannelDispatcher;
use App\Models\AlertEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AlertsCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_alerts_test_sends_to_each_enabled_channel_even_when_alerts_are_disabled(): void
    {
        config(['javmeta_alerts.enabled' => false, 'javmeta_alerts.channels' => ['telegram', 'webhook']]);
        $sent = [];
        $this->app->instance(AlertChannelDispatcher::class, new AlertChannelDispatcher([
            'telegram' => $this->channel('telegram', $sent),
            'webhook' => $this->channel('webhook', $sent),
        ]));

        $this->artisan('alerts:test')
            ->expectsOutputToContain('telegram: đã gửi.')
            ->expectsOutputToContain('webhook: đã gửi.')
            ->assertSuccessful();

        self::assertSame(['telegram', 'webhook'], $sent);
    }

    public function test_alerts_list_reports_empty_history_and_accepts_hours_option(): void
    {
        $this->artisan('alerts:list', ['--hours' => 2])
            ->expectsOutputToContain('No alert events in the last 2 hour(s).')
            ->assertSuccessful();
    }

    public function test_alerts_list_includes_an_old_episode_resolved_inside_the_requested_window(): void
    {
        $this->travelTo('2026-11-02T12:34:00Z');
        AlertEvent::query()->create([
            'rule' => 'old_success_rate',
            'severity' => 'warning',
            'value' => 80,
            'threshold' => 85,
            'started_at' => now()->subDays(2),
            'resolved_at' => now()->subHour(),
        ]);

        $this->artisan('alerts:list', ['--hours' => 2])
            ->expectsOutputToContain('old_success_rate')
            ->assertSuccessful();
    }

    public function test_alerts_metrics_command_displays_exported_values_for_ssh_inspection(): void
    {
        $this->artisan('alerts:metrics')
            ->expectsOutputToContain('javmeta_alert_firing')
            ->expectsOutputToContain('javmeta_alert_event_count')
            ->assertSuccessful();
    }

    /** @param list<string> $sent */
    private function channel(string $name, array &$sent): AlertChannel
    {
        return new class ($name, $sent) implements AlertChannel {
            /** @param list<string> $sent */
            public function __construct(private string $name, private array &$sent) {}

            public function send(string $subject, string $body, array $context = []): bool
            {
                $this->sent[] = $this->name;

                return true;
            }
        };
    }
}
