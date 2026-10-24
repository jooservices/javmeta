<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\Movie;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UiTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role' => 'super_administrator']);
    }

    public function test_the_ui_shell_needs_the_owner_session_and_carries_the_csrf_token(): void
    {
        $this->get('/app')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'viewer']))->get('/app')->assertForbidden();

        $response = $this->actingAs($this->owner())->get('/app');
        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString('name="csrf-token"', $response->getContent());
        $this->assertStringContainsString('/ui/js/app.js', $response->getContent());
    }

    public function test_the_root_address_leads_to_the_ui(): void
    {
        $this->get('/')->assertRedirect(route('ui.index'));
    }

    public function test_the_api_bridge_serves_only_the_owner_and_only_reads(): void
    {
        $this->getJson('/ui-api/movies')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'viewer']))->getJson('/ui-api/movies')->assertForbidden();
        $this->actingAs($this->owner())->postJson('/ui-api/movies', [])->assertStatus(405);
        $this->actingAs($this->owner())->getJson('/ui-api/admin/keys')->assertNotFound();
        $this->actingAs($this->owner())->getJson('/ui-api/mcp')->assertNotFound();
    }

    public function test_the_bridge_returns_the_api_data_without_an_api_key(): void
    {
        Movie::factory()->count(3)->create();
        $owner = $this->owner();

        $list = $this->actingAs($owner)->getJson('/ui-api/movies?per_page=2')->assertOk();
        $list->assertJsonPath('success', true);
        $this->assertCount(2, $list->json('data'));
        $this->assertSame(3, $list->json('meta.pagination.total'));

        $this->actingAs($owner)->getJson('/ui-api/movies/NOPE-000')->assertNotFound()->assertJsonPath('type', 'movie_not_found');
        $this->actingAs($owner)->getJson('/ui-api/performers')->assertOk();
        $this->actingAs($owner)->getJson('/ui-api/meta/genres')->assertOk();
    }

    public function test_the_public_api_still_needs_a_key_and_ignores_the_internal_flag_from_clients(): void
    {
        $this->getJson('/api/v1/movies')->assertUnauthorized();
        $this->getJson('/api/v1/movies', ['javmeta.internal_ui' => 'true'])->assertUnauthorized();
        $this->getJson('/api/v1/status/summary')->assertUnauthorized();
    }

    public function test_status_endpoints_report_the_crawl_through_the_bridge(): void
    {
        Source::factory()->create(['slug' => 'demo', 'name' => 'Demo', 'enabled' => true]);
        $owner = $this->owner();

        $summary = $this->actingAs($owner)->getJson('/ui-api/status/summary')->assertOk();
        $summary->assertJsonStructure(['data' => ['counts' => ['movies', 'performers'], 'ingest', 'queue' => ['by_status'], 'runs_24h']]);
        $this->assertArrayNotHasKey('sources', $summary->json('data'));

        $sources = $this->actingAs($owner)->getJson('/ui-api/status/sources')->assertOk();
        $this->assertSame('demo', $sources->json('data.0.slug'));
        $this->assertSame(['pending', 'claimed', 'done', 'failed'], array_keys($sources->json('data.0.queue')));
    }
}
