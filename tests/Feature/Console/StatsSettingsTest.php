<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Tests\TestCase;

final class StatsSettingsTest extends TestCase
{
    public function test_stats_switch_and_retention_are_registered_with_database_config_defaults(): void
    {
        $settings = require config_path('javmeta_settings.php');

        self::assertSame('Thống kê crawl', $settings['groups']['stats']);
        self::assertSame(['javmeta_stats.enabled'], $settings['definitions']['stats.enabled']['config']);
        self::assertSame(['javmeta_stats.retention_days'], $settings['definitions']['stats.retention_days']['config']);
        self::assertTrue(config('javmeta_stats.enabled'));
        self::assertSame(365, config('javmeta_stats.retention_days'));
    }
}
