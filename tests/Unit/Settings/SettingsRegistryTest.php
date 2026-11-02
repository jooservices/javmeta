<?php

declare(strict_types=1);

namespace Tests\Unit\Settings;

use Illuminate\Support\Arr;
use Tests\TestCase;

final class SettingsRegistryTest extends TestCase
{
    public function test_every_registered_setting_has_a_default_type_range_and_localized_copy(): void
    {
        $schema = config('javmeta_settings');
        $englishSettings = [
            'backup.manifest_path',
            'backup.logical_enabled',
            'backup.logical_interval_hours',
            'backup.logical_dir',
            'backup.logical_keep',
            'backup.manifest_alert_enabled',
        ];
        $this->assertIsArray($schema['groups'] ?? null);
        $this->assertIsArray($schema['definitions'] ?? null);

        foreach ($schema['definitions'] as $key => $definition) {
            $this->assertArrayHasKey($definition['group'], $schema['groups'], $key);
            $this->assertNotSame('', trim((string) ($definition['label'] ?? '')), $key . ' label');
            $this->assertNotSame('', trim((string) ($definition['description'] ?? '')), $key . ' description');
            if (str_starts_with($key, 'normalization.')) {
                $this->assertMatchesRegularExpression('/[ăâđêôơưàáảãạằắẳẵặầấẩẫậèéẻẽẹềếểễệìíỉĩịòóỏõọồốổỗộờớởỡợùúủũụừứửữựỳýỷỹỵ]/iu', $definition['label'] . ' ' . $definition['description'], $key . ' Vietnamese UI text');
            }
            $this->assertContains($definition['type'], ['bool', 'int', 'float', 'string', 'secret', 'int_list', 'string_list', 'json'], $key . ' type');
            $this->assertNotEmpty($definition['config'] ?? [], $key . ' config mapping');
            if (! in_array($key, $englishSettings, true)) {
                $this->assertMatchesRegularExpression('/[ăâđêôơưàáảãạằắẳẵặầấẩẫậèéẻẽẹềếểễệìíỉĩịòóỏõọồốổỗộờớởỡợùúủụừứửữựỳýỷỹỵ]/iu', $definition['description'], $key . ' Vietnamese description');
            }

            foreach ($definition['config'] as $configKey) {
                $this->assertTrue(Arr::has(config()->all(), $configKey), $key . ' default missing at ' . $configKey);
            }

            if (in_array($definition['type'], ['int', 'float'], true)) {
                $this->assertArrayHasKey('min', $definition, $key . ' minimum');
                $this->assertArrayHasKey('max', $definition, $key . ' maximum');
                $this->assertLessThanOrEqual($definition['max'], $definition['min'], $key . ' range');
            } elseif ($definition['type'] === 'json') {
                $this->assertNotEmpty($definition['shape'] ?? [], $key . ' JSON shape');
            } elseif (in_array($definition['type'], ['string', 'secret', 'int_list', 'string_list'], true)) {
                $this->assertGreaterThan(0, $schema['validation']['max_text_length'] ?? 0, $key . ' text range');
                $this->assertGreaterThan(0, $schema['validation']['max_list_items'] ?? 0, $key . ' list range');
            }
        }
    }

    public function test_fetch_method_learning_settings_are_registered(): void
    {
        $definitions = config('javmeta_settings.definitions');

        foreach ([
            'timeouts.playwright_seconds',
            'crawl.fetch_method_window_size',
            'crawl.fetch_method_failure_threshold',
            'crawl.fetch_method_window_ttl_seconds',
            'crawl.fetch_method_preference_ttl_seconds',
            'crawl.fetch_method_probe_interval',
        ] as $key) {
            self::assertArrayHasKey($key, $definitions);
        }
    }

    public function test_feed_diagnostics_settings_are_registered_and_enabled_by_default(): void
    {
        $definitions = config('javmeta_settings.definitions');

        self::assertArrayHasKey('queue.feed_diagnostics.enabled', $definitions);
        self::assertArrayHasKey('queue.feed_diagnostics.retention_minutes', $definitions);
        self::assertTrue(config('javmeta_queues.feed_diagnostics.enabled'));
        self::assertSame(120, config('javmeta_queues.feed_diagnostics.retention_minutes'));
        self::assertSame(3, $definitions['queue.feed_diagnostics.retention_minutes']['min']);
    }

    public function test_source_gap_overrides_setting_is_registered_as_a_json_map(): void
    {
        $definitions = config('javmeta_settings.definitions');
        $definition = $definitions['crawl.source_gap_overrides'];

        self::assertSame('json', $definition['type']);
        self::assertSame('source_gap_override_map', $definition['shape']);
        self::assertSame(['javmeta_queues.source_gap_overrides'], $definition['config']);
        self::assertSame(10000, $definition['max_length']);
        self::assertSame([], config('javmeta_queues.source_gap_overrides'));
    }

    public function test_egress_observability_settings_are_registered_with_safe_defaults(): void
    {
        $definitions = config('javmeta_settings.definitions');

        foreach ([
            'observability.egress_probe_urls',
            'observability.egress_probe_interval_seconds',
            'observability.browser_egress_probe_interval_seconds',
            'observability.egress_probe_timeout_seconds',
            'observability.egress_changes_stream',
            'observability.record_egress_ip',
        ] as $key) {
            self::assertArrayHasKey($key, $definitions);
            self::assertSame('observability', $definitions[$key]['group']);
        }

        self::assertSame(120, config('javmeta_observability.egress_probe_interval_seconds'));
        self::assertSame(900, config('javmeta_observability.browser_egress_probe_interval_seconds'));
        self::assertSame(5, config('javmeta_observability.egress_probe_timeout_seconds'));
        self::assertSame('xcrawler_logs', config('javmeta_observability.egress_changes_stream'));
        self::assertTrue(config('javmeta_observability.record_egress_ip'));
        self::assertSame([
            'https://ifconfig.me',
            'https://icanhazip.com',
            'https://checkip.amazonaws.com',
            'https://api.ipify.org',
        ], config('javmeta_observability.egress_probe_urls'));
    }

    public function test_performer_normalization_settings_expose_canonical_allowlists_and_japan_locations(): void
    {
        $definitions = config('javmeta_settings.definitions');
        self::assertSame('Performer normalization', config('javmeta_settings.groups.normalization'));
        self::assertSame(['A', 'B', 'O', 'AB'], $definitions['normalization.blood_types']['item_options']);
        self::assertSame(range('A', 'K'), $definitions['normalization.cups']['item_options']);
        self::assertSame('performer_field_ranges', $definitions['normalization.field_ranges']['shape']);
        self::assertSame('performer_location_aliases', $definitions['normalization.location_aliases']['shape']);
        self::assertGreaterThan(6000, count(config('javmeta_japan_locations')));
    }

    public function test_logical_backup_settings_are_registered_with_defaults(): void
    {
        $definitions = config('javmeta_settings.definitions');

        foreach ([
            'backup.logical_enabled',
            'backup.logical_interval_hours',
            'backup.logical_dir',
            'backup.logical_keep',
            'backup.manifest_alert_enabled',
        ] as $key) {
            self::assertArrayHasKey($key, $definitions);
            self::assertSame('backup', $definitions[$key]['group']);
        }

        self::assertFalse(config('javmeta_backup.logical_enabled'));
        self::assertSame(24, config('javmeta_backup.logical_interval_hours'));
        self::assertSame(storage_path('app/private/backups'), config('javmeta_backup.logical_dir'));
        self::assertSame(7, config('javmeta_backup.logical_keep'));
        self::assertTrue(config('javmeta_backup.manifest_alert_enabled'));
        self::assertSame(1, $definitions['backup.logical_interval_hours']['min']);
        self::assertSame(8760, $definitions['backup.logical_interval_hours']['max']);
        self::assertSame(1, $definitions['backup.logical_keep']['min']);
        self::assertSame(1000, $definitions['backup.logical_keep']['max']);
        self::assertSame('Backup', config('javmeta_settings.groups.backup'));
        self::assertDoesNotMatchRegularExpression('/[^\\x00-\\x7F]/', config('javmeta_settings.groups.backup'));
        foreach ($definitions as $key => $definition) {
            if (! str_starts_with($key, 'backup.logical_') && ! in_array($key, ['backup.manifest_alert_enabled', 'backup.manifest_path'], true)) {
                continue;
            }

            self::assertSame('backup', $definition['group']);
            self::assertDoesNotMatchRegularExpression('/[^\x00-\x7F]/', $definition['label']);
            self::assertDoesNotMatchRegularExpression('/[^\x00-\x7F]/', $definition['description']);
        }
    }
}
