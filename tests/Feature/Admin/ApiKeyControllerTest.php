<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\ApiKey;
use App\Models\ApiUsageLog;
use App\Services\Auth\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_admin_create_deduplicates_key_scopes(): void
    {
        $ownerToken = fake()->uuid();
        $this->setOwnerToken($ownerToken);

        $this->postJson('/api/v1/admin/keys', [
            'label' => fake()->words(2, true),
            'scopes' => ['movies', 'movies', 'catalog'],
        ], ['X-Owner-Token' => $ownerToken])
            ->assertCreated()
            ->assertJsonPath('data.scopes', ['movies', 'catalog']);
    }

    public function test_admin_create_and_revoke_emit_api_key_operations(): void
    {
        $ownerToken = fake()->uuid();
        $this->setOwnerToken($ownerToken);
        config()->set([
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.stream_logs' => 'javmeta_logs',
            'openobserve.email' => 'root@javmeta.local',
            'openobserve.password' => 'secret',
            'openobserve.timeout' => 1,
        ]);
        Http::fake(['openobserve.test/*' => Http::response(['code' => 200], 200)]);

        $created = $this->postJson('/api/v1/admin/keys', [
            'label' => fake()->words(2, true),
        ], ['X-Owner-Token' => $ownerToken])->assertCreated();
        $id = (int) $created->json('data.id');
        $this->deleteJson('/api/v1/admin/keys/' . $id, [], ['X-Owner-Token' => $ownerToken])->assertNoContent();

        $operations = collect(Http::recorded())
            ->filter(static fn(array $pair): bool => str_contains($pair[0]->url(), '/_json'))
            ->flatMap(static function (array $pair): array {
                $records = json_decode($pair[0]->body(), true);

                return is_array($records) ? $records : [];
            })
            ->filter(static fn(array $record): bool => ($record['kind'] ?? null) === 'admin_api_key')
            ->map(static fn(array $record): array => ['action' => $record['action'] ?? null, 'api_key_id' => $record['api_key_id'] ?? null])
            ->values()
            ->all();

        $this->assertSame([
            ['action' => 'create', 'api_key_id' => $id],
            ['action' => 'revoke', 'api_key_id' => $id],
        ], $operations);
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

    public function test_admin_usage_summarizes_the_last_day_and_week_for_one_key(): void
    {
        $ownerToken = fake()->uuid();
        $this->setOwnerToken($ownerToken);
        $created = app(ApiKeyService::class)->create(fake()->words(2, true));
        ApiUsageLog::query()->create(['api_key_id' => $created->model->getKey(), 'endpoint' => '/movies', 'method' => 'GET', 'status_code' => 200, 'response_ms' => 10, 'created_at' => now()->subHour()]);
        ApiUsageLog::query()->create(['api_key_id' => $created->model->getKey(), 'endpoint' => '/movies', 'method' => 'GET', 'status_code' => 500, 'response_ms' => 30, 'created_at' => now()->subHours(2)]);
        ApiUsageLog::query()->create(['api_key_id' => $created->model->getKey(), 'endpoint' => '/search', 'method' => 'GET', 'status_code' => 429, 'response_ms' => 20, 'created_at' => now()->subDays(2)]);
        ApiUsageLog::query()->create(['api_key_id' => $created->model->getKey(), 'endpoint' => '/old', 'method' => 'GET', 'status_code' => 503, 'response_ms' => 100, 'created_at' => now()->subDays(8)]);

        $this->getJson('/api/v1/admin/keys/' . $created->model->getKey() . '/usage', [
            'X-Owner-Token' => $ownerToken,
        ])->assertOk()
            ->assertJsonPath('data.last_24h', ['calls' => 2, 'errors' => 1, 'avg_ms' => 20])
            ->assertJsonPath('data.last_7d', ['calls' => 3, 'errors' => 2, 'avg_ms' => 20])
            ->assertJsonPath('data.top_endpoints', [['endpoint' => '/movies', 'calls' => 2], ['endpoint' => '/search', 'calls' => 1]]);
    }

    public function test_admin_usage_returns_not_found_for_a_missing_key(): void
    {
        $ownerToken = fake()->uuid();
        $this->setOwnerToken($ownerToken);

        $this->getJson('/api/v1/admin/keys/999999/usage', ['X-Owner-Token' => $ownerToken])->assertNotFound();
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
