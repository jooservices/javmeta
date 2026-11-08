<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;
use Tests\TestCase;

final class SettingsPurgeTelegramCredentialsTest extends TestCase
{
    public function test_upgrade_command_forgets_legacy_credentials_without_printing_values(): void
    {
        $store = new FakeConfigStore();
        $store->set('settings.telegram.bot_token', 'fixture-token');
        $store->set('settings.telegram.chat_id', 'fixture-chat');
        $this->app->instance(ConfigStore::class, $store);

        $this->artisan('settings:purge-telegram-credentials')
            ->expectsOutputToContain('Removed 2 legacy Telegram credential setting(s).')
            ->doesntExpectOutputToContain('fixture-token')
            ->doesntExpectOutputToContain('fixture-chat')
            ->assertSuccessful();

        self::assertFalse($store->has('settings.telegram.bot_token'));
        self::assertFalse($store->has('settings.telegram.chat_id'));
    }
}
