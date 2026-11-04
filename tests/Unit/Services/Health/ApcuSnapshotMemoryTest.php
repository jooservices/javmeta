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
}
