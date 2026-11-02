<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Report;

use App\Services\Report\NodeSampler;
use App\Services\Report\ProcWorkerProcessSource;
use App\Services\Report\WorkerBusyStateStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class NodeSamplerTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_proc_stat_cpu_memory_and_load_values_are_parsed(): void
    {
        $previous = NodeSampler::cpuCounters("cpu 10 2 3 40 5 1 0 0 0 0\n");
        $current = NodeSampler::cpuCounters("cpu 20 2 8 50 5 1 0 0 0 0\n");

        self::assertSame(['total' => 61, 'idle' => 45], $previous);
        self::assertSame(60.0, NodeSampler::cpuPercent($previous, $current));
        self::assertSame(1024 * 1024, NodeSampler::memoryAvailable("MemTotal: 4096 kB\nMemAvailable: 1024 kB\n"));
        self::assertSame(0.24, NodeSampler::loadAverage('0.24 0.30 0.50 1/100 1234'));
        self::assertNull(NodeSampler::memoryAvailable('MemFree: 12 kB'));
        self::assertSame(['total' => 61, 'idle' => 45], NodeSampler::cpuCounters("cpu 10 2 3 40 5 1 0 0 100 20\n"));
        config(['sentry.node' => 'unknown']);
        self::assertSame(gethostname(), NodeSampler::nodeName());
    }

    public function test_proc_process_list_counts_horizon_workers_and_reads_browser_lane(): void
    {
        $directory = base_path('.tmp/javmeta-proc-' . bin2hex(random_bytes(5)));
        mkdir($directory . '/101', 0700, true);
        mkdir($directory . '/202', 0700, true);
        mkdir($directory . '/303', 0700, true);
        file_put_contents($directory . '/101/cmdline', "php\0artisan\0horizon:work\0redis\0--queue=listing\0");
        file_put_contents($directory . '/202/cmdline', "php\0artisan\0horizon:work\0redis\0--queue=browser_listing\0");
        file_put_contents($directory . '/303/cmdline', "php\0artisan\0schedule:work\0");

        try {
            $processes = (new ProcWorkerProcessSource($directory))->processes();
        } finally {
            unlink($directory . '/101/cmdline');
            unlink($directory . '/202/cmdline');
            unlink($directory . '/303/cmdline');
            rmdir($directory . '/101');
            rmdir($directory . '/202');
            rmdir($directory . '/303');
            rmdir($directory);
        }

        self::assertSame([
            ['pid' => 101, 'lane' => 'direct'],
            ['pid' => 202, 'lane' => 'browser'],
        ], $processes);
    }

    public function test_worker_counts_split_lanes_and_expired_busy_markers_become_idle(): void
    {
        config(['cache.default' => 'array', 'javmeta_report.worker_busy_ttl_seconds' => 30]);
        Cache::store('array')->flush();
        Carbon::setTestNow('2026-11-03 12:00:00 UTC');
        $states = new WorkerBusyStateStore();
        $processes = [
            ['pid' => 11, 'lane' => 'direct'],
            ['pid' => 12, 'lane' => 'direct'],
            ['pid' => 21, 'lane' => 'browser'],
            ['pid' => 22, 'lane' => 'browser'],
        ];
        $states->markBusy('worker-a', 11, 'direct', 'listing');
        $states->markBusy('worker-a', 21, 'browser', 'browser_listing');

        $counts = $states->counts('worker-a', $processes);
        self::assertSame(['busy' => 2, 'idle' => 2, 'total' => 4], array_intersect_key($counts, array_flip(['busy', 'idle', 'total'])));
        self::assertSame(['busy' => 1, 'idle' => 1, 'total' => 2], $counts['lanes']['direct']);
        self::assertSame(['busy' => 1, 'idle' => 1, 'total' => 2], $counts['lanes']['browser']);
        self::assertSame('browser_listing', Cache::get(WorkerBusyStateStore::key('worker-a', 21))['queue']);

        Carbon::setTestNow(Carbon::now()->addSeconds(31));
        $expired = $states->counts('worker-a', $processes);
        self::assertSame(0, $expired['busy']);
        self::assertSame(4, $expired['idle']);
    }
}
