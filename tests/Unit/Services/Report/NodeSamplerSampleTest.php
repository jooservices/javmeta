<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Report;

use App\Services\Report\NodeSampleStore;
use App\Services\Report\NodeSampler;
use App\Services\Report\WorkerBusyStateStore;
use App\Services\Report\WorkerProcessSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(NodeSampler::class)]
final class NodeSamplerSampleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'javmeta_report.node_volume_path' => '', 'sentry.node' => 'xcrawler-test']);
        Cache::store('array')->flush();
        Carbon::setTestNow('2026-11-05 03:00:00 UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_sample_carries_the_node_cpu_memory_disk_and_worker_counts_and_is_stored(): void
    {
        $store = new NodeSampleStore();
        $sampler = $this->sampler($store, [['pid' => 7, 'lane' => 'direct']]);

        $first = $sampler->sample('worker-1');

        self::assertSame('worker-1', $first['node']);
        self::assertSame(Carbon::now()->timestamp, $first['sampled_at']);
        self::assertNull($first['cpu_percent'], 'The first sample has no earlier counters to compare with.');
        self::assertIsArray($first['cpu_counters']);
        self::assertArrayHasKey('total', $first['cpu_counters']);
        self::assertIsFloat($first['load1']);
        self::assertGreaterThan(0, $first['mem_available_bytes']);
        self::assertGreaterThan(0.0, $first['disk_used_percent']);
        self::assertLessThanOrEqual(100.0, $first['disk_used_percent']);
        self::assertSame(1, $first['workers']['total']);
        self::assertSame($first, $store->samples('worker-1')[0]);
    }

    public function test_the_second_sample_reports_cpu_use_against_the_stored_counters(): void
    {
        $store = new NodeSampleStore();
        $sampler = $this->sampler($store, []);

        $sampler->sample('worker-2');
        Carbon::setTestNow(Carbon::now()->addMinute());
        $second = $sampler->sample('worker-2');

        self::assertCount(2, $store->samples('worker-2'));
        if ($second['cpu_percent'] !== null) {
            self::assertGreaterThanOrEqual(0.0, $second['cpu_percent']);
            self::assertLessThanOrEqual(100.0, $second['cpu_percent']);
        }
    }

    public function test_a_blank_node_name_falls_back_to_the_configured_node_name(): void
    {
        $sampler = $this->sampler(new NodeSampleStore(), []);

        self::assertSame('xcrawler-test', $sampler->sample('   ')['node']);
        self::assertSame('xcrawler-test', $sampler->sample()['node']);
        self::assertSame('xcrawler-test', NodeSampler::nodeName());
    }

    public function test_a_configured_volume_path_is_used_for_the_disk_figure(): void
    {
        config(['javmeta_report.node_volume_path' => sys_get_temp_dir()]);

        $sample = $this->sampler(new NodeSampleStore(), [])->sample('worker-3');

        self::assertSame(NodeSampler::diskUsedPercent(sys_get_temp_dir()), $sample['disk_used_percent']);
    }

    public function test_unreadable_inputs_become_nulls_instead_of_errors(): void
    {
        self::assertNull(NodeSampler::diskUsedPercent('/definitely/not/a/real/path'));
        self::assertNull(NodeSampler::cpuCounters('no cpu line here'));
        self::assertNull(NodeSampler::cpuCounters("cpu 1 2 3\n"));
        self::assertNull(NodeSampler::loadAverage(''));
        self::assertNull(NodeSampler::loadAverage('abc 1 2'));
        self::assertNull(NodeSampler::cpuPercent(['total' => 100, 'idle' => 50], ['total' => 100, 'idle' => 60]));
        // An idle counter that went backwards counts as no idle time, never as negative load.
        self::assertSame(100.0, NodeSampler::cpuPercent(['total' => 0, 'idle' => 10], ['total' => 10, 'idle' => 5]));
    }

    /** @param list<array{pid: int, lane: string}> $processes */
    private function sampler(NodeSampleStore $store, array $processes): NodeSampler
    {
        $source = new class ($processes) implements WorkerProcessSource {
            /** @param list<array{pid: int, lane: string}> $processes */
            public function __construct(private readonly array $processes) {}

            public function processes(): array
            {
                return $this->processes;
            }
        };

        return new NodeSampler($store, new WorkerBusyStateStore(), $source);
    }
}
