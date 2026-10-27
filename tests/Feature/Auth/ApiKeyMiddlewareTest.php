<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\ApiKey;
use App\Services\Auth\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

final class ApiKeyMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_api_key_returns_unauthorized_problem_without_data(): void
    {
        $response = $this->getJson('/api/v1/auth/verify');

        $response->assertUnauthorized()
            ->assertJsonPath('type', 'unauthorized')
            ->assertJsonMissingPath('data');
    }

    public function test_wrong_api_key_returns_unauthorized_problem_without_data(): void
    {
        $response = $this->getJson('/api/v1/auth/verify', [
            'X-API-Key' => 'jvm_' . fake()->sha256(),
        ]);

        $response->assertUnauthorized()
            ->assertJsonPath('type', 'unauthorized')
            ->assertJsonMissingPath('data');
    }

    public function test_active_api_key_lookup_returns_the_cached_model_without_a_database_read(): void
    {
        $service = app(ApiKeyService::class);
        $created = $service->create(fake()->words(2, true));
        Cache::shouldReceive('get')->once()->andReturn($created->model);
        Cache::shouldReceive('put')->never();

        $this->assertSame($created->model, $service->findActiveByPlaintext($created->plaintext));
    }

    public function test_cache_errors_fall_back_to_database_authentication(): void
    {
        $service = app(ApiKeyService::class);
        $created = $service->create(fake()->words(2, true));
        Cache::shouldReceive('get')->once()->andThrow(new RuntimeException('Redis unavailable'));
        Cache::shouldReceive('put')->once();

        $resolved = $service->findActiveByPlaintext($created->plaintext);

        $this->assertNotNull($resolved);
        $this->assertSame($created->model->getKey(), $resolved->getKey());
    }

    public function test_revoked_api_key_returns_unauthorized_problem_without_data(): void
    {
        $service = app(ApiKeyService::class);
        $created = $service->create(fake()->words(2, true));
        $service->findActiveByPlaintext($created->plaintext);

        $service->revoke((int) $created->model->getKey());

        $response = $this->getJson('/api/v1/auth/verify', [
            'X-API-Key' => $created->plaintext,
        ]);

        $response->assertUnauthorized()
            ->assertJsonPath('type', 'unauthorized')
            ->assertJsonMissingPath('data');
    }

    public function test_revocation_succeeds_when_cache_invalidation_fails(): void
    {
        $created = app(ApiKeyService::class)->create(fake()->words(2, true));
        Cache::shouldReceive('forget')->once()->andThrow(new RuntimeException('Redis unavailable'));

        app(ApiKeyService::class)->revoke((int) $created->model->getKey());

        $this->assertDatabaseHas('api_keys', [
            'id' => $created->model->getKey(),
            'status' => ApiKey::STATUS_REVOKED,
        ]);
    }

    public function test_valid_api_key_logs_successful_usage(): void
    {
        $created = app(ApiKeyService::class)->create(fake()->words(2, true));

        $response = $this->getJson('/api/v1/auth/verify', [
            'X-API-Key' => $created->plaintext,
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'ok');
        $this->assertDatabaseHas('api_usage_log', [
            'api_key_id' => $created->model->getKey(),
            'endpoint' => '/api/v1/auth/verify',
            'method' => 'GET',
            'status_code' => 200,
        ]);
    }
}
