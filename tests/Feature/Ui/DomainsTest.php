<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DomainsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['javmeta_domains.command_center_host' => 'jav-cc.example.test', 'javmeta_domains.ui_host' => 'jav.example.test']);
        User::factory()->create(['email' => 'owner@example.test', 'password' => bcrypt('pw-123456'), 'role' => 'super_administrator']);
    }

    public function test_login_on_the_command_center_host_lands_on_the_operations_dashboard(): void
    {
        $this->post('http://jav-cc.example.test/login', ['email' => 'owner@example.test', 'password' => 'pw-123456'])->assertRedirect('http://jav-cc.example.test/ops');
    }

    public function test_login_on_the_ui_host_lands_on_the_app(): void
    {
        $this->post('http://jav.example.test/login', ['email' => 'owner@example.test', 'password' => 'pw-123456'])->assertRedirect('http://jav.example.test/app');
    }

    public function test_the_ui_shell_points_the_operations_link_at_the_command_center_host(): void
    {
        $owner = User::query()->first();
        $this->actingAs($owner)->get('http://jav.example.test/app')->assertOk()->assertSee('name="command-center-url" content="http://jav-cc.example.test/ops"', false);

        config(['javmeta_domains.command_center_host' => '']);
        $this->actingAs($owner)->get('http://jav.example.test/app')->assertOk()->assertDontSee('command-center-url', false);
    }
}
