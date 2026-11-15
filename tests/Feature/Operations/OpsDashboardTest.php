<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @return list<array{0: string, 1: string, 2: string}> */
    public static function dashboardLocales(): array
    {
        return [
            ['en', 'Command Center', 'Sources'],
            ['vi', 'Trung tâm điều hành', 'Nguồn'],
        ];
    }

    #[DataProvider('dashboardLocales')]
    public function test_the_dashboard_translates_visible_copy_and_declares_the_selected_locale(string $locale, string $heading, string $sources): void
    {
        $admin = $this->admin();
        $response = $this->actingAs($admin)
            ->withHeader('Accept-Language', $locale === 'en' ? 'vi' : 'en')
            ->get('/ops?lang=' . $locale);

        $response->assertOk()
            ->assertSee('<html lang="' . $locale . '">', false)
            ->assertSee($heading)
            ->assertSee($sources)
            ->assertSee('/ops?lang=' . ($locale === 'en' ? 'vi' : 'en'), false);

        $this->actingAs($admin)->getJson('/ops/data')
            ->assertOk()
            ->assertJsonStructure(['as_of', 'totals' => ['movies']]);
    }

    public function test_the_dashboard_uses_the_authenticated_owner_language_setting(): void
    {
        config(['javmeta.ui_locale' => 'en']);

        $this->actingAs($this->admin())
            ->withHeader('Accept-Language', 'fr')
            ->get('/ops')
            ->assertOk()
            ->assertSee('<html lang="en">', false)
            ->assertSee('Command Center');
    }

    public function test_the_dashboard_uses_accept_language_when_no_query_language_is_selected(): void
    {
        $this->actingAs($this->admin())
            ->withHeader('Accept-Language', 'vi')
            ->get('/ops')
            ->assertOk()
            ->assertSee('<html lang="vi">', false)
            ->assertSee('Trung tâm điều hành');
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

    public function test_the_login_form_does_not_prefill_an_email(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringNotContainsString('soulevilx@gmail.com', $html);
        $this->assertDoesNotMatchRegularExpression('/id="email"[^>]*value="[^"]+"/', $html);
    }

    public function test_the_login_page_is_vietnamese_xcrawler(): void
    {
        $this->get('/login?lang=vi')
            ->assertOk()
            ->assertSee('lang="vi"', false)
            ->assertSee('Đăng nhập · XCrawler', false)
            ->assertSee('<h1>Đăng nhập</h1>', false)
            ->assertSee('Kho phim này là riêng tư', false)
            ->assertDontSee('Operations sign in')
            ->assertDontSee('JavMeta Operations');
    }

    public function test_a_failed_sign_in_keeps_the_language_chosen_in_the_url(): void
    {
        $page = $this->get('/login?lang=vi');
        $page->assertSee('/login?lang=vi', false);

        $this->withHeader('Accept-Language', 'en')
            ->post('/login?lang=vi', ['email' => 'nobody@example.test', 'password' => 'wrong-password'])
            ->assertSessionHasErrors();
        $this->assertStringContainsString('không đúng', (string) session('errors')->first());
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
