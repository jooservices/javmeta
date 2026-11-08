<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

final class QueueConfigurationTest extends TestCase
{
    public function test_database_retry_after_exceeds_worker_timeout_by_default(): void
    {
        self::assertGreaterThan(
            config('javmeta_queues.worker_timeout'),
            config('queue.connections.database.retry_after'),
        );
    }
}
