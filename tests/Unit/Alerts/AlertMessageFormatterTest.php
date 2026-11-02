<?php

declare(strict_types=1);

namespace Tests\Unit\Alerts;

use App\Services\Alerts\AlertMessageFormatter;
use Tests\TestCase;

final class AlertMessageFormatterTest extends TestCase
{
    public function test_idle_dispatch_recovery_reports_no_due_work_and_info_severity(): void
    {
        $message = (new AlertMessageFormatter())->format('dispatch_age', [
            'severity' => 'critical',
            'value' => 0,
            'threshold' => 60,
            'context' => ['idle' => true],
        ], true);

        self::assertSame('info', $message['context']['severity']);
        self::assertStringContainsString('không còn việc crawl đến hạn', $message['body']);
        self::assertStringNotContainsString('0.0 giây', $message['body']);
    }
}
