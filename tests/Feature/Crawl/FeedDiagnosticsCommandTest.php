<?php

declare(strict_types=1);

namespace Tests\Feature\Crawl;

use App\Models\CrawlQueue;
use App\Models\Source;
use App\Jobs\FetchListingJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class FeedDiagnosticsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_run_records_exhausted_empty_and_depth_stop_reasons_for_diagnostics(): void
    {
        $this->travelTo('2026-11-02T12:34:00Z');
        config([
            'javmeta_queues.feed_diagnostics.enabled' => true,
            'javmeta_queues.feed_diagnostics.retention_minutes' => 120,
            'javmeta_queues.depth_limit' => 1,
            'queue.default' => 'redis',
            'queue.connections.redis.driver' => 'redis',
            'queue.connections.redis.connection' => 'default',
        ]);
        Queue::fake();

        $hash = [];
        $redis = $this->diagnosticRedis($hash);
        Redis::shouldReceive('connection')->andReturn($redis);

        $this->artisan('crawler:feed-pool')->assertSuccessful();
        self::assertSame(1, $hash['q|listing|direct|no_due_rows']);
        self::assertSame(1, $hash['q|detail|direct|depth']);

        Source::query()->create([
            'slug' => 'budgeted', 'name' => 'budgeted', 'base_url' => 'https://budgeted.test', 'enabled' => true, 'priority' => 1,
            'needs_proxy' => false, 'gap_seconds_default' => 120, 'gap_seconds_min' => 1, 'gap_seconds_max' => 120, 'gap_seconds_current' => 120,
        ]);
        CrawlQueue::factory()->create([
            'source_slug' => 'budgeted',
            'kind' => CrawlQueue::KIND_DETAIL,
            'status' => CrawlQueue::STATUS_CLAIMED,
            'claimed_at' => now(),
            'started_at' => null,
        ]);
        CrawlQueue::factory()->create([
            'source_slug' => 'budgeted',
            'kind' => CrawlQueue::KIND_GALLERY,
            'status' => CrawlQueue::STATUS_PENDING,
            'next_attempt_at' => null,
        ]);
        $this->artisan('crawler:feed-pool')->assertSuccessful();

        self::assertSame(1, $hash['s|budgeted|budget']);
        self::assertSame(1, $hash['q|gallery|direct|exhausted_budget']);
        config()->set('javmeta_queues.source_gap_overrides', [
            'budgeted' => ['min' => 10, 'default' => 20, 'max' => 30],
        ]);
        $output = new BufferedOutput();
        self::assertSame(0, Artisan::call('crawler:feed-diagnostics', ['--minutes' => 10], $output));
        $display = $output->fetch();
        self::assertStringContainsString('budgeted', $display);
        self::assertStringContainsString('Current effective gap (s)', $display);
        self::assertStringContainsString('20.00', $display);
        self::assertStringContainsString('yes', $display);
        self::assertStringContainsString('Current adaptive gap (s)', $display);
        self::assertStringContainsString('30.00', $display);
        self::assertStringContainsString('exhausted_budget', $display);
    }

    public function test_instrumentation_redis_failure_does_not_break_feed_dispatch(): void
    {
        config([
            'javmeta_queues.feed_diagnostics.enabled' => true,
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'database',
        ]);
        $row = CrawlQueue::factory()->create([
            'source_slug' => fake()->slug(2),
            'kind' => CrawlQueue::KIND_LISTING,
        ]);
        Queue::fake();
        $redis = Mockery::mock();
        $redis->shouldReceive('pipeline')->once()->andThrow(new RuntimeException('Redis unavailable.'));
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($redis);

        $this->artisan('crawler:feed-pool', ['--limit' => 1])->assertSuccessful();

        Queue::assertPushed(FetchListingJob::class, 1);
        self::assertSame(CrawlQueue::STATUS_CLAIMED, $row->refresh()->status);
    }

    public function test_diagnostics_command_reports_redis_read_failure(): void
    {
        config(['javmeta_queues.feed_diagnostics.enabled' => true]);
        $redis = Mockery::mock();
        $redis->shouldReceive('hgetall')->once()->andThrow(new RuntimeException('Redis unavailable.'));
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($redis);

        $this->artisan('crawler:feed-diagnostics', ['--minutes' => 10])
            ->expectsOutput('Unable to read feed diagnostics from Redis.')
            ->assertFailed();
    }

    /** @param array<string, int> $hash */
    private function diagnosticRedis(array &$hash): object
    {
        $redis = Mockery::mock();
        $redis->shouldReceive('llen')->andReturnUsing(static fn(string $key): int => $key === 'queues:detail' ? 1 : 0);
        $redis->shouldReceive('pipeline')->andReturn($redis);
        $redis->shouldReceive('exec')->andReturn([]);
        $redis->shouldReceive('hset')->andReturnUsing(static function (string $key, string $field, int $value) use (&$hash): int {
            $hash[$field] = $value;

            return 1;
        });
        $redis->shouldReceive('hincrby')->andReturnUsing(static function (string $key, string $field, int $value) use (&$hash): int {
            $hash[$field] = (int) ($hash[$field] ?? 0) + $value;

            return $hash[$field];
        });
        $redis->shouldReceive('expire')->andReturn(1);
        $currentKey = 'javmeta:feed-pool:diagnostics:' . now()->startOfMinute()->timestamp;
        $redis->shouldReceive('hgetall')->andReturnUsing(static function (string $key) use (&$hash, $currentKey): array {
            return $key === $currentKey ? $hash : [];
        });

        return $redis;
    }
}
