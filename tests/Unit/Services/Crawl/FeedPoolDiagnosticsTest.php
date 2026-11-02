<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawl;

use App\Services\Crawl\FeedPoolDiagnostics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class FeedPoolDiagnosticsTest extends TestCase
{
    public function test_run_counters_have_ttl_and_export_expected_metric_points(): void
    {
        $minute = CarbonImmutable::parse('2026-11-02T12:34:00Z');
        $this->travelTo($minute);
        config([
            'horizon.use' => 'default',
            'javmeta_queues.feed_diagnostics.enabled' => true,
            'javmeta_queues.feed_diagnostics.retention_minutes' => 15,
        ]);
        $hash = [];
        $ttl = null;
        $redis = Mockery::mock();
        $redis->shouldReceive('pipeline')->once()->andReturn($redis);
        $redis->shouldReceive('exec')->once()->andReturn([]);
        $redis->shouldReceive('hset')->times(3)->andReturnUsing(static function (string $key, string $field, int $value) use (&$hash): int {
            $hash[$field] = $value;

            return 1;
        });
        $redis->shouldReceive('hincrby')->times(5)->andReturnUsing(static function (string $key, string $field, int $value) use (&$hash): int {
            $hash[$field] = (int) ($hash[$field] ?? 0) + $value;

            return $hash[$field];
        });
        $redis->shouldReceive('expire')->once()->andReturnUsing(static function (string $key, int $seconds) use (&$ttl): int {
            $ttl = $seconds;

            return 1;
        });
        $redis->shouldReceive('hgetall')->once()->andReturnUsing(static function (string $key) use (&$hash): array {
            return $hash;
        });
        Redis::shouldReceive('connection')->twice()->with('default')->andReturn($redis);

        $diagnostics = app(FeedPoolDiagnostics::class);
        $diagnostics->recordRun(
            ['ffjav' => ['budget' => 60, 'started' => 32, 'waiting' => 3]],
            ['ffjav' => 2],
            [['queue' => 'listing', 'lane' => 'direct', 'reason' => 'no_due_rows']],
            40,
            2,
        );

        self::assertSame(900, $ttl);
        self::assertSame(60, $hash['s|ffjav|budget']);
        self::assertSame(32, $hash['s|ffjav|started']);
        self::assertSame(3, $hash['s|ffjav|waiting']);
        self::assertSame(2, $hash['s|ffjav|dispatched']);
        self::assertSame(1, $hash['q|listing|direct|no_due_rows']);
        self::assertSame(40, $hash['run_ms_total']);
        self::assertSame(2, $hash['claim_rows_examined']);

        $metrics = $diagnostics->metricPointsForMinute(CarbonImmutable::now());
        $byName = collect($metrics)->groupBy('name');
        self::assertSame(60, $byName['javmeta_feed_source_budget'][0]['value']);
        self::assertSame(32, $byName['javmeta_feed_source_started'][0]['value']);
        self::assertSame(3, $byName['javmeta_feed_source_waiting'][0]['value']);
        self::assertSame(2, $byName['javmeta_feed_source_dispatched_1m'][0]['value']);
        self::assertSame(1, $byName['javmeta_feed_stop_reason_1m'][0]['value']);
        self::assertEquals(40.0, $byName['javmeta_feed_run_ms'][0]['value']);
        self::assertSame(2, $byName['javmeta_feed_claim_rows_examined_1m'][0]['value']);
    }

    public function test_diagnostics_redis_errors_are_swallowed(): void
    {
        config(['javmeta_queues.feed_diagnostics.enabled' => true]);
        $redis = Mockery::mock();
        $redis->shouldReceive('pipeline')->once()->andThrow(new RuntimeException('Redis unavailable.'));
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($redis);

        app(FeedPoolDiagnostics::class)->recordRun([], [], [], 1, 0);

        self::assertTrue(true);
    }

    public function test_report_surfaces_redis_read_errors(): void
    {
        config(['javmeta_queues.feed_diagnostics.enabled' => true]);
        $redis = Mockery::mock();
        $redis->shouldReceive('hgetall')->once()->andThrow(new RuntimeException('Redis unavailable.'));
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($redis);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to read feed diagnostics from Redis.');
        app(FeedPoolDiagnostics::class)->report(10);
    }

    public function test_empty_minute_does_not_emit_claim_rows_zero(): void
    {
        config(['javmeta_queues.feed_diagnostics.enabled' => true]);
        $redis = Mockery::mock();
        $redis->shouldReceive('hgetall')->once()->andReturn([]);
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($redis);

        self::assertSame([], app(FeedPoolDiagnostics::class)->metricPointsForMinute(CarbonImmutable::now()));
    }
}
