<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Health;

use App\Services\Health\SnapshotMemory;
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

    public function test_a_live_in_memory_record_is_served_without_touching_the_file(): void
    {
        $memory = $this->memory(['expires_at' => 200, 'report' => ['blocking' => false]]);
        $path = storage_path('framework/testing/startup-health-missing-' . bin2hex(random_bytes(5)) . '.json');
        $store = new StartupHealthSnapshotStore($path, static fn(): int => 100, $memory);

        self::assertSame(['blocking' => false], $store->get());
        self::assertFileDoesNotExist($path);
        self::assertSame(0, $memory->forgotten);
    }

    public function test_an_expired_in_memory_record_is_forgotten_and_the_file_decides(): void
    {
        $memory = $this->memory(['expires_at' => 50, 'report' => ['stale' => true]]);
        $store = new StartupHealthSnapshotStore(storage_path('framework/testing/none-' . bin2hex(random_bytes(5)) . '.json'), static fn(): int => 100, $memory);

        self::assertNull($store->get());
        self::assertSame(1, $memory->forgotten);
    }

    public function test_a_live_record_without_a_report_reads_as_no_snapshot(): void
    {
        $store = new StartupHealthSnapshotStore(storage_path('framework/testing/none-' . bin2hex(random_bytes(5)) . '.json'), static fn(): int => 100, $this->memory(['expires_at' => 200]));

        self::assertNull($store->get());
    }

    public function test_put_writes_both_the_memory_and_the_file_with_a_minimum_ttl_of_one_second(): void
    {
        $memory = $this->memory(null);
        $path = storage_path('framework/testing/startup-health-' . bin2hex(random_bytes(5)) . '.json');
        $store = new StartupHealthSnapshotStore($path, static fn(): int => 100, $memory);

        try {
            $store->put(['blocking' => true], 0);

            self::assertSame(['expires_at' => 101, 'report' => ['blocking' => true]], $memory->stored);
            self::assertSame(1, $memory->storedTtl);
            self::assertSame(['expires_at' => 101, 'report' => ['blocking' => true]], json_decode((string) file_get_contents($path), true));
        } finally {
            @unlink($path);
        }
    }

    public function test_a_corrupt_or_expired_file_and_an_unwritable_folder_read_as_no_snapshot(): void
    {
        $path = storage_path('framework/testing/startup-health-' . bin2hex(random_bytes(5)) . '.json');
        @mkdir(dirname($path), 0770, true);
        $store = new StartupHealthSnapshotStore($path, static fn(): int => 100, $this->memory(null));

        try {
            file_put_contents($path, '{not json');
            self::assertNull($store->get());

            file_put_contents($path, json_encode(['expires_at' => 100, 'report' => ['x' => 1]]));
            self::assertNull($store->get(), 'A record that expires exactly now is already expired.');

            file_put_contents($path, json_encode(['expires_at' => 101, 'report' => 'oops']));
            self::assertNull($store->get());
        } finally {
            @unlink($path);
        }

        $blocked = new StartupHealthSnapshotStore('/proc/definitely/not/writable/startup.json', static fn(): int => 100, $this->memory(null));
        $blocked->put(['blocking' => true], 5);
        self::assertNull($blocked->get());
    }

    /** @param array<string, mixed>|null $held */
    private function memory(?array $held): object
    {
        return new class ($held) implements SnapshotMemory {
            public int $forgotten = 0;
            public int $storedTtl = 0;
            /** @var array<string, mixed>|null */
            public ?array $stored = null;

            /** @param array<string, mixed>|null $held */
            public function __construct(private ?array $held) {}

            public function fetch(): ?array
            {
                return $this->held;
            }

            public function store(array $record, int $ttlSeconds): void
            {
                $this->stored = $record;
                $this->storedTtl = $ttlSeconds;
            }

            public function forget(): void
            {
                $this->forgotten++;
                $this->held = null;
            }
        };
    }
}
