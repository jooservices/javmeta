<?php

declare(strict_types=1);

namespace Tests\Unit\Workers;

use App\Services\Workers\HorizonLayoutSnapshot;
use Tests\TestCase;

final class HorizonLayoutSnapshotTest extends TestCase
{
    public function test_metric_points_report_applied_counts_per_queue_and_node(): void
    {
        $points = HorizonLayoutSnapshot::metricPoints([
            [
                'node' => 'worker-node-one',
                'supervisors' => [
                    'crawler-listing' => ['queue' => ['listing'], 'maxProcesses' => 3],
                    'crawler-search' => ['queue' => ['search'], 'maxProcesses' => 1],
                ],
            ],
            [
                'node' => 'main-node-two',
                'supervisors' => [
                    'crawler-listing' => ['queue' => ['listing'], 'maxProcesses' => 2],
                ],
            ],
        ]);

        $this->assertSame([
            ['name' => 'javmeta_horizon_workers', 'value' => 3, 'labels' => ['queue' => 'listing', 'node' => 'worker-node-one']],
            ['name' => 'javmeta_horizon_workers', 'value' => 1, 'labels' => ['queue' => 'search', 'node' => 'worker-node-one']],
            ['name' => 'javmeta_horizon_workers', 'value' => 2, 'labels' => ['queue' => 'listing', 'node' => 'main-node-two']],
        ], $points);
    }

    public function test_metric_reports_zero_when_a_live_supervisor_has_no_worker_process(): void
    {
        $points = HorizonLayoutSnapshot::metricPoints([
            [
                'node' => 'worker-node-one',
                'supervisors' => [
                    'crawler-listing' => ['queue' => ['listing'], 'maxProcesses' => 0],
                ],
            ],
        ]);

        $this->assertSame(0, $points[0]['value']);
    }
}
