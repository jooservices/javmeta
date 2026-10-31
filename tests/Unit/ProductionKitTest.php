<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ProductionKitTest extends TestCase
{
    public function test_production_template_keeps_secrets_empty_and_staging_defaults_unchanged(): void
    {
        $root = dirname(__DIR__, 2);
        $template = (string) file_get_contents($root . '/deploy/production/env.production.example');
        foreach (['APP_NAME=XCrawler', 'APP_ENV=production', 'APP_URL=https://xcrawler.net', 'DB_DATABASE=xcrawler', 'DB_USERNAME=xcrawler', 'REDIS_USERNAME=xcrawler', 'REDIS_PREFIX=xcrawler:', 'HORIZON_PREFIX=xcrawler:horizon:', 'ELASTICSEARCH_MOVIES_INDEX=xcrawler_movies', 'ELASTICSEARCH_PERFORMERS_INDEX=xcrawler_performers'] as $line) {
            self::assertStringContainsString($line . "\n", $template);
        }
        foreach (['APP_KEY', 'DB_PASSWORD', 'MONGO_URI', 'REDIS_PASSWORD', 'ELASTICSEARCH_API_KEY', 'OPENOBSERVE_PASSWORD', 'JAVMETA_SENTRY_DSN', 'JAVMETA_OWNER_ADMIN_TOKEN', 'JAVMETA_MCP_API_KEY'] as $key) {
            self::assertMatchesRegularExpression('/^' . $key . '=$/m', $template);
        }
        foreach (['DOMAINS_', 'TELEGRAM_', 'JAVMETA_QUEUE_', 'JAVMETA_FRESH_', 'OLLAMA_', 'ELASTICSEARCH_SCENES_INDEX'] as $prefix) {
            self::assertDoesNotMatchRegularExpression('/^' . $prefix . '/m', $template);
        }
        $domains = require $root . '/config/javmeta_domains.php';
        self::assertSame('jav.soulevil.com', $domains['ui_host']);
        self::assertSame('jav-cc.soulevil.com', $domains['command_center_host']);
        // Scene index names are UI/database settings, never new environment fallbacks.
        self::assertStringContainsString("'scenes_index' => 'javmeta_scenes'", (string) file_get_contents($root . '/config/javmeta_media.php'));
    }

    public function test_runbook_setting_keys_exist_and_host_rules_keep_the_command_center_isolated(): void
    {
        $root = dirname(__DIR__, 2);
        $registry = require $root . '/config/javmeta_settings.php';
        $readme = (string) file_get_contents($root . '/deploy/production/README.md');
        preg_match_all('/`((?:domains|search|media|ai|observability|telegram|queue|crawl)\.[a-z_]+)`/', $readme, $matches);
        self::assertNotEmpty($matches[1]);
        foreach (array_unique($matches[1]) as $key) {
            self::assertArrayHasKey($key, $registry['definitions']);
        }
        $haproxy = (string) file_get_contents($root . '/deploy/production/haproxy/xcrawler.cfg');
        self::assertStringContainsString('cc.xcrawler.net', $haproxy);
        self::assertStringContainsString('if xcrawler_cc !xcrawler_cc_root !xcrawler_cc_paths', $haproxy);
        self::assertStringContainsString('^/(ops|horizon|vendor/horizon|login|logout)(/|$)', $haproxy);
        self::assertStringContainsString('if xcrawler_ui xcrawler_ops', $haproxy);
        self::assertStringContainsString('192.168.10.20:80', $haproxy);
    }
}
