<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Monolog\Logger;
use Monolog\Handler\TestHandler;
use App\Logging\RedactUrlSecrets;
use App\Observability\TelemetrySanitizer;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_a_secret_is_stored_encrypted_and_never_returned(): void
    {
        $settings = app(SettingsService::class);
        $result = $settings->set('telegram.bot_token', '123:secret-token');

        $this->assertNull($result['new']);
        $this->assertSame('123:secret-token', config('javmeta_alerts.telegram.bot_token'));
        $this->assertSame('123:secret-token', config('laravel-notifications.channels.telegram.bot_token'), 'every config key of the setting is updated');
        $row = collect($settings->all())->firstWhere('key', 'telegram.bot_token');
        $this->assertNull($row['value']);
        $this->assertTrue($row['is_set']);
        $this->assertStringNotContainsString('secret-token', json_encode($settings->all(), JSON_THROW_ON_ERROR));
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

        $this->putJson('/ui-admin/settings', ['settings' => ['queue.feed_pool_limit' => 120, 'alerts.enabled' => false]])->assertOk()->assertJsonPath('data.applied.0', 'queue.feed_pool_limit');
        $this->assertSame(120, collect($this->getJson('/ui-admin/settings')->json('data.settings'))->firstWhere('key', 'queue.feed_pool_limit')['value']);

        $this->putJson('/ui-admin/settings', ['settings' => ['queue.feed_pool_limit' => -5]])->assertStatus(422)->assertJsonStructure(['data' => ['errors' => ['queue.feed_pool_limit']]]);
        $this->putJson('/ui-admin/settings', [])->assertStatus(422);
    }

    public function test_a_secret_default_comes_from_the_first_config_key_that_has_a_value(): void
    {
        config(["javmeta_alerts.telegram.bot_token" => "", "laravel-notifications.channels.telegram.bot_token" => "123:live"]);
        $settings = app(SettingsService::class);

        $this->assertContains("telegram.bot_token", $settings->importDefaults());
        $this->assertSame("123:live", $this->store->get("settings.telegram.bot_token"));
    }
    public static function invalidUrls(): array
    {
        $cases = [];
        foreach (['media.api_url', 'search.embedder_url', 'ai.ollama_url', 'downloads.qbittorrent_url', 'telegram.api_base'] as $key) {
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
        foreach (['media.api_url', 'search.embedder_url', 'ai.ollama_url', 'downloads.qbittorrent_url'] as $key) {
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
        // Keep the redaction sentinel independent of the checkout directory name.
        config(['javmeta_media.output_folder' => '/test-output']);
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
        $this->assertStringNotContainsString('legacy', $page->getContent());
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
