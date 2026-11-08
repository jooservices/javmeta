<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\BrowserServiceEnvironment;
use PHPUnit\Framework\TestCase;

final class BrowserServiceEnvironmentTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('FLARESOLVERR_URL');
        putenv('PLAYWRIGHT_URL');
    }

    protected function tearDown(): void
    {
        putenv('FLARESOLVERR_URL');
        putenv('PLAYWRIGHT_URL');
    }

    public function test_database_setting_maps_to_flaresolverr_runtime_variable(): void
    {
        self::assertTrue(BrowserServiceEnvironment::apply('browser.flaresolverr_url', 'http://service/v1'));
        self::assertSame('http://service/v1', getenv('FLARESOLVERR_URL'));
    }

    public function test_database_setting_maps_to_playwright_runtime_variable(): void
    {
        self::assertTrue(BrowserServiceEnvironment::apply('browser.playwright_url', 'http://service:3000'));
        self::assertSame('http://service:3000', getenv('PLAYWRIGHT_URL'));
    }

    public function test_unchanged_or_unknown_settings_do_not_update_environment(): void
    {
        putenv('FLARESOLVERR_URL=http://service/v1');
        self::assertFalse(BrowserServiceEnvironment::apply('browser.flaresolverr_url', 'http://service/v1'));
        self::assertFalse(BrowserServiceEnvironment::apply('worker.default', '2'));
        self::assertSame('http://service/v1', getenv('FLARESOLVERR_URL'));
    }
}
