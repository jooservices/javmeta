<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Crawl\SourceRequestPacer;
use Illuminate\Support\Facades\Redis;

final class SourceRequestPacerRedisTest extends IntegrationTestCase
{
    public function test_reservation_uses_real_redis_and_preserves_the_per_source_lock(): void
    {
        self::assertSame('redis', config('database.redis.default.host'));
        self::assertSame(6379, (int) config('database.redis.default.port'));
        self::assertStringStartsWith('javmeta_it:', (string) config('database.redis.options.prefix'));
        Redis::purge('default');
        $pacer = new SourceRequestPacer();
        $source = 'integration-' . bin2hex(random_bytes(6));

        self::assertSame(0, $pacer->reserveStart($source, 30, 'direct', 0));
        self::assertNull($pacer->reserveStart($source, 30, 'direct', 0));
    }
}
