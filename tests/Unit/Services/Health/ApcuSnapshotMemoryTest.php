<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Health;

use App\Services\Health\ApcuSnapshotMemory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApcuSnapshotMemory::class)]
final class ApcuSnapshotMemoryTest extends TestCase
{
    public function test_it_stores_fetches_and_forgets_a_record_when_apcu_is_enabled(): void
    {
        if (! function_exists('apcu_enabled') || ! apcu_enabled()) {
            self::markTestSkipped('APCu is not enabled for the CLI (apc.enable_cli=1 is required).');
        }
        $memory = new ApcuSnapshotMemory();
        $memory->forget();
        self::assertNull($memory->fetch());

        $memory->store(['expires_at' => 9, 'report' => ['a' => 1]], 0);
        self::assertSame(['expires_at' => 9, 'report' => ['a' => 1]], $memory->fetch());

        $memory->forget();
        self::assertNull($memory->fetch());
    }

    public function test_it_holds_nothing_and_never_fails_when_apcu_is_off(): void
    {
        if (function_exists('apcu_enabled') && apcu_enabled()) {
            self::markTestSkipped('APCu is enabled; the on-path test covers this class.');
        }
        $memory = new ApcuSnapshotMemory();

        $memory->store(['expires_at' => 9], 5);
        $memory->forget();

        self::assertNull($memory->fetch());
    }

    public function test_it_reads_writes_and_forgets_through_the_extension_functions_when_enabled(): void
    {
        $calls = [];
        $memory = new ApcuSnapshotMemory(
            static fn(): bool => true,
            static function (string $key, bool &$found) use (&$calls): mixed {
                $calls[] = ['fetch', $key];
                $found = true;

                return ['expires_at' => 9];
            },
            static function (string $key, array $record, int $ttl) use (&$calls): bool {
                $calls[] = ['store', $key, $record, $ttl];

                return true;
            },
            static function (string $key) use (&$calls): bool {
                $calls[] = ['delete', $key];

                return true;
            },
        );

        self::assertSame(['expires_at' => 9], $memory->fetch());
        $memory->store(['expires_at' => 10], 0);
        $memory->forget();

        self::assertSame([
            ['fetch', 'javmeta.startup-health.snapshot'],
            ['store', 'javmeta.startup-health.snapshot', ['expires_at' => 10], 1],
            ['delete', 'javmeta.startup-health.snapshot'],
        ], $calls);
    }

    public function test_a_missing_or_malformed_entry_reads_as_nothing(): void
    {
        $missing = new ApcuSnapshotMemory(static fn(): bool => true, static function (string $key, bool &$found): mixed {
            $found = false;

            return false;
        });
        $malformed = new ApcuSnapshotMemory(static fn(): bool => true, static function (string $key, bool &$found): mixed {
            $found = true;

            return 'not an array';
        });

        self::assertNull($missing->fetch());
        self::assertNull($malformed->fetch());
    }

    public function test_it_never_touches_the_extension_when_disabled(): void
    {
        $never = static function (): never {
            throw new \LogicException('The extension must not be used while disabled.');
        };
        $memory = new ApcuSnapshotMemory(static fn(): bool => false, $never, $never, $never);

        self::assertNull($memory->fetch());
        $memory->store(['a' => 1], 5);
        $memory->forget();
        $this->addToAssertionCount(1);
    }
}
