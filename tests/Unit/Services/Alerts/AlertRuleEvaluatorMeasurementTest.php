<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Alerts;

use App\Services\Alerts\AlertRuleEvaluator;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(AlertRuleEvaluator::class)]
final class AlertRuleEvaluatorMeasurementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'javmeta_alerts.min_attempts_in_window' => 100,
            'javmeta_alerts.success_rate_min_percent' => 85,
            'javmeta_alerts.success_rate_critical_percent' => 70,
            'javmeta_alerts.blocked_ratio_max_percent' => 10,
            'javmeta_alerts.pg_connections_max' => 45,
            'javmeta_alerts.dispatch_age_max_seconds' => 60,
            'javmeta_alerts.backlog_due_min_per_hot_source' => 10,
            'javmeta_alerts.window_minutes' => 15,
        ]);
    }

    public function test_the_global_rates_report_the_measured_value_against_the_configured_threshold(): void
    {
        $snapshot = $this->snapshot(['a' => [200, 150, 30], 'b' => [100, 90, 0]]);

        $success = $this->evaluator()->measurement('success_rate', $snapshot);
        self::assertEqualsWithDelta(80.0, $success['value'], 0.01);
        self::assertSame(85.0, $success['threshold']);
        self::assertSame(15, $success['context']['window_minutes']);
        self::assertSame(['a', 'b'], $success['context']['top_sources']);

        $critical = $this->evaluator()->measurement('success_rate_critical', $snapshot);
        self::assertSame(70.0, $critical['threshold']);

        $blocked = $this->evaluator()->measurement('blocked_ratio', $snapshot);
        self::assertEqualsWithDelta(10.0, $blocked['value'], 0.01);
        self::assertSame(10.0, $blocked['threshold']);
    }

    public function test_a_global_rate_needs_the_minimum_number_of_attempts(): void
    {
        $snapshot = $this->snapshot(['a' => [99, 10, 10]]);

        self::assertNull($this->evaluator()->measurement('success_rate', $snapshot));
        self::assertNull($this->evaluator()->measurement('blocked_ratio', $snapshot));
    }

    public function test_a_per_source_rate_is_measured_only_for_an_enabled_source_with_enough_attempts(): void
    {
        $snapshot = $this->snapshot(['a' => [200, 100, 0], 'thin' => [10, 1, 0]], enabled: ['a', 'thin']);

        $measured = $this->evaluator()->measurement('source_success_rate:a', $snapshot);
        self::assertEqualsWithDelta(50.0, $measured['value'], 0.01);
        self::assertSame(['source_slug' => 'a', 'window_minutes' => 15, 'attempts' => 200], $measured['context']);

        self::assertNull($this->evaluator()->measurement('source_success_rate:thin', $snapshot), 'Too few attempts.');
        self::assertNull($this->evaluator()->measurement('source_success_rate:unknown', $snapshot), 'No stats for it.');
        self::assertNull($this->evaluator()->measurement('source_success_rate:disabled', $this->snapshot(['disabled' => [200, 1, 0]], enabled: [])), 'Not enabled.');
        self::assertNull($this->evaluator()->measurement('source_success_rate', $snapshot), 'A source name is required.');
    }

    public function test_postgres_connections_and_dispatch_age_are_measured(): void
    {
        $busy = $this->snapshot([], connections: 60, dispatchAge: 120, hasDueWork: true, due: ['a' => 3, 'b' => 4]);

        self::assertSame(['value' => 60, 'threshold' => 45, 'context' => []], $this->evaluator()->measurement('postgres_connections', $busy));
        self::assertNull($this->evaluator()->measurement('postgres_connections', $this->snapshot([])), 'No connection figure.');

        $age = $this->evaluator()->measurement('dispatch_age', $busy);
        self::assertSame(120, $age['value']);
        self::assertSame(60, $age['threshold']);
        self::assertSame(['idle' => false, 'missing' => false, 'due_items' => 7], $age['context']);

        $missing = $this->evaluator()->measurement('dispatch_age', $this->snapshot([], hasDueWork: true, dispatchAge: null));
        self::assertSame(-1, $missing['value']);
        self::assertTrue($missing['context']['missing']);

        $idle = $this->evaluator()->measurement('dispatch_age', $this->snapshot([], hasDueWork: false, dispatchAge: 500));
        self::assertSame(0, $idle['value']);
        self::assertTrue($idle['context']['idle']);
    }

    public function test_backlog_depth_is_measured_only_for_a_hot_source(): void
    {
        $snapshot = $this->snapshot([], hot: ['hot'], due: ['hot' => 4, 'cold' => 9]);

        self::assertSame(['value' => 4, 'threshold' => 10, 'context' => ['source_slug' => 'hot']], $this->evaluator()->measurement('backlog_depth:hot', $snapshot));
        self::assertSame(0, $this->evaluator()->measurement('backlog_depth:hot', $this->snapshot([], hot: ['hot']))['value'], 'No due work counts as zero.');
        self::assertNull($this->evaluator()->measurement('backlog_depth:cold', $snapshot));
        self::assertNull($this->evaluator()->measurement('backlog_depth', $snapshot));
    }

    public function test_an_unknown_rule_has_no_measurement(): void
    {
        self::assertNull($this->evaluator()->measurement('no_such_rule', $this->snapshot([])));
    }

    private function evaluator(): AlertRuleEvaluator
    {
        return new AlertRuleEvaluator();
    }

    /**
     * @param array<string, array{int, int, int}> $attempts source => [attempts, successes, blocked]
     * @param list<string>|null $enabled
     * @param list<string> $hot
     * @param array<string, int> $due
     * @return array{attempts_by_source: array<string, array{attempts: int, successes: int, blocked: int}>, enabled_sources: list<string>, hot_sources: list<string>, due_by_source: array<string, int>, postgres_connections: int|null, dispatch_age_seconds: int|null, has_due_work: bool}
     */
    private function snapshot(array $attempts, ?array $enabled = null, array $hot = [], array $due = [], ?int $connections = null, ?int $dispatchAge = null, bool $hasDueWork = false): array
    {
        $rows = [];
        foreach ($attempts as $source => [$total, $successes, $blocked]) {
            $rows[$source] = ['attempts' => $total, 'successes' => $successes, 'blocked' => $blocked];
        }

        return [
            'attempts_by_source' => $rows,
            'enabled_sources' => $enabled ?? array_keys($rows),
            'hot_sources' => $hot,
            'due_by_source' => $due,
            'postgres_connections' => $connections,
            'dispatch_age_seconds' => $dispatchAge,
            'has_due_work' => $hasDueWork,
        ];
    }
}
