<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\ApiKey;
use App\Services\Auth\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ApiKeyControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_guard_rejects_missing_owner_token(): void
    {
        $this->setOwnerToken(fake()->uuid());

        $this->getJson('/api/v1/admin/keys')
            ->assertUnauthorized()
            ->assertJsonPath('type', 'unauthorized');
    }

    public function test_admin_can_create_key_and_plaintext_is_returned_once(): void
    {
        $ownerToken = fake()->uuid();
        $this->setOwnerToken($ownerToken);

        $response = $this->postJson('/api/v1/admin/keys', [
            'label' => fake()->words(2, true),
            'abuse_rpm' => 7,
        ], [
            'X-Owner-Token' => $ownerToken,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.abuse_rpm', 7)
            ->assertJsonPath('data.status', ApiKey::STATUS_ACTIVE)
            ->assertJsonStructure(['data' => ['id', 'prefix', 'plaintext']]);

        $plaintext = (string) $response->json('data.plaintext');
        $this->assertStringStartsWith('jvm_', $plaintext);
        $this->assertDatabaseMissing('api_keys', ['key_hash' => $plaintext]);

        $this->getJson('/api/v1/admin/keys', [
            'X-Owner-Token' => $ownerToken,
        ])->assertOk()
            ->assertJsonMissingPath('data.0.plaintext')
            ->assertJsonPath('data.0.prefix', substr($plaintext, 0, 12));
    }

    public function test_admin_can_list_active_and_revoked_keys(): void
    {
        $ownerToken = fake()->uuid();
        $this->setOwnerToken($ownerToken);
        $service = app(ApiKeyService::class);
        $active = $service->create(fake()->words(2, true));
        $revoked = $service->create(fake()->words(2, true));
        $service->revoke((int) $revoked->model->getKey());

        $response = $this->getJson('/api/v1/admin/keys', [
            'X-Owner-Token' => $ownerToken,
        ]);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($active->model->getKey(), $ids);
        $this->assertContains($revoked->model->getKey(), $ids);
        $this->assertSame(ApiKey::STATUS_REVOKED, ApiKey::query()->find($revoked->model->getKey())?->status);
    }

    public function test_revoke_makes_key_immediately_unauthorized(): void
    {
        $ownerToken = fake()->uuid();
        $this->setOwnerToken($ownerToken);
        $created = app(ApiKeyService::class)->create(fake()->words(2, true));

        $this->getJson('/api/v1/auth/verify', [
            'X-API-Key' => $created->plaintext,
        ])->assertOk();

        $this->deleteJson('/api/v1/admin/keys/' . $created->model->getKey(), [], [
            'X-Owner-Token' => $ownerToken,
        ])->assertNoContent();

        $this->getJson('/api/v1/auth/verify', [
            'X-API-Key' => $created->plaintext,
        ])->assertUnauthorized();
    }

    private function setOwnerToken(string $ownerToken): void
    {
        config(['javmeta_auth.owner_admin_token' => $ownerToken]);
    }
}
