<?php

declare(strict_types=1);

namespace Tests\Unit\Alerts;

use App\Services\Alerts\AlertMessageFormatter;
use Tests\TestCase;

final class AlertMessageFormatterTest extends TestCase
{
    public function test_supported_safety_rules_map_to_database_template_keys(): void
    {
        $formatter = new AlertMessageFormatter();

        self::assertSame('crawl.success_rate_low', $formatter->templateKey('success_rate'));
        self::assertSame('crawl.blocked_ratio_high', $formatter->templateKey('blocked_ratio'));
        self::assertSame('crawl.source_success_low', $formatter->templateKey('source_success_rate:javbus'));
        self::assertSame('database.connections_high', $formatter->templateKey('postgres_connections'));
        self::assertSame('crawl.dispatch_delayed', $formatter->templateKey('dispatch_age'));
        self::assertSame('crawl.backlog_by_source_high', $formatter->templateKey('backlog_depth:javbus'));
        self::assertNull($formatter->templateKey('unknown_rule'));
    }
}
