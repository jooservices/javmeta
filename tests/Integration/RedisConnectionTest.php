<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

final class RedisConnectionTest extends TestCase
{
    public function test_it_round_trips_a_key_with_the_integration_prefix(): void
    {
        $host = getenv('REDIS_HOST');

        if ($host === false || $host === '') {
            $this->markTestSkipped('REDIS_HOST is not set; skipping the Redis integration test.');
        }

        self::assertSame('javmeta_it:', config('database.redis.options.prefix'));

        $key = 'integration:' . bin2hex(random_bytes(12));
        $value = bin2hex(random_bytes(16));
        $redis = Redis::connection();

        try {
            $redis->set($key, $value);

            self::assertSame($value, $redis->get($key));
            self::assertSame(1, $redis->del($key));
            self::assertFalse((bool) $redis->exists($key));
        } finally {
            $redis->del($key);
        }
    }
}
