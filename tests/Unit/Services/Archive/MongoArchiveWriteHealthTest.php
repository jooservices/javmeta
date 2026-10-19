<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Archive;

use App\Services\Archive\MongoArchiveWriteHealth;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class MongoArchiveWriteHealthTest extends TestCase
{
    public function test_failure_count_is_cumulative_while_status_recovers_after_a_success(): void
    {
        Cache::store('array')->flush();
        $health = app(MongoArchiveWriteHealth::class);

        $health->recordFailure();
        $health->recordFailure();

        self::assertSame('degraded', $health->status()['status']);
        self::assertSame(2, $health->status()['failed_writes']);
        self::assertNotNull($health->status()['last_failure_at']);

        $health->recordSuccess();

        self::assertSame('ok', $health->status()['status']);
        self::assertSame(2, $health->failureCount());
    }
}
