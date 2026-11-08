<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Alerts;

use App\Services\Alerts\AlertTemplateValues;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(AlertTemplateValues::class)]
final class AlertTemplateValuesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['javmeta_alerts.window_minutes' => 15, 'javmeta_alerts.blocked_ratio_max_percent' => 10]);
    }

    public function test_success_rate_carries_the_blocked_ratio_context_with_blank_defaults(): void
    {
        $values = $this->values('success_rate', 80.0, 85.0, ['attempts' => 400, 'worst_sources' => 'a 50%']);

        self::assertSame([
            'success_rate' => 80.0,
            'minimum_rate' => 85.0,
            'window_minutes' => 15,
            'blocked_ratio' => '',
            'maximum_blocked_ratio' => 10,
            'attempts' => 400,
            'worst_sources' => 'a 50%',
        ], $values);
    }

    public function test_the_critical_success_rate_has_a_threshold_and_the_context_window(): void
    {
        self::assertSame(
            ['success_rate' => 60.0, 'threshold' => 70.0, 'window_minutes' => 30],
            $this->values('success_rate_critical', 60.0, 70.0, ['window_minutes' => 30]),
        );
    }

    public function test_the_window_never_drops_below_one_minute(): void
    {
        self::assertSame(1, $this->values('blocked_ratio', 12.0, 10.0, ['window_minutes' => 0])['window_minutes']);
    }

    public function test_blocked_ratio_values(): void
    {
        self::assertSame(
            ['blocked_ratio' => 12.0, 'maximum_ratio' => 10.0, 'window_minutes' => 15, 'attempts' => '', 'worst_sources' => ''],
            $this->values('blocked_ratio', 12.0, 10.0),
        );
    }

    public function test_a_source_rule_names_its_source_from_the_context_or_from_the_rule_name(): void
    {
        $fromContext = $this->values('source_success_rate:a', 50.0, 85.0, ['source_slug' => 'ctx', 'attempts' => 120]);
        $fromRule = $this->values('source_success_rate:from-rule', 50.0, 85.0);

        self::assertSame('ctx', $fromContext['source_slug']);
        self::assertSame(120, $fromContext['attempts']);
        self::assertSame('from-rule', $fromRule['source_slug']);
        self::assertSame(['source_slug', 'success_rate', 'minimum_rate', 'window_minutes', 'attempts'], array_keys($fromRule));
    }

    public function test_postgres_connections_values(): void
    {
        self::assertSame(['connection_count' => 60, 'threshold' => 45], $this->values('postgres_connections', 60, 45));
    }

    public function test_dispatch_age_describes_each_of_its_four_states(): void
    {
        $waiting = $this->values('dispatch_age', 1234, 60, ['due_items' => 7]);
        self::assertSame('due work is waiting for dispatch', $waiting['dispatch_state']);
        self::assertSame('1,234 seconds ago', $waiting['dispatch_age']);
        self::assertSame(60, $waiting['threshold_seconds']);
        self::assertSame(7, $waiting['due_items']);

        self::assertSame('dispatch has resumed', $this->values('dispatch_age', 5, 60, [], true)['dispatch_state']);

        $missing = $this->values('dispatch_age', -1, 60, ['missing' => true]);
        self::assertSame('no dispatch has been recorded', $missing['dispatch_state']);
        self::assertSame('not recorded', $missing['dispatch_age']);

        $idle = $this->values('dispatch_age', 0, 60, ['idle' => true, 'missing' => true]);
        self::assertSame('no crawl work is due', $idle['dispatch_state']);
        self::assertSame('not applicable', $idle['dispatch_age']);
        self::assertSame('', $idle['due_items']);
    }

    public function test_backlog_depth_names_its_source_and_an_unknown_rule_has_no_values(): void
    {
        self::assertSame(
            ['source_slug' => 'hot', 'due_items' => 3, 'minimum_items' => 10],
            $this->values('backlog_depth:hot', 3, 10),
        );
        self::assertSame('ctx', $this->values('backlog_depth:hot', 3, 10, ['source_slug' => 'ctx'])['source_slug']);
        self::assertSame([], $this->values('no_such_rule', 1, 1));
    }

    /** @param array<string, mixed> $context */
    private function values(string $rule, float|int $value, float|int $threshold, array $context = [], bool $resolved = false): array
    {
        return (new AlertTemplateValues())->for($rule, ['severity' => 'warning', 'value' => $value, 'threshold' => $threshold, 'context' => $context], $resolved);
    }
}
