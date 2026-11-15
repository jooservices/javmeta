<?php

declare(strict_types=1);

namespace Tests\Unit\Workers;

use App\Services\Workers\HorizonWorkerLayout;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class HorizonWorkerLayoutTest extends TestCase
{
    public function test_worker_counts_apply_lane_queue_role_and_hostname_precedence(): void
    {
        config([
            'javmeta_workers.role' => 'main',
            'sentry.node' => 'node.one',
            'javmeta_queues.workers_per_queue' => 2,
            'javmeta_queues.direct_workers_per_queue' => 3,
            'javmeta_queues.browser_workers_per_queue' => 4,
            'javmeta_queues.worker_queue_overrides' => ['listing' => 5],
            'javmeta_queues.worker_node_overrides' => [
                'main' => ['default' => 6, 'browser' => 7, 'queues' => ['search' => 8]],
                'main@node.one' => ['direct' => 9, 'retry' => 10, 'queues' => ['observability' => 3]],
            ],
        ]);

        $supervisors = app(HorizonWorkerLayout::class)->supervisors();

        $this->assertSame(5, $supervisors['crawler-listing']['maxProcesses']);
        $this->assertSame(7, $supervisors['crawler-browser_listing']['maxProcesses']);
        $this->assertSame(9, $supervisors['crawler-search']['maxProcesses']);
        $this->assertSame(['search', 'download_search'], $supervisors['crawler-search']['queue']);
        $this->assertSame(9, $supervisors['crawler-detail']['maxProcesses']);
        $this->assertSame(10, $supervisors['crawler-retry_listing']['maxProcesses']);
        $this->assertSame(3, $supervisors['api-observability']['maxProcesses']);
    }

    public function test_node_overrides_do_not_leak_to_a_different_node(): void
    {
        config([
            'javmeta_workers.role' => 'worker',
            'sentry.node' => 'node.two',
            'javmeta_queues.workers_per_queue' => 2,
            'javmeta_queues.browser_workers_per_queue' => 3,
            'javmeta_queues.worker_node_overrides' => [
                'main' => ['default' => 6],
                'worker@node.one' => ['queues' => ['listing' => 8]],
            ],
        ]);

        $supervisors = app(HorizonWorkerLayout::class)->supervisors();

        $this->assertSame(2, $supervisors['crawler-listing']['maxProcesses']);
        $this->assertSame(3, $supervisors['crawler-browser_listing']['maxProcesses']);
        $this->assertSame(2, $supervisors['crawler-search']['maxProcesses']);
    }

    public function test_every_queue_keeps_at_least_one_worker_after_bad_counts_are_clamped(): void
    {
        config([
            'javmeta_queues.workers_per_queue' => 0,
            'javmeta_queues.direct_workers_per_queue' => 0,
            'javmeta_queues.browser_workers_per_queue' => 0,
            'javmeta_queues.worker_queue_overrides' => ['listing' => 0],
            'javmeta_queues.worker_node_overrides' => ['main' => ['default' => 0, 'queues' => ['search' => 0]]],
        ]);

        $counts = HorizonWorkerLayout::workerCounts(app(HorizonWorkerLayout::class)->supervisors());

        $this->assertNotEmpty($counts);
        $this->assertSame([1], array_values(array_unique($counts)));
    }

    public function test_configured_fleet_worker_count_sums_role_and_node_specific_overrides(): void
    {
        config([
            'javmeta_queues.workers_per_queue' => 1,
            'javmeta_queues.browser_workers_per_queue' => 2,
            'javmeta_queues.retry.workers_per_queue' => 1,
            'javmeta_queues.worker_queue_overrides' => [],
            'javmeta_queues.worker_node_overrides' => [
                'main' => ['default' => 2],
                'worker' => ['default' => 2],
                'worker@node.one' => ['default' => 3],
                'worker@node-two' => ['queues' => ['listing' => 4]],
            ],
            'javmeta_queues.postgres_connection_budget.worker_nodes' => 3,
            'javmeta_queues.postgres_connection_budget.primary_nodes' => 1,
        ]);

        $workerCount = app(HorizonWorkerLayout::class)->configuredFleetWorkerCount();

        self::assertSame(125, $workerCount);
    }

    public function test_apply_keeps_code_defaults_when_database_layout_exceeds_connection_budget(): void
    {
        $defaults = (array) config('horizon.defaults');
        config([
            'javmeta_workers.role' => 'worker',
            'javmeta_queues.workers_per_queue' => 32,
            'javmeta_queues.direct_workers_per_queue' => null,
            'javmeta_queues.browser_workers_per_queue' => 32,
            'javmeta_queues.retry.workers_per_queue' => 32,
            'javmeta_queues.worker_queue_overrides' => [],
            'javmeta_queues.worker_node_overrides' => [],
            'javmeta_queues.postgres_connection_budget' => [
                'role_limit' => 120,
                'reserve' => 30,
                'worker_nodes' => 3,
                'primary_nodes' => 1,
            ],
            'database.connections' => ['pgsql' => ['driver' => 'pgsql']],
        ]);
        Log::shouldReceive('warning')->once()->withArgs(static function (string $message, array $context): bool {
            return $message === 'Horizon worker settings exceed the PostgreSQL connection budget; keeping the code default layout.'
                && ($context['configured_demand'] ?? null) > ($context['budget_limit'] ?? PHP_INT_MAX);
        })->andReturnNull();

        $snapshot = app(HorizonWorkerLayout::class)->apply();

        self::assertSame($defaults, $snapshot['supervisors']);
        self::assertSame($defaults, config('horizon.defaults'));
    }
}
