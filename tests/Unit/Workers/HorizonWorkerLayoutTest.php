<?php

declare(strict_types=1);

namespace Tests\Unit\Workers;

use App\Services\Workers\HorizonWorkerLayout;
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
                'main@node.one' => ['direct' => 9, 'queues' => ['observability' => 3]],
            ],
        ]);

        $supervisors = app(HorizonWorkerLayout::class)->supervisors();

        $this->assertSame(5, $supervisors['crawler-listing']['maxProcesses']);
        $this->assertSame(7, $supervisors['crawler-browser_listing']['maxProcesses']);
        $this->assertSame(8, $supervisors['crawler-search']['maxProcesses']);
        $this->assertSame(9, $supervisors['crawler-detail']['maxProcesses']);
        $this->assertSame(3, $supervisors['api-observability']['maxProcesses']);
    }
}
