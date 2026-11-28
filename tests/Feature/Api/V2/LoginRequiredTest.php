<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V2;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ui_security.login_required (on by default) keeps the v2 data behind sign-in, as the legacy UI bridge does. */
final class LoginRequiredTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_reaches_only_what_signing_in_needs_while_login_is_required(): void
    {
        config()->set('javmeta.ui_login_required', true);

        $this->getJson('/api/v2/openapi.json')->assertOk();
        $this->getJson('/api/v2/sessions/current')->assertOk()->assertJsonPath('data.scope', 'guest')->assertJsonPath('data.login_required', true);
        $this->postJson('/api/v2/password-resets', ['email' => 'nobody@example.test'])->assertSuccessful();

        foreach (['movies', 'movies/facets', 'performers', 'search?q=a', 'statistics/overview', 'status/summary', 'catalogs/genres', 'calendar', 'updates'] as $path) {
            $this->getJson('/api/v2/' . $path)->assertUnauthorized()->assertJsonPath('code', 'authentication_required');
        }
    }

    public function test_a_guest_may_read_the_public_operations_when_login_is_not_required(): void
    {
        config()->set('javmeta.ui_login_required', false);

        $this->getJson('/api/v2/status/summary')->assertOk();
        $this->getJson('/api/v2/sessions/current')->assertOk()->assertJsonPath('data.login_required', false);
    }

    public function test_a_signed_in_user_reads_the_data_while_login_is_required(): void
    {
        config()->set('javmeta.ui_login_required', true);

        $this->actingAs(User::factory()->create(), 'web')->getJson('/api/v2/status/summary')->assertOk();
    }

    public function test_a_guest_may_still_ask_for_download_links_while_login_is_required(): void
    {
        config()->set('javmeta.ui_login_required', true);

        $this->getJson('/api/v2/download-requests')->assertOk();
        $this->postJson('/api/v2/download-requests', [])->assertStatus(422)->assertJsonPath('errors.0.field', 'movie_code');
    }

    public function test_a_guest_never_sees_the_statistics_even_when_login_is_not_required(): void
    {
        config()->set('javmeta.ui_login_required', false);

        foreach (['overview', 'series', 'distributions', 'correlations', 'data-quality', 'crawl-health', 'contributions', 'sources-active', 'dashboard', 'compare'] as $page) {
            $this->getJson('/api/v2/statistics/' . $page)->assertUnauthorized();
        }
    }
}
