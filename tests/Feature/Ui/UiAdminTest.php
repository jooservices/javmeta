<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UiAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_needs_the_owner_session(): void
    {
        $this->get('/ui-admin/keys')->assertRedirect();
        $this->postJson('/ui-admin/keys', ['label' => 'x'])->assertStatus(401);
    }

    public function test_the_owner_creates_lists_checks_and_revokes_a_key(): void
    {
        $owner = User::factory()->create(['role' => 'super_administrator']);
        $this->actingAs($owner);

        $created = $this->postJson('/ui-admin/keys', ['label' => 'ui-made', 'abuse_rpm' => 30, 'scopes' => ['catalog', 'status']])->assertCreated();
        $id = $created->json('data.id');
        $this->assertStringStartsWith('jvm_', $created->json('data.plaintext'));
        $this->assertSame(['catalog', 'status'], ApiKey::query()->find($id)->scopes);

        $this->getJson('/ui-admin/keys')->assertOk()->assertJsonPath('data.0.label', 'ui-made');
        $this->getJson('/ui-admin/keys/' . $id . '/usage')->assertOk()->assertJsonPath('data.last_24h.calls', 0);
        $this->getJson('/ui-admin/report/quality')->assertOk()->assertJsonStructure(['data' => ['movies', 'core_fields']]);
        $this->deleteJson('/ui-admin/keys/' . $id)->assertNoContent();
        $this->assertSame('revoked', ApiKey::query()->find($id)->status);
        $this->postJson('/ui-admin/keys', ['label' => 'bad', 'scopes' => ['nope']])->assertStatus(422);
    }

    public function test_the_owner_can_create_a_key_limited_to_browser_service_configuration(): void
    {
        $owner = User::factory()->create(['role' => 'super_administrator']);
        $this->actingAs($owner);

        $created = $this->postJson('/ui-admin/keys', ['label' => 'browser-config', 'scopes' => ['config']])->assertCreated();
        $this->assertSame(['config'], ApiKey::query()->find($created->json('data.id'))->scopes);
    }
}
