<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawl;

use App\Services\Crawl\WorkerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class WorkerHeartbeatTest extends TestCase
{
    use RefreshDatabase;
    public function test_beat_writes_global_and_instance_heartbeat(): void
    {
        $heartbeat = app(WorkerHeartbeat::class);
        Carbon::setTestNow('2026-09-30 10:00:00');
        $heartbeat->beat('node-a:100');

        $this->assertSame('2026-09-30T10:00:00+00:00', $heartbeat->lastBeatAt()?->toIso8601String());
        $this->assertSame('2026-09-30T10:00:00+00:00', $heartbeat->lastBeatAt('node-a:100')?->toIso8601String());
        Carbon::setTestNow();
    }

    public function test_is_stale_true_when_no_heartbeat(): void
    {
        $heartbeat = app(WorkerHeartbeat::class);

        $this->assertTrue($heartbeat->isStale());
        $this->assertTrue($heartbeat->isStale('node-a:100'));
    }

    public function test_is_stale_respects_threshold(): void
    {
        $heartbeat = app(WorkerHeartbeat::class);
        Carbon::setTestNow('2026-09-30 10:00:00');
        $heartbeat->beat('node-a:100');

        Carbon::setTestNow('2026-09-30 10:15:00');

        $this->assertFalse($heartbeat->isStale('node-a:100', 900));
        $this->assertTrue($heartbeat->isStale('node-a:100', 60));
        Carbon::setTestNow();
    }

    public function test_instances_lists_each_worker_with_staleness(): void
    {
        $heartbeat = app(WorkerHeartbeat::class);
        Carbon::setTestNow('2026-09-30 10:00:00');
        $heartbeat->beat('node-a:100');
        $heartbeat->beat('node-b:200');

        Carbon::setTestNow('2026-09-30 10:50:00');
        $heartbeat->beat('node-b:200');

        $instances = $heartbeat->instances(600);

        $this->assertCount(2, $instances);
        $this->assertSame('node-a:100', $instances[0]['instance']);
        $this->assertTrue($instances[0]['stale']);
        $this->assertSame('node-b:200', $instances[1]['instance']);
        $this->assertFalse($instances[1]['stale']);
        Carbon::setTestNow();
    }

    public function test_beat_prunes_instances_older_than_the_retention_window(): void
    {
        $heartbeat = app(WorkerHeartbeat::class);
        Carbon::setTestNow('2026-09-30 10:00:00');
        Cache::put(WorkerHeartbeat::INDEX_KEY, ['old:1' => now()->subHours(2)->getTimestamp()], now()->addDay());

        $heartbeat->beat('new:2');

        $index = Cache::get(WorkerHeartbeat::INDEX_KEY);
        $this->assertArrayNotHasKey('old:1', $index);
        $this->assertArrayHasKey('new:2', $index);
        Carbon::setTestNow();
    }

    public function test_index_never_exceeds_the_hard_cap(): void
    {
        $heartbeat = app(WorkerHeartbeat::class);
        Carbon::setTestNow('2026-09-30 10:00:00');
        $now = now()->getTimestamp();
        $seeded = [];
        for ($i = 1; $i <= 500; $i++) {
            $seeded['node-' . $i . ':1'] = $now - $i;
        }
        Cache::put(WorkerHeartbeat::INDEX_KEY, $seeded, now()->addDay());

        $heartbeat->beat('newest:2');

        $index = Cache::get(WorkerHeartbeat::INDEX_KEY);
        $this->assertLessThanOrEqual(WorkerHeartbeat::INDEX_MAX_ENTRIES, count($index));
        $this->assertArrayHasKey('newest:2', $index);
        $this->assertArrayHasKey('node-1:1', $index);
        $this->assertArrayNotHasKey('node-500:1', $index);
        Carbon::setTestNow();
    }

    public function test_beat_skips_the_index_write_inside_the_write_interval(): void
    {
        $heartbeat = app(WorkerHeartbeat::class);
        Carbon::setTestNow('2026-09-30 10:00:00');
        $heartbeat->beat('node-a:100');
        $firstWrite = Cache::get(WorkerHeartbeat::INDEX_KEY)['node-a:100'];

        Carbon::setTestNow('2026-09-30 10:00:10');
        $heartbeat->beat('node-a:100');

        $this->assertSame($firstWrite, Cache::get(WorkerHeartbeat::INDEX_KEY)['node-a:100']);
        $this->assertSame($firstWrite, Carbon::parse('2026-09-30 10:00:00')->getTimestamp());
        Carbon::setTestNow();
    }

    public function test_instances_handles_a_huge_legacy_index_without_exhausting_memory(): void
    {
        $heartbeat = app(WorkerHeartbeat::class);
        Carbon::setTestNow('2026-09-30 10:00:00');
        $now = now()->getTimestamp();
        $legacy = [];
        for ($i = 1; $i <= 50000; $i++) {
            $legacy['node-' . $i . ':' . $i] = $i % 2 === 0 ? $now - 7200 - $i : $now - ($i % 300);
        }
        Cache::put(WorkerHeartbeat::INDEX_KEY, $legacy, now()->addDay());
        unset($legacy);

        $before = memory_get_peak_usage();
        $instances = $heartbeat->instances(600);
        $growth = memory_get_peak_usage() - $before;

        $this->assertLessThanOrEqual(WorkerHeartbeat::INDEX_MAX_ENTRIES, count($instances));
        foreach ($instances as $row) {
            $this->assertGreaterThanOrEqual($now - WorkerHeartbeat::INDEX_RETENTION_SECONDS, Carbon::parse($row['last_heartbeat_at'])->getTimestamp());
        }
        $this->assertLessThan(32 * 1024 * 1024, $growth);
        Carbon::setTestNow();
    }

    public function test_instances_keeps_the_row_shape_and_stale_flag(): void
    {
        $heartbeat = app(WorkerHeartbeat::class);
        Carbon::setTestNow('2026-09-30 10:00:00');
        Cache::put(WorkerHeartbeat::INDEX_KEY, [
            'fresh:1' => now()->getTimestamp(),
            'stale:2' => now()->subSeconds(700)->getTimestamp(),
        ], now()->addDay());

        $instances = $heartbeat->instances(600);

        $this->assertSame([
            ['instance' => 'fresh:1', 'last_heartbeat_at' => '2026-09-30T10:00:00+00:00', 'stale' => false],
            ['instance' => 'stale:2', 'last_heartbeat_at' => '2026-09-30T09:48:20+00:00', 'stale' => true],
        ], $instances);
        Carbon::setTestNow();
    }
}
