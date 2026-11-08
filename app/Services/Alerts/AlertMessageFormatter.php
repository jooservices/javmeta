<?php

declare(strict_types=1);

namespace App\Services\Alerts;

/** Maps evaluator rules to their database-backed notification template keys. */
final class AlertMessageFormatter
{
    public function templateKey(string $rule): ?string
    {
        if (str_starts_with($rule, 'source_success_rate:')) {
            return 'crawl.source_success_low';
        }
        if (str_starts_with($rule, 'backlog_depth:')) {
            return 'crawl.backlog_by_source_high';
        }

        return match ($rule) {
            'success_rate' => 'crawl.success_rate_low',
            'success_rate_critical' => 'crawl.success_rate_critical',
            'blocked_ratio' => 'crawl.blocked_ratio_high',
            'postgres_connections' => 'database.connections_high',
            'dispatch_age' => 'crawl.dispatch_delayed',
            default => null,
        };
    }
}
