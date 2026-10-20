<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class HorizonAccessTest extends TestCase
{
    public function test_horizon_dashboard_is_forbidden_without_the_owner_token(): void
    {
        config(['javmeta_auth.owner_admin_token' => 'owner-token']);

        $this->get('/horizon')->assertForbidden();
    }

    public function test_horizon_dashboard_is_available_with_the_owner_token(): void
    {
        config(['javmeta_auth.owner_admin_token' => 'owner-token']);

        $this->withHeader('X-Owner-Token', 'owner-token')
            ->get('/horizon')
            ->assertOk();
    }

    public function test_horizon_dashboard_does_not_bypass_authentication_in_local_environment(): void
    {
        config(['javmeta_auth.owner_admin_token' => '']);
        $this->app->detectEnvironment(static fn(): string => 'local');

        $this->get('/horizon')->assertForbidden();
    }
}
