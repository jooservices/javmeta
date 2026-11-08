<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Services\Alerts\AlertChannel;
use App\Services\Alerts\AlertChannelDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Failure branches of alerts:test (the success path lives in AlertsCommandsTest). */
final class AlertsTestCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fails_when_no_alert_channel_is_enabled(): void
    {
        config(['javmeta_alerts.channels' => []]);
        $this->app->instance(AlertChannelDispatcher::class, new AlertChannelDispatcher([]));

        $this->artisan('alerts:test')
            ->expectsOutputToContain('Chưa bật kênh nhận cảnh báo nào.')
            ->assertFailed();
    }

    public function test_it_fails_and_names_the_channel_that_did_not_deliver(): void
    {
        config(['javmeta_alerts.channels' => ['telegram', 'webhook']]);
        $this->app->instance(AlertChannelDispatcher::class, new AlertChannelDispatcher([
            'telegram' => $this->channel(true),
            'webhook' => $this->channel(false),
        ]));

        $this->artisan('alerts:test')
            ->expectsOutputToContain('telegram: đã gửi.')
            ->expectsOutputToContain('webhook: gửi thất bại hoặc chưa được cấu hình.')
            ->assertFailed();
    }

    private function channel(bool $delivers): AlertChannel
    {
        return new class ($delivers) implements AlertChannel {
            public function __construct(private readonly bool $delivers) {}

            public function send(string $subject, string $body, array $context = []): bool
            {
                return $this->delivers;
            }
        };
    }
}
