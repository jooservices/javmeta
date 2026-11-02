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
                    ['javmeta:source-gap:start:direct:fast-site', '250000', '60000'],
                    ['javmeta:source-gap:start:retry:fast-site', '250000', '60000'],
                ], true);
        })->andReturn(0, 250000, 0);
        Redis::shouldReceive('connection')->times(3)->with('default')->andReturn($redis);

        $pacer = new SourceRequestPacer();

        self::assertSame(0, $pacer->reserveStart('fast-site', 0.25));
        self::assertSame(250000, $pacer->reserveStart('fast-site', 0.25));
        self::assertSame(0, $pacer->reserveStart('fast-site', 0.25, 'retry'));
    }
}
