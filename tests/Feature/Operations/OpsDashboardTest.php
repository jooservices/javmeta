<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class OpsDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $password = 'correct-horse-battery'): User
    {
        return User::factory()->create([
            'email' => 'owner@example.test',
            'password' => $password,
            'role' => 'super_administrator',
        ]);
    }

    public function test_a_visitor_is_sent_to_the_login_page_and_the_root_redirects_to_the_dashboard(): void
    {
        $this->get('/')->assertRedirect(route('ui.index'));
        $this->get('/ops')->assertRedirect(route('login'));
        $this->getJson('/ops/data')->assertUnauthorized();
    }

    public function test_a_signed_in_user_without_the_role_is_refused(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)->get('/ops')->assertForbidden();
        $this->actingAs($viewer)->getJson('/ops/data')->assertForbidden();
    }

    public function test_the_super_administrator_sees_the_dashboard_and_its_data(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/ops')->assertOk();
        $this->actingAs($admin)->getJson('/ops/data')->assertOk()->assertJsonStructure(['as_of', 'totals' => ['movies']]);
    }

    public function test_the_command_center_displays_the_guest_mode_state(): void
    {
        config(['javmeta.ui_login_required' => false]);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/ops')->assertOk()->assertSee('ui-login-mode');
        $this->actingAs($admin)->getJson('/ops/data')->assertOk()
            ->assertJsonPath('ui_login_required', false)
            ->assertJsonPath('ui_login_state', 'Đăng nhập giao diện đã tắt (chế độ khách)');
    }

    public function test_login_works_with_the_right_password_and_fails_with_a_wrong_one(): void
    {
        $this->admin('correct-horse-battery');

        $this->post('/login', ['email' => 'owner@example.test', 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/login', ['email' => 'owner@example.test', 'password' => 'correct-horse-battery'])
            ->assertRedirect(route('ui.index'));
        $this->assertAuthenticated();
    }

    public function test_an_account_without_the_role_cannot_log_in_to_operations(): void
    {
        User::factory()->create(['email' => 'viewer@example.test', 'password' => 'secret-password', 'role' => 'viewer']);

        $this->post('/login', ['email' => 'viewer@example.test', 'password' => 'secret-password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_logout_ends_the_session(): void
    {
        $this->actingAs($this->admin())->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->post('/login', ['email' => 'owner@example.test', 'password' => 'bad-' . $attempt]);
        }

        $this->post('/login', ['email' => 'owner@example.test', 'password' => 'bad-last'])->assertStatus(429);
    }

    public function test_the_command_creates_the_account_with_a_hashed_random_password_and_rotates_it_only_on_request(): void
    {
        $this->artisan('operations:make-super-admin', ['email' => 'Boss@Example.test'])->assertSuccessful();

        $user = User::query()->where('email', 'boss@example.test')->firstOrFail();
        $this->assertSame('super_administrator', $user->role);
        $this->assertTrue(Hash::isHashed((string) $user->password));
        $firstHash = (string) $user->password;

        $this->artisan('operations:make-super-admin', ['email' => 'boss@example.test'])->assertFailed();
        $this->assertSame($firstHash, (string) $user->fresh()->password);

        $this->artisan('operations:make-super-admin', ['email' => 'boss@example.test', '--rotate' => true])->assertSuccessful();
        $this->assertNotSame($firstHash, (string) $user->fresh()->password);

        $this->artisan('operations:make-super-admin', ['email' => 'not-an-email'])->assertFailed();
    }
}
