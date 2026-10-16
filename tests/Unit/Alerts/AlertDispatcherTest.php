<?php

declare(strict_types=1);

namespace Tests\Unit\Alerts;

use App\Contracts\AlertNotifier;
use App\Services\Alerts\AlertDispatcher;
use Tests\TestCase;

final class AlertDispatcherTest extends TestCase
{
    private function notifierSpy(): AlertNotifier
    {
        return new class implements AlertNotifier {
            public bool $called = false;

            public function notify(string $subject, string $body, array $context = []): void
            {
                $this->called = true;
            }
        };
    }

    public function test_send_skips_when_alerts_disabled(): void
    {
        config(['javmeta_alerts.enabled' => false]);

        $notifier = $this->notifierSpy();
        $result = (new AlertDispatcher($notifier))->send('k', 's', 'b', [], 0);

        self::assertFalse($result);
        self::assertFalse($notifier->called);
    }

    public function test_send_notifies_when_alerts_enabled(): void
    {
        config(['javmeta_alerts.enabled' => true]);

        $notifier = $this->notifierSpy();
        $result = (new AlertDispatcher($notifier))->send('k', 's', 'b', [], 0);

        self::assertTrue($result);
        self::assertTrue($notifier->called);
    }
}
