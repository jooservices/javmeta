<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Contracts\AlertAuditNotifier;
use App\Contracts\AlertNotifier;
use App\Notifications\CompositeAlertNotifier;
use Tests\TestCase;

final class CompositeAlertNotifierTest extends TestCase
{
    public function test_successful_audit_write_does_not_count_as_external_delivery(): void
    {
        $audit = new class implements AlertAuditNotifier {
            public function notify(string $subject, string $body, array $context = []): bool
            {
                return true;
            }
        };
        $external = new class implements AlertNotifier {
            public function notify(string $subject, string $body, array $context = []): bool
            {
                return false;
            }
        };

        self::assertFalse((new CompositeAlertNotifier([$audit, $external]))->notify('Subject', 'Body'));
    }

    public function test_successful_external_delivery_counts_even_when_audit_write_fails(): void
    {
        $audit = new class implements AlertAuditNotifier {
            public function notify(string $subject, string $body, array $context = []): bool
            {
                return false;
            }
        };
        $external = new class implements AlertNotifier {
            public function notify(string $subject, string $body, array $context = []): bool
            {
                return true;
            }
        };

        self::assertTrue((new CompositeAlertNotifier([$audit, $external]))->notify('Subject', 'Body'));
    }
}
