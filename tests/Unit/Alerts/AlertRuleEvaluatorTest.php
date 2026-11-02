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
            'javmeta_alerts.backlog_due_min_per_hot_source' => 10,
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

    public function test_capacity_dispatch_and_low_backlog_rules_fire_at_their_limits(): void
    {
        $rules = (new AlertRuleEvaluator())->evaluate($this->snapshot(
            ['javdb' => ['attempts' => 100, 'successes' => 90, 'blocked' => 0]],
            postgresConnections: 46,
            dispatchAge: 61,
            dueBySource: ['javdb' => 9],
        ));

        self::assertArrayHasKey('postgres_connections', $rules);
        self::assertArrayHasKey('dispatch_age', $rules);
        self::assertArrayHasKey('backlog_depth:javdb', $rules);
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
        self::assertArrayNotHasKey('backlog_depth:javdb', $rules);
    }

    /**
     * @param  array<string, array{attempts: int, successes: int, blocked: int}>  $attempts
     * @param  array<string, int>  $dueBySource
     * @return array<string, mixed>
     */
    private function snapshot(
        array $attempts,
        ?int $postgresConnections = null,
        ?int $dispatchAge = null,
        array $dueBySource = [],
        bool $hasDueWork = true,
    ): array {
        return [
            'attempts_by_source' => $attempts,
            'enabled_sources' => array_keys($attempts),
            'hot_sources' => array_keys($attempts),
            'due_by_source' => $dueBySource,
            'postgres_connections' => $postgresConnections,
            'dispatch_age_seconds' => $dispatchAge,
            'has_due_work' => $hasDueWork,
        ];
    }
}
