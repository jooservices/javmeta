<?php

declare(strict_types=1);

namespace Tests\Unit\Alerts;

use App\Services\Alerts\AlertRuleEvaluator;
use Tests\TestCase;

final class AlertRuleEvaluatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'javmeta_alerts.success_rate_min_percent' => 85,
            'javmeta_alerts.blocked_ratio_max_percent' => 10,
            'javmeta_alerts.min_attempts_in_window' => 100,
            'javmeta_alerts.pg_connections_max' => 45,
            'javmeta_alerts.dispatch_age_max_seconds' => 60,
            'javmeta_alerts.backlog_age_max_minutes' => 1440,
            'javmeta_alerts.backlog_growth_max_per_hour' => 1000,
            'javmeta_alerts.window_minutes' => 15,
        ]);
    }

    public function test_low_success_rate_fires_only_after_the_minimum_sample(): void
    {
        $evaluator = new AlertRuleEvaluator();
        $smallSample = $evaluator->evaluate($this->snapshot([
            'javdb' => ['attempts' => 99, 'successes' => 50, 'blocked' => 0],
        ]));
        $largeSample = $evaluator->evaluate($this->snapshot([
            'javdb' => ['attempts' => 100, 'successes' => 84, 'blocked' => 0],
        ]));

        self::assertArrayNotHasKey('success_rate', $smallSample);
        self::assertSame(84.0, $largeSample['success_rate']['value']);
        self::assertSame(85.0, $largeSample['success_rate']['threshold']);
    }

    public function test_success_rate_below_critical_threshold_uses_the_critical_rule(): void
    {
        $rules = (new AlertRuleEvaluator())->evaluate($this->snapshot([
            'javdb' => ['attempts' => 100, 'successes' => 69, 'blocked' => 0],
        ]));

        self::assertArrayHasKey('success_rate_critical', $rules);
        self::assertSame('critical', $rules['success_rate_critical']['severity']);
        self::assertSame(70.0, $rules['success_rate_critical']['threshold']);
        self::assertArrayNotHasKey('success_rate', $rules);
    }

    public function test_blocked_and_per_source_rates_use_their_own_thresholds(): void
    {
        $rules = (new AlertRuleEvaluator())->evaluate($this->snapshot([
            'javdb' => ['attempts' => 100, 'successes' => 90, 'blocked' => 11],
            'onejav' => ['attempts' => 100, 'successes' => 70, 'blocked' => 15],
        ]));

        self::assertArrayHasKey('blocked_ratio', $rules);
        self::assertArrayHasKey('source_success_rate:onejav', $rules);
        self::assertArrayNotHasKey('source_success_rate:javdb', $rules);
        self::assertSame('onejav', $rules['success_rate']['context']['top_sources'][0]);
    }

    public function test_rate_thresholds_compare_unrounded_values(): void
    {
        $rules = (new AlertRuleEvaluator())->evaluate($this->snapshot([
            'javdb' => ['attempts' => 10000, 'successes' => 8496, 'blocked' => 1004],
        ]));

        self::assertArrayHasKey('success_rate', $rules);
        self::assertArrayHasKey('blocked_ratio', $rules);
        self::assertArrayHasKey('source_success_rate:javdb', $rules);
        self::assertSame(85.0, $rules['success_rate']['value']);
        self::assertSame(10.0, $rules['blocked_ratio']['value']);
    }

    public function test_capacity_and_dispatch_rules_fire_at_their_limits(): void
    {
        $rules = (new AlertRuleEvaluator())->evaluate($this->snapshot(
            ['javdb' => ['attempts' => 100, 'successes' => 90, 'blocked' => 0]],
            postgresConnections: 46,
            dispatchAge: 61,
            dueBySource: ['javdb' => 9],
        ));

        self::assertArrayHasKey('postgres_connections', $rules);
        self::assertArrayHasKey('dispatch_age', $rules);
    }

    public function test_a_caught_up_source_raises_no_backlog_alert(): void
    {
        $rules = (new AlertRuleEvaluator())->evaluate($this->snapshot(
            ['javdb' => ['attempts' => 100, 'successes' => 95, 'blocked' => 0]],
            dueBySource: [],
            hasDueWork: false,
        ));

        self::assertSame([], array_filter(array_keys($rules), static fn(string $rule): bool => str_starts_with($rule, 'backlog_')));
    }

    public function test_a_source_with_recent_due_work_and_a_slow_backlog_raises_no_backlog_alert(): void
    {
        $rules = (new AlertRuleEvaluator())->evaluate($this->snapshot(
            ['javdb' => ['attempts' => 100, 'successes' => 95, 'blocked' => 0]],
            dueBySource: ['javdb' => 2000],
            oldestDueAge: ['javdb' => 1440 * 60],
            growth: ['javdb' => 1000],
        ));

        self::assertArrayNotHasKey('backlog_age:javdb', $rules, 'Exactly the limit is not late.');
        self::assertArrayNotHasKey('backlog_growth:javdb', $rules, 'Exactly the limit is not growing too fast.');
    }

    public function test_a_source_whose_oldest_due_item_is_older_than_the_threshold_raises_an_alert(): void
    {
        $rules = (new AlertRuleEvaluator())->evaluate($this->snapshot(
            ['javdb' => ['attempts' => 100, 'successes' => 95, 'blocked' => 0]],
            dueBySource: ['javdb' => 40],
            oldestDueAge: ['javdb' => 1441 * 60],
        ));

        self::assertSame(
            ['severity' => 'warning', 'value' => 1441.0, 'threshold' => 1440, 'context' => ['source_slug' => 'javdb', 'due_items' => 40]],
            $rules['backlog_age:javdb'],
        );
    }

    public function test_a_source_whose_backlog_grows_faster_than_the_threshold_raises_an_alert(): void
    {
        $rules = (new AlertRuleEvaluator())->evaluate($this->snapshot(
            ['javdb' => ['attempts' => 100, 'successes' => 95, 'blocked' => 0]],
            dueBySource: ['javdb' => 3000],
            growth: ['javdb' => 1001],
        ));

        self::assertSame(
            ['severity' => 'warning', 'value' => 1001.0, 'threshold' => 1000, 'context' => ['source_slug' => 'javdb', 'due_items' => 3000]],
            $rules['backlog_growth:javdb'],
        );
    }

    public function test_a_shrinking_backlog_raises_no_growth_alert(): void
    {
        $rules = (new AlertRuleEvaluator())->evaluate($this->snapshot(
            ['javdb' => ['attempts' => 100, 'successes' => 95, 'blocked' => 0]],
            dueBySource: ['javdb' => 10],
            growth: ['javdb' => -5000],
        ));

        self::assertArrayNotHasKey('backlog_growth:javdb', $rules);
    }

    public function test_a_source_that_is_not_enabled_raises_no_backlog_alert(): void
    {
        $rules = (new AlertRuleEvaluator())->evaluate([
            ...$this->snapshot(['javdb' => ['attempts' => 100, 'successes' => 95, 'blocked' => 0]]),
            'oldest_due_age_by_source' => ['muramura' => 99999 * 60],
            'due_growth_per_hour_by_source' => ['muramura' => 99999],
            'due_by_source' => ['muramura' => 101],
        ]);

        self::assertArrayNotHasKey('backlog_age:muramura', $rules);
        self::assertArrayNotHasKey('backlog_growth:muramura', $rules);
    }

    public function test_a_threshold_of_zero_switches_the_backlog_rule_off(): void
    {
        config()->set(['javmeta_alerts.backlog_age_max_minutes' => 0, 'javmeta_alerts.backlog_growth_max_per_hour' => 0]);

        $rules = (new AlertRuleEvaluator())->evaluate($this->snapshot(
            ['javdb' => ['attempts' => 100, 'successes' => 95, 'blocked' => 0]],
            dueBySource: ['javdb' => 5000],
            oldestDueAge: ['javdb' => 999999],
            growth: ['javdb' => 99999],
        ));

        self::assertSame([], array_filter(array_keys($rules), static fn(string $rule): bool => str_starts_with($rule, 'backlog_')));
    }

    public function test_dispatch_alert_requires_due_work_and_thresholds_are_strict(): void
    {
        $evaluator = new AlertRuleEvaluator();
        $rules = $evaluator->evaluate($this->snapshot(
            ['javdb' => ['attempts' => 100, 'successes' => 85, 'blocked' => 10]],
            postgresConnections: 45,
            dispatchAge: 60,
            dueBySource: ['javdb' => 10],
            hasDueWork: false,
        ));

        self::assertArrayNotHasKey('success_rate', $rules);
        self::assertArrayNotHasKey('blocked_ratio', $rules);
        self::assertArrayNotHasKey('dispatch_age', $rules);
    }

    /**
     * @param  array<string, array{attempts: int, successes: int, blocked: int}>  $attempts
     * @param  array<string, int>  $dueBySource
     * @param  array<string, int>  $oldestDueAge  seconds
     * @param  array<string, int>  $growth  due items per hour
     * @return array<string, mixed>
     */
    private function snapshot(
        array $attempts,
        ?int $postgresConnections = null,
        ?int $dispatchAge = null,
        array $dueBySource = [],
        bool $hasDueWork = true,
        array $oldestDueAge = [],
        array $growth = [],
    ): array {
        return [
            'attempts_by_source' => $attempts,
            'enabled_sources' => array_keys($attempts),
            'due_by_source' => $dueBySource,
            'oldest_due_age_by_source' => $oldestDueAge,
            'due_growth_per_hour_by_source' => $growth,
            'postgres_connections' => $postgresConnections,
            'dispatch_age_seconds' => $dispatchAge,
            'has_due_work' => $hasDueWork,
        ];
    }
}
