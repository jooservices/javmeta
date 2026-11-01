<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Services\Settings\SettingsService;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;
use Tests\TestCase;

final class FreshListingIntervalConfigTest extends TestCase
{
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

    public function test_fresh_listing_interval_defaults_to_sixty_minutes(): void
    {
        self::assertSame(60, $this->freshIntervalWithEnvironmentValue(null));
    }

    public function test_fresh_listing_interval_uses_the_environment_value_until_it_is_imported(): void
    {
        self::assertSame(25, $this->freshIntervalWithEnvironmentValue('25'));
    }

    public function test_saved_database_value_takes_precedence_over_legacy_environment_value(): void
    {
        $this->store->set('settings.crawl.fresh_interval_minutes', 35);
        self::assertSame(35, $this->freshIntervalWithEnvironmentValue('25'));
    }

    private function freshIntervalWithEnvironmentValue(?string $value): int
    {
        $name = 'JAVMETA_FRESH_INTERVAL_MINUTES';
        $previousProcessValue = getenv($name);
        $hadEnvValue = array_key_exists($name, $_ENV);
        $previousEnvValue = $_ENV[$name] ?? null;
        $hadServerValue = array_key_exists($name, $_SERVER);
        $previousServerValue = $_SERVER[$name] ?? null;

        try {
            if ($value === null) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                putenv($name . '=' . $value);
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }

            app(SettingsService::class)->apply();

            return (int) config('javmeta_sources.defaults.loop.fresh_interval_minutes');
        } finally {
            if ($previousProcessValue === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $previousProcessValue);
            }

            if ($hadEnvValue) {
                $_ENV[$name] = $previousEnvValue;
            } else {
                unset($_ENV[$name]);
            }

            if ($hadServerValue) {
                $_SERVER[$name] = $previousServerValue;
            } else {
                unset($_SERVER[$name]);
            }
        }
    }
}
