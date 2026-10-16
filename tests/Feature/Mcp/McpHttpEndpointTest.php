<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Models\Performer;
use App\Services\Auth\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class McpHttpEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_initialize_over_http(): void
    {
        $key = app(ApiKeyService::class)->create(fake()->words(2, true))->plaintext;

        $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2024-11-05', 'capabilities' => [], 'clientInfo' => []],
        ], ['X-Api-Key' => $key])
            ->assertOk()
            ->assertJsonPath('result.protocolVersion', '2024-11-05')
            ->assertJsonPath('result.serverInfo.name', 'javmeta');
    }

    public function test_tools_list_over_http(): void
    {
        $key = app(ApiKeyService::class)->create(fake()->words(2, true))->plaintext;

        $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => (object) [],
        ], ['X-Api-Key' => $key])
            ->assertOk()
            ->assertJsonCount(8, 'result.tools')
            ->assertJsonPath('result.tools.0.name', 'lookup_movies');
    }

    public function test_tools_call_lookup_movies_over_http(): void
    {
        $key = app(ApiKeyService::class)->create(fake()->words(2, true))->plaintext;
        config(['javmeta_auth.mcp_api_key' => $key]);

        $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'lookup_movies', 'arguments' => ['per_page' => 1]],
        ], ['X-Api-Key' => $key])
            ->assertOk()
            ->assertJsonPath('result.content.0.type', 'text');
    }

    public function test_tools_call_search_supports_performer_entity_over_http(): void
    {
        $key = app(ApiKeyService::class)->create(fake()->words(2, true))->plaintext;
        config(['javmeta_auth.mcp_api_key' => $key]);
        $term = fake()->unique()->words(2, true);
        $performer = Performer::factory()->create(['bio_text' => "Profile {$term}"]);

        $response = $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'tools/call',
            'params' => [
                'name' => 'search',
                'arguments' => ['q' => $term, 'entity' => 'performer'],
            ],
        ], ['X-Api-Key' => $key]);

        $response->assertOk()->assertJsonPath('result.content.0.type', 'text');
        $payload = json_decode((string) $response->json('result.content.0.text'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($performer->uuid, $payload['items'][0]['uuid']);
    }

    public function test_tools_call_rejects_unknown_tool(): void
    {
        $key = app(ApiKeyService::class)->create(fake()->words(2, true))->plaintext;
        config(['javmeta_auth.mcp_api_key' => $key]);

        $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => ['name' => 'nope'],
        ], ['X-Api-Key' => $key])
            ->assertOk()
            ->assertJsonPath('result.isError', true);
    }

    public function test_requires_api_key(): void
    {
        $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [],
        ])->assertUnauthorized();
    }

    public function test_returns_parse_error_for_bad_json(): void
    {
        $key = app(ApiKeyService::class)->create(fake()->words(2, true))->plaintext;

        $this->post('/api/v1/mcp', [], ['X-Api-Key' => $key, 'CONTENT_TYPE' => 'application/json'], '{bad json')
            ->assertBadRequest()
            ->assertJsonPath('error.code', -32700);
    }
}
