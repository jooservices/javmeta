<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

final class FreshListingIntervalConfigTest extends TestCase
{
    public function test_fresh_listing_interval_uses_the_environment_value_and_defaults_to_sixty_minutes(): void
    {
        $root = dirname(__DIR__, 3);
        $config = require $root . '/config/javmeta_sources.php';
        $expected = max(1, (int) env('JAVMETA_FRESH_INTERVAL_MINUTES', 60));

        self::assertSame($expected, $config['defaults']['loop']['fresh_interval_minutes']);
    }
}
