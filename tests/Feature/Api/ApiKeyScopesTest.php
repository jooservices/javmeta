<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Services\Auth\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ApiKeyScopesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_scoped_key_reaches_only_its_scopes(): void
    {
        $key = app(ApiKeyService::class)->create('scoped', 60, ['catalog'])->plaintext;
        $headers = ['X-API-Key' => $key];

        $this->getJson('/api/v1/meta/catalog/makers', $headers)->assertOk();
        $this->getJson('/api/v1/suggest?q=abc', $headers)->assertOk();
        $this->getJson('/api/v1/performers', $headers)->assertStatus(403)->assertJsonPath('type', 'forbidden_scope');
        $this->getJson('/api/v1/movies/calendar', $headers)->assertStatus(403);
        $this->getJson('/api/v1/status/summary', $headers)->assertStatus(403);
    }

    public function test_a_key_without_scopes_keeps_full_access(): void
    {
        $headers = ['X-API-Key' => app(ApiKeyService::class)->create('all')->plaintext];

        $this->getJson('/api/v1/performers', $headers)->assertOk();
        $this->getJson('/api/v1/movies/calendar', $headers)->assertOk();
        $this->assertNull(ApiKey::query()->where('label', 'all')->first()->scopes);
    }
}
