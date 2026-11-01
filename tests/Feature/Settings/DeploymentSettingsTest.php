<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Services\Settings\SettingsService;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;
use Tests\TestCase;

final class DeploymentSettingsTest extends TestCase
{
    public function test_manifest_import_initializes_missing_settings_and_preserves_ui_choices(): void
    {
        require_once dirname(__DIR__, 3) . '/deploy/lib/settings.php';
        SettingsService::forgetDefaults();
        $store = new FakeConfigStore();
        $settings = new SettingsService($store);
        $manifest = ['services' => [
            ['id' => 'application', 'optional' => false, 'fields' => [['key' => 'UI_HOST', 'setting' => 'domains.ui_host']]],
            ['id' => 'ollama', 'optional' => true, 'fields' => [['key' => 'OLLAMA_URL', 'setting' => 'ai.ollama_url']]],
        ]];
        $answers = ['UI_HOST' => 'catalog.example.test', 'OLLAMA_URL' => 'http://ollama.example.test', 'SERVICE_OLLAMA_ENABLED' => '0'];
        \JavMeta\Deploy\importSettings($settings, $manifest, [], $answers);
        $this->assertSame('catalog.example.test', $settings->get('domains.ui_host'));
        $this->assertArrayNotHasKey('ai.ollama_url', $store->group(SettingsService::GROUP));
        $settings->set('domains.ui_host', 'owner.example.test');
        $answers['SERVICE_OLLAMA_ENABLED'] = '1';
        \JavMeta\Deploy\importSettings($settings, $manifest, $store->group(SettingsService::GROUP), $answers);
        $this->assertSame('owner.example.test', $settings->get('domains.ui_host'));
        $this->assertSame('http://ollama.example.test', $settings->get('ai.ollama_url'));
        SettingsService::forgetDefaults();
    }
}
