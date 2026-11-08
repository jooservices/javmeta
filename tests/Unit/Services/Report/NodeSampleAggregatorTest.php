<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Report;

use App\Services\Report\NodeSampleAggregator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class NodeSampleAggregatorTest extends TestCase
{
    public function test_it_aggregates_cpu_peak_and_average_worker_share_and_omits_stale_nodes(): void
    {
        $now = CarbonImmutable::parse('2026-11-03 12:00:00 UTC');
        $nodes = (new NodeSampleAggregator())->aggregate([
            'worker-a' => [
                ['sampled_at' => $now->subMinutes(2)->timestamp, 'cpu_percent' => 20, 'workers' => ['busy' => 1, 'total' => 2]],
                ['sampled_at' => $now->timestamp, 'cpu_percent' => 40, 'mem_available_bytes' => 1000, 'disk_used_percent' => 12.5, 'workers' => ['busy' => 2, 'idle' => 0, 'total' => 2]],
            ],
            'worker-old' => [['sampled_at' => $now->subMinutes(10)->timestamp, 'cpu_percent' => 50, 'workers' => ['busy' => 1, 'total' => 1]]],
        ], $now->subMinutes(10), $now, 180);

        self::assertSame(['worker-a'], array_keys($nodes));
        self::assertSame(30.0, $nodes['worker-a']['cpu_avg']);
        self::assertSame(40.0, $nodes['worker-a']['cpu_peak']);
        self::assertSame(0.75, $nodes['worker-a']['busy_share_avg']);
        self::assertSame(1000, $nodes['worker-a']['mem_available_bytes']);
    }
}
