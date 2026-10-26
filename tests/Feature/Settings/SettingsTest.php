<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;
use Tests\TestCase;

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
}
