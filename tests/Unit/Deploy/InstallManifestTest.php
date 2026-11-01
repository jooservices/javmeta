<?php

declare(strict_types=1);

namespace Tests\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InstallManifestTest extends TestCase
{
    public function test_manifest_covers_every_example_key_and_never_defaults_a_secret_or_ref(): void
    {
        $root = dirname(__DIR__, 3);
        $manifest = json_decode(file_get_contents($root . '/deploy/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $fields = array_merge(...array_column($manifest['services'], 'fields'));
        $registry = require $root . '/config/javmeta_settings.php';
        foreach ($fields as $field) {
            if (isset($field['setting'])) {
                $this->assertArrayHasKey($field['setting'], $registry['definitions']);
            }
        }
        preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', file_get_contents($root . '/deploy/production/env.production.example'), $matches);
        $this->assertSame([], array_values(array_diff($matches[1], array_column($fields, 'key'))));
        $all = array_merge($manifest['options'], $fields);
        $this->assertCount(count(array_unique(array_column($all, 'key'))), $all);
        foreach ($all as $field) {
            if ($field['secret'] || $field['key'] === 'REF') {
                $this->assertSame('', $field['default'], $field['key']);
            }
        }
        $this->assertSame(['application', 'postgres', 'mongo', 'redis', 'elasticsearch', 'embedder', 'flare', 'playwright', 'openobserve', 'sentry', 'legacy', 'ollama', 'telegram'], array_column($manifest['services'], 'id'));
    }

    public function test_probe_rejects_non_http_urls_before_opening_a_socket(): void
    {
        require_once dirname(__DIR__, 3) . '/deploy/lib/probe.php';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid HTTP URL');
        \JavMeta\Deploy\request('file:///etc/passwd');
    }

    public function test_auth_defaults_follow_the_database_and_the_bundled_redis_acl(): void
    {
        $root = dirname(__DIR__, 3);
        $manifest = json_decode(file_get_contents($root . '/deploy/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $fields = array_column(array_merge(...array_column($manifest['services'], 'fields')), null, 'key');
        $this->assertSame('@MONGO_DATABASE@', $fields['MONGO_AUTH_SOURCE']['default']);
        $acl = file_get_contents($root . '/deploy/production/provision/redis-acl.txt');
        foreach (['REDIS_PREFIX', 'CACHE_PREFIX', 'HORIZON_PREFIX'] as $key) {
            $this->assertStringStartsWith($fields['REDIS_PREFIX']['default'], $fields[$key]['default']);
        }
        $this->assertStringContainsString('~' . $fields['REDIS_PREFIX']['default'] . '*', $acl);
    }

    public function test_guest_login_switch_is_optional_non_secret_and_defaults_to_required(): void
    {
        $root = dirname(__DIR__, 3);
        $manifest = json_decode(file_get_contents($root . '/deploy/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $fields = array_merge($manifest['options'], ...array_column($manifest['services'], 'fields'));
        $field = array_values(array_filter($fields, static fn(array $item): bool => $item['key'] === 'JAVMETA_UI_LOGIN_REQUIRED'))[0] ?? null;

        $this->assertIsArray($field);
        $this->assertFalse($field['secret']);
        $this->assertFalse($field['required']);
        $this->assertSame('true', $field['default']);
        $this->assertSame('ui_security.login_required', $field['setting']);
        $this->assertArrayNotHasKey('env', $field);
        $this->assertStringNotContainsString('JAVMETA_UI_LOGIN_REQUIRED', file_get_contents($root . '/.env.example'));
    }

    public function test_probe_has_no_dependency_on_composer_before_installation(): void
    {
        $probe = file_get_contents(dirname(__DIR__, 3) . '/deploy/lib/probe.php');
        $this->assertStringNotContainsString('Symfony\\Component', $probe);
        $this->assertStringContainsString("proc_open(['psql'", $probe);
    }

    public function test_worker_installer_blocks_web_and_scheduler_and_enables_exporter(): void
    {
        $installer = file_get_contents(dirname(__DIR__, 3) . '/deploy/production/node/install-node.sh');
        $this->assertStringContainsString('configure_node_role', $installer);
        $roles = file_get_contents(dirname(__DIR__, 3) . '/deploy/lib/node-template.sh');
        $this->assertStringContainsString('worker-role.conf', $roles);
        $this->assertStringContainsString('ConditionPathExists=', $roles);
        $this->assertStringContainsString('node_control disable --now "$unit"', $roles);
        $engine = file_get_contents(dirname(__DIR__, 3) . '/deploy/lib/install.sh');
        $this->assertStringContainsString('${answers[EXPORTER_UNIT]}', $engine);
    }
}
