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
}
