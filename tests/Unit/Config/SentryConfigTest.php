<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

final class SentryConfigTest extends TestCase
{
    public function test_options_include_the_deployed_release_and_node_name_without_changing_environment(): void
    {
        $previousRelease = getenv('SENTRY_RELEASE');
        $previousEnvironment = getenv('SENTRY_ENVIRONMENT');
        $previousNodeName = getenv('JAVMETA_NODE_NAME');

        try {
            putenv('SENTRY_RELEASE=release-full-sha');
            putenv('SENTRY_ENVIRONMENT=production');
            putenv('JAVMETA_NODE_NAME=xcrawler-worker-1');

            $configuration = require dirname(__DIR__, 3) . '/config/sentry.php';

            self::assertSame('release-full-sha', $configuration['release']);
            self::assertSame('production', $configuration['environment']);
            self::assertSame('xcrawler-worker-1', $configuration['server_name']);
        } finally {
            self::restoreEnvironment('SENTRY_RELEASE', $previousRelease);
            self::restoreEnvironment('SENTRY_ENVIRONMENT', $previousEnvironment);
            self::restoreEnvironment('JAVMETA_NODE_NAME', $previousNodeName);
        }
    }

    private static function restoreEnvironment(string $name, string|false $value): void
    {
        if ($value === false) {
            putenv($name);

            return;
        }

        putenv("{$name}={$value}");
    }
}
