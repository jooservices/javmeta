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
            'javmeta_alerts.backlog_age_max_minutes' => 1440,
            'javmeta_alerts.backlog_growth_max_per_hour' => 1000,
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

    public function test_the_backlog_age_is_measured_in_minutes_for_an_enabled_source_only(): void
    {
        $snapshot = $this->snapshot([], enabled: ['live'], due: ['live' => 7], oldestDueAge: ['live' => 90 * 60, 'gone' => 5000 * 60]);

        self::assertSame(['value' => 90, 'threshold' => 1440, 'context' => ['source_slug' => 'live', 'due_items' => 7]], $this->evaluator()->measurement('backlog_age:live', $snapshot));
        self::assertSame(0, $this->evaluator()->measurement('backlog_age:live', $this->snapshot([], enabled: ['live']))['value'], 'No due work counts as zero.');
        self::assertNull($this->evaluator()->measurement('backlog_age:gone', $snapshot));
        self::assertNull($this->evaluator()->measurement('backlog_age', $snapshot));
    }

    public function test_the_backlog_growth_is_measured_per_hour_for_an_enabled_source_only(): void
    {
        $snapshot = $this->snapshot([], enabled: ['live'], due: ['live' => 7], growth: ['live' => 250, 'gone' => 9000]);

        self::assertSame(['value' => 250, 'threshold' => 1000, 'context' => ['source_slug' => 'live', 'due_items' => 7]], $this->evaluator()->measurement('backlog_growth:live', $snapshot));
        self::assertSame(0, $this->evaluator()->measurement('backlog_growth:live', $this->snapshot([], enabled: ['live'], growth: ['live' => 0]))['value'], 'A measured zero is a measurement.');
        self::assertNull($this->evaluator()->measurement('backlog_growth:gone', $snapshot));
        self::assertNull($this->evaluator()->measurement('backlog_growth', $snapshot));
    }

    public function test_a_missing_growth_figure_is_not_a_measurement_unless_the_rule_is_off(): void
    {
        $noHistory = $this->snapshot([], enabled: ['live'], due: ['live' => 7]);

        self::assertNull($this->evaluator()->measurement('backlog_growth:live', $noHistory), 'No history is unknown, not zero.');

        config(['javmeta_alerts.backlog_growth_max_per_hour' => 0]);
        self::assertSame(0, $this->evaluator()->measurement('backlog_growth:live', $noHistory)['value'], 'A rule that is off has nothing to wait for.');
    }

    public function test_the_retired_backlog_depth_rule_has_no_measurement(): void
    {
        self::assertNull($this->evaluator()->measurement('backlog_depth:live', $this->snapshot([], enabled: ['live'], due: ['live' => 1])));
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
    private function snapshot(array $attempts, ?array $enabled = null, array $due = [], ?int $connections = null, ?int $dispatchAge = null, bool $hasDueWork = false, array $oldestDueAge = [], array $growth = []): array
    {
        $rows = [];
        foreach ($attempts as $source => [$total, $successes, $blocked]) {
            $rows[$source] = ['attempts' => $total, 'successes' => $successes, 'blocked' => $blocked];
        }

        return [
            'attempts_by_source' => $rows,
            'enabled_sources' => $enabled ?? array_keys($rows),
            'due_by_source' => $due,
            'oldest_due_age_by_source' => $oldestDueAge,
            'due_growth_per_hour_by_source' => $growth,
            'postgres_connections' => $connections,
            'dispatch_age_seconds' => $dispatchAge,
            'has_due_work' => $hasDueWork,
        ];
    }
}
