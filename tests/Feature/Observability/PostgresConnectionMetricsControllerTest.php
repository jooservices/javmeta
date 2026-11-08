<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use Tests\TestCase;

final class PostgresConnectionMetricsControllerTest extends TestCase
{
    public function test_metrics_endpoint_exposes_connection_demand_limits_and_warning_as_prometheus_gauges(): void
    {
        config([
            'database.connections' => ['pgsql' => ['driver' => 'pgsql']],
            'javmeta_queues.workers_per_queue' => 1,
            'javmeta_queues.browser_workers_per_queue' => 1,
            'javmeta_queues.retry.workers_per_queue' => 1,
            'javmeta_queues.worker_queue_overrides' => [],
            'javmeta_queues.worker_node_overrides' => [],
            'javmeta_queues.postgres_connection_budget' => [
                'role_limit' => 100,
                'reserve' => 20,
                'worker_nodes' => 1,
                'primary_nodes' => 0,
            ],
            'javmeta_alerts.postgres_connection_budget_warning_percent' => 75,
        ]);

        $response = $this->get('/metrics');

        $response->assertOk();
        self::assertSame('text/plain; version=0.0.4; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString("javmeta_postgres_connection_demand 17\n", (string) $response->getContent());
        self::assertStringContainsString("javmeta_postgres_connection_role_limit 100\n", (string) $response->getContent());
        self::assertStringContainsString("javmeta_postgres_connection_budget_limit 80\n", (string) $response->getContent());
        self::assertMatchesRegularExpression('/javmeta_postgres_connection_demand_warning_threshold 75(?:\.0)?\n/', (string) $response->getContent());
        self::assertStringContainsString("javmeta_postgres_connection_demand_warning 0\n", (string) $response->getContent());
        self::assertFalse($response->headers->has('Set-Cookie'));
    }
}
