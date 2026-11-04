<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawl;

use App\Services\Crawl\SourceRequestPacer;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

final class SourceRequestPacerTest extends TestCase
{
    public function test_reserves_sequential_subsecond_start_slots_atomically(): void
    {
        $redis = Mockery::mock();
        $redis->shouldReceive('eval')->times(3)->withArgs(static function (string $script, array $arguments, int $keyCount): bool {
            return str_contains($script, 'local last = tonumber(redis.call(\'GET\', KEYS[1]))')
                && str_contains($script, 'last + gap')
                && $keyCount === 1
                && in_array($arguments, [
                    ['javmeta:source-gap:start:direct:fast-site', '250000', '60000', '1000000'],
                    ['javmeta:source-gap:start:retry:fast-site', '250000', '60000', '1000000'],
                ], true);
        })->andReturn(0, 250000, 0);
        Redis::shouldReceive('connection')->times(3)->with('default')->andReturn($redis);

        $pacer = new SourceRequestPacer();

        self::assertSame(0, $pacer->reserveStart('fast-site', 0.25, 'direct', 1_000_000));
        self::assertSame(250000, $pacer->reserveStart('fast-site', 0.25, 'direct', 1_000_000));
        self::assertSame(0, $pacer->reserveStart('fast-site', 0.25, 'retry', 1_000_000));
    }

    public function test_a_start_slot_further_away_than_the_maximum_wait_is_refused(): void
    {
        $redis = Mockery::mock();
        $redis->shouldReceive('eval')->once()->andReturn(-1);
        Redis::shouldReceive('connection')->once()->andReturn($redis);

        self::assertNull((new SourceRequestPacer())->reserveStart('slow-site', 2.0, 'direct', 500_000));
    }

    public function test_an_unreadable_redis_answer_is_an_error(): void
    {
        foreach (['unexpected', -2] as $answer) {
            $redis = Mockery::mock();
            $redis->shouldReceive('eval')->once()->andReturn($answer);
            Redis::shouldReceive('connection')->once()->andReturn($redis);

            try {
                (new SourceRequestPacer())->reserveStart('site', 1.0, 'direct', 1_000_000);
                self::fail('A bad Redis answer must not be treated as a start slot.');
            } catch (\RuntimeException $error) {
                self::assertSame('Unable to reserve a source request start slot.', $error->getMessage());
            }
        }
    }

    public function test_waiting_sleeps_in_slices_and_returns_after_the_requested_time(): void
    {
        $started = microtime(true);

        (new SourceRequestPacer())->wait(20_000);
        (new SourceRequestPacer())->wait(0);
        (new SourceRequestPacer())->wait(-5);

        $elapsed = microtime(true) - $started;
        self::assertGreaterThanOrEqual(0.019, $elapsed);
        self::assertLessThan(0.5, $elapsed);
    }
}
