<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Workers;

use App\Services\Workers\PostgresConnectionBudget;
use InvalidArgumentException;
use Tests\TestCase;

final class PostgresConnectionBudgetTest extends TestCase
{
    public function test_worker_demand_above_the_reserved_role_budget_is_refused(): void
    {
        config([
            'database.connections' => [
                'pgsql' => ['driver' => 'pgsql'],
                'sqlite' => ['driver' => 'sqlite'],
            ],
            'javmeta_queues.postgres_connection_budget.role_limit' => 120,
            'javmeta_queues.postgres_connection_budget.reserve' => 30,
            'javmeta_alerts.postgres_connection_budget_warning_percent' => 75,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Configured PostgreSQL connection demand (91) exceeds the safe role budget (90).');

        app(PostgresConnectionBudget::class)->assertWithinBudget(91);
    }

    public function test_role_budget_is_inclusive_and_warning_threshold_is_calculated_from_role_limit(): void
    {
        config([
            'database.connections' => ['pgsql' => ['driver' => 'pgsql']],
            'javmeta_queues.postgres_connection_budget.role_limit' => 120,
            'javmeta_queues.postgres_connection_budget.reserve' => 30,
            'javmeta_alerts.postgres_connection_budget_warning_percent' => 75,
        ]);
        $budget = app(PostgresConnectionBudget::class);

        $budget->assertWithinBudget(90);
        $belowWarning = $budget->summary(89);
        $atWarning = $budget->summary(90);

        self::assertSame(90, $belowWarning['budget_limit']);
        self::assertSame(90.0, $belowWarning['warning_threshold']);
        self::assertFalse($belowWarning['warning']);
        self::assertTrue($atWarning['warning']);
    }

    public function test_demand_counts_named_postgres_connections_and_read_write_pools(): void
    {
        config([
            'database.connections' => [
                'pgsql' => ['driver' => 'pgsql', 'read' => [], 'write' => []],
                'pgsql_read' => ['driver' => 'pgsql'],
                'sqlite' => ['driver' => 'sqlite'],
            ],
            'javmeta_queues.postgres_connection_budget.role_limit' => 120,
            'javmeta_queues.postgres_connection_budget.reserve' => 30,
            'javmeta_alerts.postgres_connection_budget_warning_percent' => 75,
        ]);

        $summary = app(PostgresConnectionBudget::class)->summary(30);

        self::assertSame(3, $summary['connections_per_worker']);
        self::assertSame(90, $summary['demand']);
        self::assertTrue($summary['within_budget']);
    }

    public function test_negative_worker_demand_and_invalid_budget_settings_are_rejected(): void
    {
        config([
            'database.connections' => ['pgsql' => ['driver' => 'pgsql']],
            'javmeta_queues.postgres_connection_budget.role_limit' => 120,
            'javmeta_queues.postgres_connection_budget.reserve' => 120,
            'javmeta_alerts.postgres_connection_budget_warning_percent' => 75,
        ]);
        $budget = app(PostgresConnectionBudget::class);

        try {
            $budget->summary(-1);
            self::fail('Negative worker demand should be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Configured worker process count cannot be negative.', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PostgreSQL connection budget configuration is invalid.');
        $budget->summary(1);
    }
}
