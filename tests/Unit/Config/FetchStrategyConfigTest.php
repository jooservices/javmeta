<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * crawlerx owns the fetch strategy (method, profile, per-method timeout);
 * javmeta must not configure it any more.
 */
final class FetchStrategyConfigTest extends TestCase
{
    private const FILES = [
        'config/javmeta_sources.php',
        'app/Services/Crawler/CrawlerxClient.php',
        '.env.example',
        'deploy/production.env.example',
        'docker-compose.yml',
        'README.md',
    ];

    public function test_no_fetch_profile_or_timeout_is_configured(): void
    {
        $root = dirname(__DIR__, 3);

        foreach (self::FILES as $file) {
            $contents = (string) file_get_contents($root . '/' . $file);
            self::assertStringNotContainsString('JAVMETA_FETCH_PROFILE', $contents, $file);
            self::assertStringNotContainsString('JAVMETA_FETCH_TIMEOUT', $contents, $file);
            self::assertStringNotContainsString('FetchProfile', $contents, $file);
            self::assertStringNotContainsString('HttpOptionsDto', $contents, $file);
        }
    }
}
