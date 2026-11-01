<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dependencies\Probes;

use App\Services\Dependencies\Probes\MongoProbe;
use RuntimeException;
use Tests\TestCase;

final class MongoProbeTest extends TestCase
{
    public function test_it_reports_a_successful_ping_as_healthy(): void
    {
        $ping = static function (): void {};
        $readSettings = static function (): void {};

        self::assertTrue((new MongoProbe($ping, $readSettings))->probe());
    }

    public function test_it_reports_a_failed_ping_as_down(): void
    {
        $ping = static function (): void {
            throw new RuntimeException(fake()->sentence());
        };

        self::assertFalse((new MongoProbe($ping, static function (): void {}))->probe());
    }

    public function test_it_fails_when_the_settings_store_cannot_be_read(): void
    {
        $readSettings = static function (): void {
            throw new RuntimeException(fake()->sentence());
        };

        $result = (new MongoProbe(static function (): void {}, $readSettings))->check();

        self::assertFalse($result->available);
        self::assertSame('MongoDB settings-store read failed.', $result->message);
    }
}
