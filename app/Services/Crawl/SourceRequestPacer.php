<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use Illuminate\Support\Facades\Redis;
use RuntimeException;

final class SourceRequestPacer
{
    private const RESERVE_START = <<<'LUA'
local time = redis.call('TIME')
local now = time[1] * 1000000 + time[2]
local last = tonumber(redis.call('GET', KEYS[1]))
local gap = tonumber(ARGV[1])
local start = now
if last then
    start = math.max(now, last + gap)
end
local wait = start - now
local maximum_wait = tonumber(ARGV[3])
if wait > maximum_wait then
    return -1
end
redis.call('SET', KEYS[1], start, 'PX', ARGV[2])
return wait
LUA;

    public function reserveStart(string $sourceSlug, float $gapSeconds, string $lane, int $maximumWaitMicroseconds): ?int
    {
        $gapMicroseconds = (int) round($gapSeconds * 1_000_000);
        $ttlMilliseconds = max(60_000, (int) ceil($gapMicroseconds * 2 / 1000));
        $wait = Redis::connection((string) config('horizon.use', 'default'))->eval(
            self::RESERVE_START,
            [
                'javmeta:source-gap:start:' . $lane . ':' . rawurlencode($sourceSlug),
                (string) $gapMicroseconds,
                (string) $ttlMilliseconds,
                (string) $maximumWaitMicroseconds,
            ],
            1,
        );

        if (! is_numeric($wait) || $wait < -1) {
            throw new RuntimeException('Unable to reserve a source request start slot.');
        }

        if ((int) $wait === -1) {
            return null;
        }

        return (int) $wait;
    }

    public function wait(int $microseconds): void
    {
        while ($microseconds > 0) {
            $slice = min(500_000, $microseconds);
            usleep($slice);
            $microseconds -= $slice;
        }
    }
}
