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
        putenv('CRAWLERX_FLARESOLVERR_URL');
    }

    protected function tearDown(): void
    {
        putenv('FLARESOLVERR_URL');
        putenv('CRAWLERX_FLARESOLVERR_URL');
    }

    public function test_new_name_is_kept(): void
    {
        putenv('FLARESOLVERR_URL=http://new/v1');
        putenv('CRAWLERX_FLARESOLVERR_URL=http://old/v1');

        BrowserServiceEnvironment::applyAliases();

        self::assertSame('http://new/v1', getenv('FLARESOLVERR_URL'));
    }

    public function test_old_name_is_used_when_the_new_one_is_missing(): void
    {
        putenv('CRAWLERX_FLARESOLVERR_URL=http://old/v1');

        BrowserServiceEnvironment::applyAliases();

        self::assertSame('http://old/v1', getenv('FLARESOLVERR_URL'));
    }

    public function test_nothing_is_set_when_neither_name_exists(): void
    {
        BrowserServiceEnvironment::applyAliases();

        self::assertFalse(getenv('FLARESOLVERR_URL'));
    }
}
