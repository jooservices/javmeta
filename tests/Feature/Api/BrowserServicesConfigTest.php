<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Services\Auth\ApiKeyService;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;
use Tests\TestCase;

final class BrowserServicesConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::forgetDefaults();
        $this->app->instance(ConfigStore::class, new FakeConfigStore());
        $this->app->forgetInstance(SettingsService::class);
    }

    protected function tearDown(): void
    {
        SettingsService::forgetDefaults();
        parent::tearDown();
    }

    public function test_browser_services_config_requires_the_config_scope_and_supports_etags(): void
    {
        $all = ['X-API-Key' => app(ApiKeyService::class)->create('all')->plaintext];
        $config = ['X-API-Key' => app(ApiKeyService::class)->create('config', 60, ['config'])->plaintext];
        $wrongScope = ['X-API-Key' => app(ApiKeyService::class)->create('status', 60, ['status'])->plaintext];

        $this->getJson('/api/v1/config/browser-services')->assertUnauthorized();
        $this->getJson('/api/v1/config/browser-services', $wrongScope)->assertForbidden()->assertJsonPath('type', 'forbidden_scope');

        $response = $this->getJson('/api/v1/config/browser-services', $config);
        $response->assertOk()
            ->assertJsonPath('data.concurrency.browser_max', 2)
            ->assertJsonPath('data.queues.browser_max', 100)
            ->assertJsonPath('data.retry.retry_after_seconds', 240)
            ->assertJsonStructure(['data' => ['version', 'timeouts', 'thresholds', 'cleanup']]);
        $etag = $response->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->get('/api/v1/config/browser-services', [...$config, 'If-None-Match' => $etag])->assertStatus(304)->assertHeader('ETag', $etag);

        app(SettingsService::class)->set('browser.max_concurrency', 4);
        $updated = $this->getJson('/api/v1/config/browser-services', $all)->assertOk();
        $updated->assertJsonPath('data.concurrency.browser_max', 4);
        $this->assertNotSame($etag, $updated->headers->get('ETag'));
    }
}
