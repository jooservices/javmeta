<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V2;

use App\Events\PasswordResetRequested;
use App\Http\Api\V2\Scope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

final class SessionAndUserTest extends V2TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['email' => 'admin@example.test', 'password' => 'secret-pass-1', 'role' => 'super_administrator']);
    }

    private function csrf(User $user): string
    {
        $this->actingAs($user, 'web')->getJson('/api/v2/sessions/current');

        return (string) $this->app['session.store']->token();
    }

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('api-v2-sign-in');
    }

    public function test_sign_in_returns_user_scope_and_csrf_and_sets_the_request_id(): void
    {
        $this->admin();

        $response = $this->postJson('/api/v2/sessions', ['email' => 'ADMIN@example.test', 'password' => 'secret-pass-1'])->assertCreated();

        $response->assertJsonPath('data.user.email', 'admin@example.test')->assertJsonPath('data.scope', 'admin')->assertJsonStructure(['data' => ['csrf'], 'meta']);
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }

    public function test_wrong_password_is_401_and_missing_fields_are_422_with_field_errors(): void
    {
        $this->admin();

        $this->postJson('/api/v2/sessions', ['email' => 'admin@example.test', 'password' => 'nope'])->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
        $this->postJson('/api/v2/sessions', [])->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonFragment(['field' => 'email', 'code' => 'required']);
    }

    public function test_sign_in_is_limited_to_ten_per_minute(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->postJson('/api/v2/sessions', ['email' => 'x@example.test', 'password' => 'bad'])->assertUnauthorized();
        }

        $this->postJson('/api/v2/sessions', ['email' => 'x@example.test', 'password' => 'bad'])->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
    }

    public function test_current_session_answers_for_guest_and_for_a_signed_in_user(): void
    {
        config()->set('javmeta.ui_login_required', false);
        $this->getJson('/api/v2/sessions/current')->assertOk()->assertJsonPath('data.scope', 'guest')->assertJsonPath('data.user', null)->assertJsonPath('data.login_required', false);

        $user = $this->admin();
        $this->actingAs($user, 'web')->getJson('/api/v2/sessions/current')->assertOk()->assertJsonPath('data.scope', 'admin')->assertJsonStructure(['data' => ['csrf', 'preferences']]);
    }

    public function test_sign_out_clears_the_session(): void
    {
        $user = $this->admin();
        $token = $this->csrf($user);

        $this->deleteJson('/api/v2/sessions/current', [], ['X-CSRF-Token' => $token])->assertNoContent();
    }

    public function test_password_reset_always_answers_202_and_a_token_works_once(): void
    {
        Event::fake([PasswordResetRequested::class]);
        $user = $this->admin();

        $this->postJson('/api/v2/password-resets', ['email' => 'nobody@example.test'])->assertStatus(202)->assertJsonPath('data.accepted', true);
        Event::assertNotDispatched(PasswordResetRequested::class);

        $this->postJson('/api/v2/password-resets', ['email' => $user->email])->assertStatus(202);
        $token = null;
        Event::assertDispatched(PasswordResetRequested::class, function (PasswordResetRequested $event) use (&$token): bool {
            $token = $event->token;

            return true;
        });

        $this->putJson("/api/v2/password-resets/{$token}", ['password' => 'short'])->assertStatus(422);
        $this->putJson("/api/v2/password-resets/{$token}", ['password' => 'a-new-password'])->assertNoContent();
        $this->putJson("/api/v2/password-resets/{$token}", ['password' => 'a-new-password'])->assertNotFound()->assertJsonPath('code', 'token_not_found');
        $this->postJson('/api/v2/sessions', ['email' => $user->email, 'password' => 'a-new-password'])->assertCreated();
    }

    public function test_an_expired_reset_token_is_refused(): void
    {
        $user = $this->admin();
        DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => hash('sha256', 'old-token'), 'created_at' => now()->subMinutes(61)]);

        $this->putJson('/api/v2/password-resets/old-token', ['password' => 'a-new-password'])->assertNotFound();
    }

    public function test_user_routes_need_the_admin_scope(): void
    {
        $this->getJson('/api/v2/users')->assertUnauthorized()->assertJsonPath('code', 'authentication_required');

        $member = User::factory()->create(['role' => 'user']);
        $this->actingAs($member, 'web')->getJson('/api/v2/users')->assertForbidden()->assertJsonPath('code', 'forbidden');
    }

    public function test_unsafe_requests_of_a_signed_in_user_need_the_csrf_token(): void
    {
        $admin = $this->admin();
        $payload = ['name' => 'New', 'email' => 'new@example.test', 'password' => 'long-enough-1'];

        $this->actingAs($admin, 'web')->postJson('/api/v2/users', $payload)->assertForbidden()->assertJsonPath('code', 'csrf_mismatch');
    }

    public function test_an_administrator_manages_users(): void
    {
        $admin = $this->admin();
        $token = $this->csrf($admin);
        $h = ['X-CSRF-Token' => $token];

        $created = $this->postJson('/api/v2/users', ['name' => 'New', 'email' => 'New@Example.test', 'password' => 'long-enough-1'], $h)->assertCreated()->assertJsonPath('data.role', 'user')->assertJsonPath('data.scope', 'owner');
        $id = $created->json('data.id');
        $this->assertSame("/api/v2/users/{$id}", $created->headers->get('Location'));

        $this->postJson('/api/v2/users', ['name' => 'Dup', 'email' => 'new@example.test', 'password' => 'long-enough-1'], $h)->assertStatus(409)->assertJsonPath('code', 'email_taken');
        $this->postJson('/api/v2/users', ['name' => 'Bad', 'email' => 'not-mail', 'password' => 'short', 'role' => 'x'], $h)->assertStatus(422);

        $this->getJson('/api/v2/users')->assertOk()->assertJsonCount(2, 'data');
        $this->patchJson("/api/v2/users/{$id}", ['role' => 'admin', 'email' => 'Renamed@Example.test'], $h)->assertOk()->assertJsonPath('data.scope', 'admin')->assertJsonPath('data.email', 'renamed@example.test');
        $this->patchJson("/api/v2/users/{$id}", [], $h)->assertStatus(422);
        $this->patchJson('/api/v2/users/99999', ['name' => 'x'], $h)->assertNotFound()->assertJsonPath('code', 'user_not_found');
        $this->deleteJson("/api/v2/users/{$admin->id}", [], $h)->assertStatus(409)->assertJsonPath('code', 'cannot_delete_self');
        $this->deleteJson("/api/v2/users/{$id}", [], $h)->assertNoContent();
    }

    public function test_unknown_v2_paths_and_methods_are_problem_json(): void
    {
        $this->getJson('/api/v2/nothing-here')->assertNotFound()->assertJsonPath('code', 'not_found')->assertJsonPath('status', 404);
        $this->getJson('/api/v2/sessions')->assertStatus(405)->assertJsonPath('code', 'method_not_allowed');
    }

    public function test_the_contract_is_served_as_json(): void
    {
        $this->getJson('/api/v2/openapi.json')->assertOk()->assertJsonPath('openapi', '3.1.0')->assertJsonPath('servers.0.url', '/api/v2');
    }

    public function test_scope_ranks_guest_owner_admin(): void
    {
        $this->assertTrue(Scope::Admin->allows(Scope::Owner));
        $this->assertFalse(Scope::Guest->allows(Scope::Owner));
        $this->assertSame(Scope::Guest, Scope::forUser(null));
        $this->assertSame(Scope::Owner, Scope::forUser(new User(['role' => 'user'])));
    }
}
