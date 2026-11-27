<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V2;

use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;

/** Shared set-up of the administration area tests: SQLite, a fake settings store, sign-in and CSRF helpers. */
abstract class AdministrationTestCase extends V2TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(ConfigStore::class, new FakeConfigStore());
        $this->app->forgetInstance(SettingsService::class);
        SettingsService::forgetDefaults();
    }

    protected function user(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    /** @return array{0: User, 1: array<string, string>} the admin and the unsafe-request headers (CSRF) */
    protected function admin(): array
    {
        $user = $this->user('super_administrator');
        $this->actingAs($user, 'web')->getJson('/api/v2/sessions/current');

        return [$user, ['X-CSRF-Token' => (string) $this->app['session.store']->token()]];
    }
}
