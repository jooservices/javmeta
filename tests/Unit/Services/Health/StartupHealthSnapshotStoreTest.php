<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Health;

use App\Services\Health\StartupHealthSnapshotStore;
use Tests\TestCase;

final class StartupHealthSnapshotStoreTest extends TestCase
{
    public function test_it_reads_a_snapshot_until_the_ttl_expires(): void
    {
        $now = 100;
        $path = storage_path('framework/testing/startup-health-' . bin2hex(random_bytes(5)) . '.json');
        $store = new StartupHealthSnapshotStore($path, static function () use (&$now): int {
            return $now;
        });

        try {
            $store->put(['blocking' => true], 5);
            self::assertSame(['blocking' => true], $store->get());
            $now += 5;
            self::assertNull($store->get());
        } finally {
            @unlink($path);
        }
    }
}
