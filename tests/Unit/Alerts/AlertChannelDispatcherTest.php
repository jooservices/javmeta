<?php

declare(strict_types=1);

namespace Tests\Unit\Alerts;

use App\Services\Alerts\AlertChannel;
use App\Services\Alerts\AlertChannelDispatcher;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class AlertChannelDispatcherTest extends TestCase
{
    public function test_channel_failure_is_logged_and_does_not_prevent_later_channels(): void
    {
        config(['javmeta_alerts.channels' => ['telegram', 'webhook']]);
        Log::shouldReceive('warning')->once()->withArgs(
            static fn(string $message, array $context): bool
            => $message === 'javmeta.alert_channel_failed'
            && $context['channel'] === 'telegram'
            && $context['exception'] === 'RuntimeException',
        );

        $delivered = false;
        $channels = new AlertChannelDispatcher([
            'telegram' => new class implements AlertChannel {
                public function send(string $subject, string $body, array $context = []): bool
                {
                    throw new \RuntimeException('private channel detail');
                }
            },
            'webhook' => new class ($delivered) implements AlertChannel {
                public function __construct(private bool &$delivered) {}

                public function send(string $subject, string $body, array $context = []): bool
                {
                    $this->delivered = true;

                    return true;
                }
            },
        ]);

        self::assertSame(['webhook'], $channels->dispatch('Cảnh báo', 'Nội dung'));
        self::assertTrue($delivered);
    }

    public function test_dispatch_uses_only_enabled_channels(): void
    {
        config(['javmeta_alerts.channels' => ['webhook']]);
        $telegramCalled = false;
        $webhookCalled = false;
        $channels = new AlertChannelDispatcher([
            'telegram' => $this->channel($telegramCalled),
            'webhook' => $this->channel($webhookCalled),
        ]);

        $channels->dispatch('Cảnh báo', 'Nội dung');

        self::assertFalse($telegramCalled);
        self::assertTrue($webhookCalled);
    }

    private function channel(bool &$called): AlertChannel
    {
        return new class ($called) implements AlertChannel {
            public function __construct(private bool &$called) {}

            public function send(string $subject, string $body, array $context = []): bool
            {
                $this->called = true;

                return true;
            }
        };
    }
}
