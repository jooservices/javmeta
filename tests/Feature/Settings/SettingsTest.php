<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Source;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Monolog\Logger;
use Monolog\Handler\TestHandler;
use App\Logging\RedactUrlSecrets;
use App\Observability\TelemetrySanitizer;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;
use Tests\TestCase;
use RuntimeException;

final class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private FakeConfigStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::forgetDefaults();
        $this->store = new FakeConfigStore();
        $this->app->instance(ConfigStore::class, $this->store);
        $this->app->forgetInstance(SettingsService::class);
    }

    protected function tearDown(): void
    {
        SettingsService::forgetDefaults();
        putenv('FLARESOLVERR_URL');
        putenv('PLAYWRIGHT_URL');
        parent::tearDown();
    }

    public function test_a_saved_value_overrides_the_config_and_the_default_stays_without_one(): void
    {
        config(['javmeta_queues.feed_pool_limit' => 50]);
        $settings = app(SettingsService::class);

        $this->assertSame(50, $settings->get('queue.feed_pool_limit'));
        $settings->set('queue.feed_pool_limit', '300');

        $this->assertSame(300, config('javmeta_queues.feed_pool_limit'));
        $this->assertSame(300, $settings->get('queue.feed_pool_limit'));

        // A fresh process: the database value is applied over the env default.
        config(['javmeta_queues.feed_pool_limit' => 50]);
        $settings->apply();
        $this->assertSame(300, config('javmeta_queues.feed_pool_limit'));
    }

    public function test_legacy_natural_language_timeout_is_clamped_to_the_parser_deadline(): void
    {
        $this->store->set('settings.ai.nl_timeout_seconds', 60);
        $settings = app(SettingsService::class);

        $settings->apply();

        $this->assertSame(2, config('javmeta_ai.nl_timeout_seconds'));
        $row = collect($settings->all())->firstWhere('key', 'ai.nl_timeout_seconds');
        $this->assertSame(2, $row['value']);
        $this->assertSame(2, $row['max']);
        $this->assertSame('database', $row['source']);
    }

    public function test_values_are_validated(): void
    {
        $settings = app(SettingsService::class);

        foreach ([['queue.feed_pool_limit', 0], ['queue.feed_pool_limit', 'abc'], ['queue.feed_pool_limit', 99999], ['alerts.enabled', 'maybe'], ['crawl.block_delays_seconds', '60,x'], ['nope.key', 1]] as [$key, $value]) {
            try {
                $settings->set($key, $value);
                $this->fail("{$key}={$value} should be refused");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $settings->set('crawl.block_delays_seconds', '30, 90,180');
        $this->assertSame([30, 90, 180], config('javmeta_sources.defaults.queue.block_delays_seconds'));
        $settings->set('alerts.enabled', 'false');
        $this->assertFalse(config('javmeta_alerts.enabled'));
    }

    public function test_feed_interval_accepts_only_supported_values_and_can_be_rolled_back(): void
    {
        $settings = app(SettingsService::class);
        foreach ([5, 10, 15, 30, 60] as $interval) {
            $settings->set('queue.feed_interval_seconds', $interval);
            $this->assertSame($interval, config('javmeta_queues.feed_interval_seconds'));
        }

        try {
            $settings->set('queue.feed_interval_seconds', 11);
            $this->fail('Unsupported feed intervals should be refused.');
        } catch (\InvalidArgumentException) {
            $this->assertSame(60, config('javmeta_queues.feed_interval_seconds'));
        }

        $setting = collect($settings->all())->firstWhere('key', 'queue.feed_interval_seconds');
        $this->assertSame('database', $setting['source']);
        $this->assertSame(60, $setting['value']);
        $this->assertSame([5, 10, 15, 30, 60], $setting['options']);
        $this->assertSame('Chu kỳ nạp hàng đợi (giây)', $setting['label']);
    }

    public function test_retry_fetch_profile_is_database_backed_validated_and_applied(): void
    {
        $settings = app(SettingsService::class);
        $profile = [
            'flaresolverr_url' => 'http://retry-browser.internal:8191',
            'playwright_url' => 'http://retry-playwright.internal:3000',
            'http_proxy_url' => 'http://retry-egress.internal:8080',
            'egress_label' => 'clean-egress',
            'preferred_methods' => ['http', 'playwright'],
        ];
        $settings->set('retry.fetch_profile', $profile);

        self::assertSame($profile, config('javmeta_queues.retry.fetch_profile'));
        self::assertSame($profile, $settings->get('retry.fetch_profile'));
        try {
            $settings->set('retry.fetch_profile', ['http_proxy_url' => 'ftp://invalid.test']);
            self::fail('Retry profile must reject non-HTTP proxy URLs.');
        } catch (\InvalidArgumentException) {
            self::assertSame($profile, config('javmeta_queues.retry.fetch_profile'));
        }
        try {
            $settings->set('retry.fetch_profile', ['preferred_methods' => ['unknown']]);
            self::fail('Retry profile must reject unknown fetch methods.');
        } catch (\InvalidArgumentException) {
            self::assertSame($profile, config('javmeta_queues.retry.fetch_profile'));
        }
    }

    public function test_retry_fetch_profile_reads_ignore_invalid_stored_values(): void
    {
        $default = config('javmeta_queues.retry.fetch_profile');
        $this->store->set(
            'settings.retry.fetch_profile',
            json_encode(['http_proxy_url' => 'https://retry-egress.internal?x=1'], JSON_THROW_ON_ERROR),
        );
        $settings = app(SettingsService::class);

        self::assertSame($default, $settings->get('retry.fetch_profile'));
        $setting = collect($settings->all())->firstWhere('key', 'retry.fetch_profile');
        self::assertSame($default, $setting['value']);
        self::assertSame('default', $setting['source']);
        self::assertNotNull($setting['warning']);
        self::assertStringNotContainsString('?x=1', json_encode($setting, JSON_THROW_ON_ERROR));
    }

    public function test_retry_fetch_profile_endpoints_reject_queries_and_fragments(): void
    {
        $settings = app(SettingsService::class);
        $default = config('javmeta_queues.retry.fetch_profile');

        foreach (['flaresolverr_url', 'playwright_url', 'http_proxy_url'] as $field) {
            foreach (['?mode=test', '#fragment'] as $suffix) {
                try {
                    $settings->set('retry.fetch_profile', [$field => 'https://retry-service.internal' . $suffix]);
                    self::fail("{$field} must reject a query or fragment.");
                } catch (\InvalidArgumentException) {
                    self::assertSame($default, config('javmeta_queues.retry.fetch_profile'));
                }
            }
        }
    }

    public function test_crawl_dispatch_alert_thresholds_are_saved_database_settings(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('alerts.crawl_dispatch_warning_seconds', 90);
        $settings->set('alerts.crawl_dispatch_critical_seconds', 240);

        $warning = collect($settings->all())->firstWhere('key', 'alerts.crawl_dispatch_warning_seconds');
        $critical = collect($settings->all())->firstWhere('key', 'alerts.crawl_dispatch_critical_seconds');

        $this->assertSame('database', $warning['source']);
        $this->assertSame(90, $warning['value']);
        $this->assertSame('database', $critical['source']);
        $this->assertSame(240, $critical['value']);

        try {
            $settings->set('alerts.crawl_dispatch_critical_seconds', 80);
            $this->fail('Critical dispatch age must not be lower than the warning age.');
        } catch (\InvalidArgumentException) {
            $this->assertSame(240, $settings->get('alerts.crawl_dispatch_critical_seconds'));
        }

        $settings->set('alerts.crawl_dispatch_critical_seconds', 120);
        try {
            $settings->set('alerts.crawl_dispatch_warning_seconds', 121);
            $this->fail('Warning dispatch age must not exceed the saved critical age.');
        } catch (\InvalidArgumentException) {
            $this->assertSame(90, $settings->get('alerts.crawl_dispatch_warning_seconds'));
        }
    }

    public function test_worker_json_shapes_and_client_service_timeout_relationships_are_validated(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('worker.queue_names', ['listing', 'detail', 'browser_detail', 'observability']);
        $this->assertSame(['listing', 'detail', 'browser_detail', 'observability', 'browser_listing', 'retry_listing', 'retry_detail'], config('javmeta_queues.horizon_names'));
        $settings->set('worker.queue_overrides', ['listing' => 2, 'browser_detail' => 1]);
        $this->assertSame(['listing' => 2, 'browser_detail' => 1], config('javmeta_queues.worker_queue_overrides'));
        foreach ([
            ['worker.queue_names', ['listing', 'browser-detail']],
            ['worker.queue_names', []],
            ['worker.queue_overrides', ['unknown_queue' => 2]],
            ['worker.queue_overrides', ['listing' => 33]],
            ['worker.node_overrides', ['other' => ['default' => 2]]],
            ['browser.gateway_wait_timeout_ms', 90000],
            ['browser.gateway_client_timeout_ms', 110000],
            ['queue.worker_timeout', 45],
        ] as [$key, $value]) {
            try {
                $settings->set($key, $value);
                $this->fail("{$key} should be refused");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $settings->set('worker.node_overrides', ['main' => ['default' => 2, 'queues' => ['listing' => 3]]]);
        $this->assertSame(['main' => ['default' => 2, 'queues' => ['listing' => 3]]], config('javmeta_queues.worker_node_overrides'));
    }

    public function test_source_gap_override_map_accepts_valid_values_and_rejects_invalid_bounds(): void
    {
        $settings = app(SettingsService::class);
        $overrides = ['javdb' => ['min' => 1.25, 'default' => 8, 'max' => 60]];

        $settings->set('crawl.source_gap_overrides', $overrides);

        self::assertSame($overrides, config('javmeta_queues.source_gap_overrides'));
        self::assertSame($overrides, $settings->get('crawl.source_gap_overrides'));
        foreach ([
            ['javdb' => ['min' => 1, 'default' => 8]],
            ['javdb' => ['min' => 8, 'default' => 1, 'max' => 60]],
            ['javdb' => ['min' => 0.1, 'default' => 1, 'max' => 60]],
            ['javdb' => ['min' => 1, 'default' => 8, 'max' => 601]],
            ['bad slug!' => ['min' => 1, 'default' => 8, 'max' => 60]],
            ['javd' => ['min' => 1, 'default' => 8, 'max' => 60]],
            ['javdb' => ['min' => '1', 'default' => 8, 'max' => 60]],
            '{' . str_repeat(' ', 10000) . '}',
        ] as $invalid) {
            try {
                $settings->set('crawl.source_gap_overrides', $invalid);
                self::fail('Invalid source gap overrides must be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertSame($overrides, config('javmeta_queues.source_gap_overrides'));
            }
        }

        Source::factory()->create(['slug' => 'persisted-site']);
        $persistedOverride = ['persisted-site' => ['min' => 2, 'default' => 5, 'max' => 10]];
        $settings->set('crawl.source_gap_overrides', $persistedOverride);
        self::assertSame($persistedOverride, $settings->get('crawl.source_gap_overrides'));
    }

    public function test_performer_normalization_ranges_and_japan_location_aliases_are_editable_database_settings(): void
    {
        $settings = app(SettingsService::class);
        $bloodTypes = config('javmeta_normalization.blood_types');
        $cups = config('javmeta_normalization.cups');
        $junkTokens = config('javmeta_normalization.junk_tokens');
        $ranges = config('javmeta_normalization.field_ranges');
        $backfillChunkSize = config('javmeta_normalization.backfill_chunk_size');
        $settings->set('normalization.blood_types', $bloodTypes);
        $settings->set('normalization.cups', $cups);
        $settings->set('normalization.junk_tokens', $junkTokens);
        $settings->set('normalization.field_ranges', $ranges);
        $settings->set('normalization.backfill_chunk_size', $backfillChunkSize);

        self::assertSame($bloodTypes, $settings->get('normalization.blood_types'));
        self::assertSame($cups, $settings->get('normalization.cups'));
        self::assertSame($junkTokens, $settings->get('normalization.junk_tokens'));
        self::assertSame($ranges, $settings->get('normalization.field_ranges'));
        self::assertSame($backfillChunkSize, $settings->get('normalization.backfill_chunk_size'));
        self::assertSame($ranges, config('javmeta_normalization.field_ranges'));

        try {
            $settings->set('normalization.blood_types', ['Trans']);
            self::fail('Blood types outside A, B, O, and AB must be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertSame(['A', 'B', 'O', 'AB'], $settings->get('normalization.blood_types'));
        }

        $aliases = ['tokyo' => '東京都', '東京都' => '東京都'];
        $settings->set('normalization.location_aliases', $aliases);
        self::assertSame($aliases, $settings->get('normalization.location_aliases'));
        self::assertSame($aliases, config('javmeta_normalization.location_aliases'));

        foreach ([
            ['normalization.blood_types', ['A', 'X']],
            ['normalization.blood_types', []],
            ['normalization.cups', ['A', 'Z']],
            ['normalization.cups', []],
            ['normalization.backfill_chunk_size', 0],
            ['normalization.backfill_chunk_size', 5001],
        ] as [$key, $value]) {
            try {
                $settings->set($key, $value);
                self::fail("Invalid canonical allow-list {$key} must be rejected.");
            } catch (\InvalidArgumentException) {
                self::assertSame($bloodTypes, $settings->get('normalization.blood_types'));
                self::assertSame($cups, $settings->get('normalization.cups'));
            }
        }

        foreach ([['unknown' => ['–']], ['blood_type' => ['']]] as $invalidJunkTokens) {
            try {
                $settings->set('normalization.junk_tokens', $invalidJunkTokens);
                self::fail('Junk tokens must use known fields and non-empty values.');
            } catch (\InvalidArgumentException) {
                self::assertSame($junkTokens, $settings->get('normalization.junk_tokens'));
            }
        }

        foreach ([
            ['height_cm' => ['min' => 1, 'max' => 500]],
            ['' => '東京都'],
            [...$ranges, 'birth_date' => ['min' => '2024-02-30', 'max' => 'today']],
        ] as $invalid) {
            try {
                $key = array_key_exists('height_cm', $invalid) ? 'normalization.field_ranges' : 'normalization.location_aliases';
                if (array_key_exists('birth_date', $invalid)) {
                    $key = 'normalization.field_ranges';
                }
                $settings->set($key, $invalid);
                self::fail('Incomplete normalization settings must be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertSame($ranges, $settings->get('normalization.field_ranges'));
            }
        }
    }

    public function test_malformed_stored_source_gap_json_uses_the_code_default(): void
    {
        $this->store->set('settings.crawl.source_gap_overrides', '{');
        $settings = app(SettingsService::class);

        self::assertTrue($settings->apply());
        self::assertSame([], config('javmeta_queues.source_gap_overrides'));
    }

    public function test_json_settings_without_a_declared_max_length_keep_their_existing_limit(): void
    {
        $settings = app(SettingsService::class);
        $overrides = [];
        for ($index = 0; $index < 50; $index++) {
            $overrides['worker@' . str_repeat('a', 180) . $index] = ['default' => 2];
        }
        self::assertGreaterThan(10000, mb_strlen(json_encode($overrides, JSON_THROW_ON_ERROR)));

        $settings->set('worker.node_overrides', $overrides);

        self::assertSame($overrides, $settings->get('worker.node_overrides'));
    }

    public function test_startup_health_settings_are_database_backed_and_validate_role_members(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('health.startup.primary.web', ['postgres', 'redis', 'mongo']);
        $settings->set('health.startup.timeout_seconds', 1);

        $this->assertSame(['postgres', 'redis', 'mongo'], config('javmeta_health.startup.primary.web'));
        $this->assertSame(1, config('javmeta_health.startup.timeout_seconds'));
        foreach ([['health.startup.primary.web', ['postgres', 'unknown']], ['health.startup.timeout_seconds', 3]] as [$key, $value]) {
            try {
                $settings->set($key, $value);
                $this->fail("{$key} must reject values outside its registered options or range.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertArrayHasKey('startup_health', $settings->groups());
        $this->assertContains('health.startup.primary.web', array_column($settings->all(), 'key'));
    }

    public function test_env_import_dry_run_keeps_every_table_cell_short(): void
    {
        Artisan::call('settings:import-env', ['--dry-run' => true]);
        $longest = 0;
        foreach (explode("\n", Artisan::output()) as $line) {
            $longest = max($longest, mb_strlen($line));
        }

        $this->assertLessThan(400, $longest, 'a long default must not widen the whole preview table');
    }

    public function test_env_import_dry_run_omits_telegram_credentials_without_writing(): void
    {
        $previous = $_ENV['NOTIFICATION_TELEGRAM_BOT_TOKEN'] ?? null;
        $_ENV['NOTIFICATION_TELEGRAM_BOT_TOKEN'] = 'test-secret-value';
        try {
            $preview = collect(app(SettingsService::class)->importPreview())->firstWhere('key', 'telegram.bot_token');
            $this->assertNull($preview);
            Artisan::call('settings:import-env', ['--dry-run' => true]);
            $output = Artisan::output();
            $this->assertStringNotContainsString('telegram.bot_token', $output);
            $this->assertStringNotContainsString('test-secret-value', $output);
            $this->assertNull($this->store->get('settings.telegram.bot_token'));
        } finally {
            if ($previous === null) {
                unset($_ENV['NOTIFICATION_TELEGRAM_BOT_TOKEN']);
            } else {
                $_ENV['NOTIFICATION_TELEGRAM_BOT_TOKEN'] = $previous;
            }
        }
    }

    public function test_capture_duration_and_both_worker_deadlines_are_validated_before_saving(): void
    {
        $settings = app(SettingsService::class);
        foreach ([['capture.max_duration_sec', 72001], ['capture.max_duration_sec', 7201], ['media.job_timeout_sec', 3599], ['media.lease_seconds', 3609]] as [$key, $value]) {
            $before = $settings->get($key);
            try {
                $settings->set($key, $value);
                $this->fail("{$key}={$value} should be refused");
            } catch (\InvalidArgumentException) {
                $this->assertSame($before, $settings->get($key));
            }
        }
        $settings->set('media.lease_seconds', 72010);
        $settings->set('media.job_timeout_sec', 72000);
        $settings->set('capture.max_duration_sec', 72000);
        $this->assertSame(72000, $settings->get('capture.max_duration_sec'));
        foreach ([['media.job_timeout_sec', 71999], ['media.lease_seconds', 72009]] as [$key, $value]) {
            $before = $settings->get($key);
            try {
                $settings->set($key, $value);
                $this->fail("{$key}={$value} should be refused against saved capture duration");
            } catch (\InvalidArgumentException) {
                $this->assertSame($before, $settings->get($key));
            }
        }
        $settings->set('capture.max_duration_sec', 10);
        $settings->set('media.job_timeout_sec', 10);
        $settings->set('media.lease_seconds', 60);
        $this->assertSame(10, $settings->get('media.job_timeout_sec'));
    }

    public function test_telegram_credentials_are_not_registered_or_saved_as_settings(): void
    {
        $settings = app(SettingsService::class);
        foreach (['telegram.bot_token', 'telegram.chat_id'] as $key) {
            $this->assertArrayNotHasKey($key, $settings->definitions());
            $this->assertNotContains($key, array_column($settings->all(), 'key'));
            try {
                $settings->set($key, 'test-secret-value');
                $this->fail("{$key} must remain a runtime secret, not an editable setting.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertStringNotContainsString('test-secret-value', json_encode($settings->all(), JSON_THROW_ON_ERROR));
    }

    public function test_import_env_stores_the_current_values_once(): void
    {
        config(['javmeta_queues.per_source_inflight' => 7, 'javmeta_alerts.telegram.bot_token' => '']);
        $settings = app(SettingsService::class);

        $first = $settings->importDefaults();
        $this->assertContains('queue.per_source_inflight', $first);
        $this->assertNotContains('telegram.bot_token', $first, 'an empty secret is not stored');
        $this->assertSame([], $settings->importDefaults(), 'the second import writes nothing');
        $this->assertSame(7, (int) $this->store->get('settings.queue.per_source_inflight'));
    }

    public function test_worker_environment_values_are_import_only_bootstrap_defaults(): void
    {
        $previousEnv = $_ENV['JAVMETA_WORKERS_PER_QUEUE'] ?? null;
        $previousServer = $_SERVER['JAVMETA_WORKERS_PER_QUEUE'] ?? null;
        $previousProcess = getenv('JAVMETA_WORKERS_PER_QUEUE');
        $_ENV['JAVMETA_WORKERS_PER_QUEUE'] = '4';
        $_SERVER['JAVMETA_WORKERS_PER_QUEUE'] = '4';
        putenv('JAVMETA_WORKERS_PER_QUEUE=4');
        try {
            config(['javmeta_queues.workers_per_queue' => 1]);
            $settings = app(SettingsService::class);
            $settings->apply();

            $row = collect($settings->all())->firstWhere('key', 'queue.workers_per_queue');
            $this->assertSame(1, config('javmeta_queues.workers_per_queue'));
            $this->assertSame(1, $row['value']);
            $this->assertSame('default', $row['source']);
            $preview = collect($settings->importPreview())->firstWhere('key', 'queue.workers_per_queue');
            $this->assertSame(4, $preview['value']);
            $this->assertSame('environment', $preview['source']);
            $this->assertContains('queue.workers_per_queue', $settings->importDefaults());
            $this->assertSame(4, $this->store->get('settings.queue.workers_per_queue'));
            $settings->apply();
            $this->assertSame(4, config('javmeta_queues.workers_per_queue'));
        } finally {
            if ($previousEnv === null) {
                unset($_ENV['JAVMETA_WORKERS_PER_QUEUE']);
            } else {
                $_ENV['JAVMETA_WORKERS_PER_QUEUE'] = $previousEnv;
            }
            if ($previousServer === null) {
                unset($_SERVER['JAVMETA_WORKERS_PER_QUEUE']);
            } else {
                $_SERVER['JAVMETA_WORKERS_PER_QUEUE'] = $previousServer;
            }
            if ($previousProcess === false) {
                putenv('JAVMETA_WORKERS_PER_QUEUE');
            } else {
                putenv('JAVMETA_WORKERS_PER_QUEUE=' . $previousProcess);
            }
        }
    }

    public function test_all_worker_environment_definitions_are_import_only(): void
    {
        foreach (app(SettingsService::class)->definitions() as $key => $definition) {
            if (($definition['group'] ?? null) !== 'worker' && $key !== 'queue.worker_timeout') {
                continue;
            }
            if (($definition['legacy_env'] ?? []) === []) {
                continue;
            }

            $this->assertTrue($definition['bootstrap_only'] ?? false, "{$key} must not read env at runtime.");
        }
    }

    public function test_worker_environment_cleanup_lists_only_settings_already_saved_in_the_database(): void
    {
        $this->store->set('settings.queue.workers_per_queue', 3);
        $before = $this->store->group('settings');

        Artisan::call('settings:env-cleanup', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('JAVMETA_WORKERS_PER_QUEUE', $output);
        $this->assertStringNotContainsString('JAVMETA_BROWSER_WORKERS_PER_QUEUE', $output);
        $this->assertSame($before, $this->store->group('settings'));
    }

    public function test_worker_bootstrap_check_blocks_until_primary_settings_are_imported(): void
    {
        $settings = app(SettingsService::class);
        $missing = $settings->missingWorkerBootstrapSettings();

        $this->assertContains('queue.workers_per_queue', $missing);
        $this->assertSame(1, Artisan::call('settings:assert-worker-bootstrap'));
        $this->assertStringContainsString('queue.workers_per_queue', Artisan::output());

        $settings->importDefaults();

        $this->assertSame([], $settings->missingWorkerBootstrapSettings());
        $this->assertSame(0, Artisan::call('settings:assert-worker-bootstrap'));
    }

    public function test_invalid_stored_worker_json_is_ignored_and_shown_with_a_warning(): void
    {
        $invalid = '{"listing":oops';
        $this->store->set('settings.worker.queue_overrides', $invalid, 'string');
        $settings = app(SettingsService::class);

        $this->assertTrue($settings->apply());
        $this->assertContains('worker.queue_overrides', $settings->invalidWorkerSettings());
        $this->assertSame([], config('javmeta_queues.worker_queue_overrides'));

        $row = collect($settings->all())->firstWhere('key', 'worker.queue_overrides');
        $this->assertSame('database', $row['source']);
        $this->assertSame($invalid, $row['value']);
        $this->assertStringContainsString('không hợp lệ', $row['warning']);
    }

    public function test_import_env_skips_release_paths_in_code_defaults(): void
    {
        config([
            'backup.manifest_path' => '/srv/javmeta/releases/20261102/storage/manifest.json',
            'javmeta_report.node_volume_path' => '/srv/javmeta/current',
        ]);
        SettingsService::forgetDefaults();

        $settings = app(SettingsService::class);
        $skipped = $settings->importSkippedPreview();

        $this->assertSame(['report.node_volume_path', 'backup.manifest_path'], array_column($skipped, 'key'));
        $this->assertSame('code default', $skipped[0]['environment']);
        $this->assertNotContains('backup.manifest_path', array_column($settings->importPreview(), 'key'));
        $settings->importDefaults();
        $this->assertNull($this->store->get('settings.backup.manifest_path'));
        $this->assertNull($this->store->get('settings.report.node_volume_path'));
    }

    public function test_import_env_skips_release_and_current_deployment_paths_and_reports_them(): void
    {
        $names = ['JAVMETA_BACKUP_MANIFEST'];
        $previous = [];
        foreach ($names as $name) {
            $previous[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
        }
        $_ENV['JAVMETA_BACKUP_MANIFEST'] = '/srv/javmeta/releases/20261102/storage/manifest.json';
        $_SERVER['JAVMETA_BACKUP_MANIFEST'] = $_ENV['JAVMETA_BACKUP_MANIFEST'];
        putenv('JAVMETA_BACKUP_MANIFEST=' . $_ENV['JAVMETA_BACKUP_MANIFEST']);
        try {
            $settings = app(SettingsService::class);
            $skipped = $settings->importSkippedPreview();
            $this->assertSame(['backup.manifest_path'], array_column($skipped, 'key'));
            $this->assertSame('deploy-specific path', $skipped[0]['reason']);

            Artisan::call('settings:import-env', ['--dry-run' => true]);
            $dryRun = Artisan::output();
            $this->assertStringContainsString('JAVMETA_BACKUP_MANIFEST', $dryRun);
            $this->assertStringNotContainsString('/srv/javmeta/releases/', $dryRun);

            Artisan::call('settings:import-env');
            $import = Artisan::output();
            $this->assertStringContainsString('JAVMETA_BACKUP_MANIFEST', $import);
            $this->assertNull($this->store->get('settings.backup.manifest_path'));
        } finally {
            foreach ($previous as $name => [$env, $server, $process]) {
                if ($env === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $env;
                }
                if ($server === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $server;
                }
                if ($process === false) {
                    putenv($name);
                } else {
                    putenv($name . '=' . $process);
                }
            }
        }
    }

    public function test_worker_floor_and_grace_period_are_enforced_before_saving(): void
    {
        $settings = app(SettingsService::class);
        foreach ([['queue.workers_per_queue', 0], ['worker.stop_grace_seconds', 179]] as [$key, $value]) {
            try {
                $settings->set($key, $value);
                $this->fail("{$key}={$value} should be refused");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertNull($this->store->get('settings.queue.workers_per_queue'));
        $this->assertNull($this->store->get('settings.worker.stop_grace_seconds'));
        $settings->set('worker.stop_grace_seconds', 240);
        $settings->set('queue.worker_timeout', 200);
        $this->assertSame(240, $settings->get('worker.stop_grace_seconds'));
        $this->assertSame(200, $settings->get('queue.worker_timeout'));
    }

    public function test_apply_validates_stored_worker_timeout_pair_using_both_saved_values(): void
    {
        config([
            'javmeta_queues.worker_timeout' => 240,
            'javmeta_queues.stop_grace_seconds' => 200,
        ]);
        $this->store->set('settings.worker.stop_grace_seconds', 220, 'int');
        $this->store->set('settings.queue.worker_timeout', 210, 'int');
        SettingsService::forgetDefaults();
        $settings = app(SettingsService::class);

        $this->assertTrue($settings->apply());
        $this->assertSame(210, config('javmeta_queues.worker_timeout'));
        $this->assertSame(220, config('javmeta_queues.stop_grace_seconds'));
        $this->assertSame([], $settings->invalidWorkerSettings());
    }

    public function test_import_env_rejects_timeout_values_that_violate_the_stored_pair(): void
    {
        $names = [
            'JAVMETA_QUEUE_TIMEOUT' => '240',
            'JAVMETA_QUEUE_STOP_GRACE' => '200',
            'REDIS_QUEUE_RETRY_AFTER' => '500',
        ];
        $previous = [];
        foreach ($names as $name => $value) {
            $previous[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            putenv($name . '=' . $value);
        }

        try {
            SettingsService::forgetDefaults();
            $settings = app(SettingsService::class);
            try {
                $settings->importDefaults();
                $this->fail('Invalid worker timeout pair should be refused before writes.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('worker.stop_grace_seconds', $exception->getMessage());
            }
            $this->assertSame([], $this->store->group('settings'));
        } finally {
            foreach ($previous as $name => [$env, $server, $process]) {
                if ($env === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $env;
                }
                if ($server === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $server;
                }
                if ($process === false) {
                    putenv($name);
                } else {
                    putenv($name . '=' . $process);
                }
            }
        }
    }

    public function test_import_env_removes_partial_writes_when_a_store_write_fails(): void
    {
        config([
            'javmeta_settings.definitions' => [
                'queue.first' => ['config' => ['test.settings.first'], 'type' => 'int'],
                'queue.second' => ['config' => ['test.settings.second'], 'type' => 'int'],
            ],
            'test.settings.first' => 1,
            'test.settings.second' => 2,
        ]);
        $this->store = new class extends FakeConfigStore {
            public function set(string $path, mixed $value, ?string $type = null): void
            {
                parent::set($path, $value, $type);

                if ($path === 'settings.queue.second') {
                    throw new \RuntimeException('simulated store failure');
                }
            }
        };
        $this->app->instance(ConfigStore::class, $this->store);
        $this->app->forgetInstance(SettingsService::class);

        try {
            app(SettingsService::class)->importDefaults();
            $this->fail('A failed settings write should be rethrown.');
        } catch (\RuntimeException $error) {
            $this->assertSame('simulated store failure', $error->getMessage());
        }

        $this->assertSame([], $this->store->group('settings'));
    }

    public function test_the_settings_page_api_needs_the_owner_and_saves_and_reports_errors(): void
    {
        $this->getJson('/ui-admin/settings')->assertStatus(401);
        $this->actingAs(User::factory()->create(['role' => 'super_administrator']));

        $page = $this->getJson('/ui-admin/settings')->assertOk();
        $this->assertArrayHasKey('queue', $page->json('data.groups'));
        $this->assertNotEmpty($page->json('data.settings'));
        $sourceGapSetting = collect($page->json('data.settings'))->firstWhere('key', 'crawl.source_gap_overrides');
        $this->assertSame('json', $sourceGapSetting['type']);
        $this->assertSame(10000, $sourceGapSetting['max_length']);
        $bloodTypes = collect($page->json('data.settings'))->firstWhere('key', 'normalization.blood_types');
        $this->assertSame(['A', 'B', 'O', 'AB'], $bloodTypes['item_options']);
        $chunkSize = collect($page->json('data.settings'))->firstWhere('key', 'normalization.backfill_chunk_size');
        $this->assertSame('int', $chunkSize['type']);
        $this->assertSame(5000, $chunkSize['max']);
        $locationAliases = collect($page->json('data.settings'))->firstWhere('key', 'normalization.location_aliases');
        $this->assertSame(500000, $locationAliases['max_length']);

        $this->putJson('/ui-admin/settings', ['settings' => ['queue.feed_pool_limit' => 120, 'alerts.enabled' => false]])->assertOk()->assertJsonPath('data.applied.0', 'queue.feed_pool_limit')->assertJsonPath('data.apply_after_seconds', 30);
        $this->assertSame(120, collect($this->getJson('/ui-admin/settings')->json('data.settings'))->firstWhere('key', 'queue.feed_pool_limit')['value']);

        $this->putJson('/ui-admin/settings', ['settings' => ['queue.feed_pool_limit' => -5]])->assertStatus(422)->assertJsonStructure(['data' => ['errors' => ['queue.feed_pool_limit']]]);
        $this->putJson('/ui-admin/settings', ['settings' => ['crawl.source_gap_overrides' => ['javdb' => ['min' => 5, 'default' => 2, 'max' => 60]]]])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['crawl.source_gap_overrides']]]);
        $this->putJson('/ui-admin/settings', [])->assertStatus(422);
    }

    public function test_telegram_credentials_are_not_imported_from_runtime_config(): void
    {
        config(["javmeta_alerts.telegram.bot_token" => "", "laravel-notifications.channels.telegram.bot_token" => "123:live"]);
        $settings = app(SettingsService::class);

        $this->assertNotContains("telegram.bot_token", $settings->importDefaults());
        $this->assertNull($this->store->get("settings.telegram.bot_token"));
    }
    public static function invalidUrls(): array
    {
        $cases = [];
        foreach (['media.api_url', 'search.embedder_url', 'ai.ollama_url', 'downloads.qbittorrent_url', 'telegram.api_base', 'browser.flaresolverr_url', 'browser.playwright_url'] as $key) {
            foreach (['ftp://example.test', '//example.test', 'https:///missing', 'https://user:pass@example.test', 'https://user@example.test', 'https://example.test?token=hidden', 'https://example.test#secret', 'https://example.test?', 'https://example.test#', "https://example.test\n", 'https://example.test\\@other.test'] as $url) {
                // Leading/trailing whitespace is normalized by the existing settings contract.
                if (str_ends_with($url, "\n")) {
                    $url = "https://exam\nple.test";
                }
                $cases[] = [$key, $url];
            }
        }
        $cases[] = ['telegram.api_base', 'http://api.telegram.org'];
        $cases[] = ['telegram.api_base', 'http://localhost.example.test'];
        $cases[] = ['telegram.api_base', 'http://127.0.0.2'];

        return $cases;
    }

    #[DataProvider('invalidUrls')]
    public function test_invalid_base_urls_use_the_existing_validation_error(string $key, string $url): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_administrator']));
        $this->putJson('/ui-admin/settings', ['settings' => [$key => $url]])->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => [$key]]]);
        $this->assertNull($this->store->get('settings.' . $key));
    }

    public function test_valid_url_schemes_and_local_telegram_http_are_accepted(): void
    {
        $settings = app(SettingsService::class);
        foreach (['media.api_url', 'search.embedder_url', 'ai.ollama_url', 'downloads.qbittorrent_url', 'browser.flaresolverr_url', 'browser.playwright_url'] as $key) {
            foreach (['http://192.168.2.10:8080/base', 'https://example.test/base/', ''] as $url) {
                $settings->set($key, $url);
                $this->assertSame($url, $settings->get($key));
            }
        }
        foreach (['https://api.telegram.org', 'http://localhost:8080', 'http://127.0.0.1:8080'] as $url) {
            $settings->set('telegram.api_base', $url);
            $this->assertSame($url, $settings->get('telegram.api_base'));
        }
        $settings->set('telegram.owner_user_id', '123');
        $this->assertSame(123, $settings->get('telegram.owner_user_id'));
        $settings->set('telegram.owner_user_id', '');
        $this->assertNull($settings->get('telegram.owner_user_id'));
    }

    public function test_invalid_saved_urls_are_ignored_without_exposing_them_and_can_be_repaired(): void
    {
        $settings = app(SettingsService::class);
        $default = $settings->get('downloads.qbittorrent_url');
        $settings->set('downloads.qbittorrent_url', 'http://valid.test');
        $this->store->set('settings.downloads.qbittorrent_url', 'http://legacy:password@example.test/base?token=hidden');
        $settings->apply();
        $this->assertSame($default, config('javmeta_downloads.qbittorrent_url'));
        $this->assertSame($default, $settings->get('downloads.qbittorrent_url'));
        $this->actingAs(User::factory()->create(['role' => 'super_administrator']));
        $page = $this->getJson('/ui-admin/settings')->assertOk();
        $row = collect($page->json('data.settings'))->firstWhere('key', 'downloads.qbittorrent_url');
        $this->assertSame($default, $row['value']);
        $this->assertSame('default', $row['source']);
        $this->assertNotEmpty($row['warning']);
        $this->assertStringNotContainsString('legacy:password@', $page->getContent());
        $this->assertStringNotContainsString('hidden', $page->getContent());
        $audit = $settings->set('downloads.qbittorrent_url', 'https://new.test');
        $this->assertStringNotContainsString('password', json_encode($audit));
        $this->assertStringNotContainsString('hidden', json_encode($audit));
        $this->assertNull(collect($settings->all())->firstWhere('key', 'downloads.qbittorrent_url')['warning']);
    }

    public function test_url_credentials_are_redacted_from_audit_and_log_text_and_context(): void
    {
        $url = 'https://legacy:private-pass@example.test/base?access_token=private-query#private-fragment';
        $sanitizer = app(TelemetrySanitizer::class);
        $event = $sanitizer->sanitizeContext(['kind' => 'setting_changed', 'old' => $url]);
        $this->assertSame('https://example.test/base', $event['old']);
        $this->assertSame('Failed https://example.test/base', $sanitizer->sanitizeText('Failed ' . $url));
        $handler = new TestHandler();
        $monolog = new Logger('url-test', [$handler]);
        $logger = new \Illuminate\Log\Logger($monolog);
        (new RedactUrlSecrets())($logger);
        $logger->error('Request {endpoint}', ['endpoint' => $url, 'nested' => ['address' => $url], 'exception' => new RuntimeException('Failed ' . $url)]);
        $record = $handler->getRecords()[0];
        $this->assertSame('https://example.test/base', $record->context['endpoint']);
        $this->assertSame('https://example.test/base', $record->context['nested']['address']);
        $this->assertStringNotContainsString('private-pass', $record->context['exception']);
        $this->assertStringNotContainsString('private-query', $record->context['exception']);
        $this->assertStringNotContainsString('private-fragment', $record->context['exception']);
        $safeException = new RuntimeException('Connection refused');
        $logger->error('Request failed', ['exception' => $safeException]);
        $this->assertSame($safeException, $handler->getRecords()[1]->context['exception'], 'ordinary exceptions retain structured logging metadata');
    }

    public function test_repairing_a_legacy_url_emits_a_safe_setting_changed_event(): void
    {
        config(['openobserve.enabled' => true, 'openobserve.url' => 'http://openobserve.test', 'openobserve.org' => 'default', 'openobserve.stream_logs' => 'javmeta_logs']);
        Http::fake(['openobserve.test/*' => Http::response([], 200)]);
        $this->store->set('settings.downloads.qbittorrent_url', 'http://legacy:private-pass@example.test?token=private-query');
        $this->actingAs(User::factory()->create(['role' => 'super_administrator']));
        $this->putJson('/ui-admin/settings', ['settings' => ['downloads.qbittorrent_url' => 'https://repaired.test']])->assertOk();
        Http::assertSent(static function ($request): bool {
            $record = $request->data()[0] ?? [];

            return ($record['kind'] ?? '') === 'setting_changed'
                && ($record['new'] ?? '') === 'https://repaired.test'
                && ! str_contains($request->body(), 'private-pass') && ! str_contains($request->body(), 'private-query');
        });
    }

    public function test_configured_local_logger_redacts_urls_before_interpolating(): void
    {
        $path = storage_path('logs/url-redaction-test-' . uniqid() . '.log');
        try {
            config(['logging.channels.single.path' => $path]);
            Log::forgetChannel('single');
            $logger = Log::channel('single');
            $logger->error('Failed {endpoint}', ['endpoint' => 'https://user:private-pass@example.test/api?token=private-query#fragment']);
            $contents = file_get_contents($path);
            $this->assertStringContainsString('https://example.test/api', $contents);
            $this->assertStringNotContainsString('private-pass', $contents);
            $this->assertStringNotContainsString('private-query', $contents);
        } finally {
            Log::forgetChannel('single');
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_an_invalid_legacy_default_cannot_reintroduce_an_ignored_url(): void
    {
        $unsafe = 'http://legacy:private-pass@example.test?token=private-query';
        config(['javmeta_downloads.qbittorrent_url' => $unsafe]);
        $this->store->set('settings.downloads.qbittorrent_url', $unsafe);
        $settings = app(SettingsService::class);
        $settings->apply();
        $this->assertSame('', $settings->get('downloads.qbittorrent_url'));
        $this->assertSame('', config('javmeta_downloads.qbittorrent_url'));
        $row = collect($settings->all())->firstWhere('key', 'downloads.qbittorrent_url');
        $this->assertSame('', $row['value']);
        $this->assertNotEmpty($row['warning']);
    }
}
