<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Models\Movie;
use App\Models\MovieCode;
use App\Models\Performer;
use App\Services\Auth\ApiKeyService;
use App\Services\Mcp\McpToolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class McpHttpEndpointTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey;

    private Movie $movie;

    private Performer $performer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiKey = app(ApiKeyService::class)->create('mcp-http-endpoint')->plaintext;
        config(['javmeta_auth.mcp_api_key' => '']);

        $this->movie = Movie::factory()->create([
            'display_code' => 'SSIS-001',
            'code_normalized' => 'SSIS001',
            'title_en' => 'MCP JAVM-89 test movie',
        ]);
        MovieCode::factory()->create([
            'movie_id' => $this->movie->id,
            'code' => 'SSIS-001',
            'code_normalized' => 'SSIS001',
            'kind' => MovieCode::KIND_DVD,
        ]);
        $this->performer = Performer::factory()->create([
            'name_romaji' => 'MCP JAVM-89 test performer',
            'bio_text' => 'MCP-JAVM-89 matching profile',
        ]);

        config([
            'elasticsearch.host' => 'http://es.test',
            'elasticsearch.embedder_url' => 'http://embedder.test',
            'openobserve.enabled' => false,
            'database.connections.mongodb.dsn' => '',
        ]);
        Http::fake([
            'http://es.test/*' => Http::response(['version' => ['number' => '8.15']], 200),
            'http://embedder.test/*' => Http::response(['status' => 'ok'], 200),
        ]);
    }

    public function test_initialize_over_http(): void
    {
        $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2024-11-05', 'capabilities' => [], 'clientInfo' => []],
        ], ['X-API-Key' => $this->apiKey])
            ->assertOk()
            ->assertJsonPath('result.protocolVersion', '2024-11-05')
            ->assertJsonPath('result.serverInfo.name', 'javmeta');
    }

    public function test_initialized_notification_is_accepted_over_http(): void
    {
        $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ], ['X-API-Key' => $this->apiKey])->assertAccepted();
    }

    public function test_tools_list_over_http_includes_every_input_schema(): void
    {
        $response = $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => (object) [],
        ], ['X-API-Key' => $this->apiKey])->assertOk();

        self::assertSame(app(McpToolService::class)->definitions(), $response->json('result.tools'));
        self::assertSame(array_column(self::toolInputExamples(), 0), collect($response->json('result.tools'))->pluck('name')->all());
    }

    #[DataProvider('toolCallCases')]
    public function test_each_exposed_tool_can_be_called_over_http_with_a_valid_key(string $name, array $arguments): void
    {
        $response = $this->callMcpTool($name, $this->resolveArguments($arguments));

        $response->assertOk()
            ->assertJsonPath('result.content.0.type', 'text')
            ->assertJsonPath('result.isError', false);

        $payload = $this->toolPayload($response->json('result.content.0.text'));
        self::assertArrayNotHasKey('error', $payload, "Tool {$name} returned an error: " . json_encode($payload));
    }

    public function test_every_tool_call_rejects_missing_and_revoked_api_keys(): void
    {
        $service = app(ApiKeyService::class);
        $revoked = $service->create('mcp-http-revoked');
        $service->revoke((int) $revoked->model->getKey());

        foreach (self::toolInputExamples() as [$name, $arguments]) {
            $body = [
                'jsonrpc' => '2.0',
                'id' => 3,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $this->resolveArguments($arguments)],
            ];

            $this->postJson('/api/v1/mcp', $body)->assertUnauthorized();
            $this->postJson('/api/v1/mcp', $body, ['X-API-Key' => $revoked->plaintext])->assertUnauthorized();
        }
    }

    public function test_optional_null_arguments_are_treated_as_absent_over_http(): void
    {
        $movieResponse = $this->callMcpTool('lookup_movies', [
            'q' => 'MCP JAVM-89 test movie',
            'maker' => null,
            'runtime_min' => null,
            'censored' => null,
            'sort' => null,
            'cursor' => null,
            'per_page' => null,
            'count_only' => null,
        ])->assertOk()->assertJsonPath('result.isError', false);
        $moviePayload = $this->toolPayload($movieResponse->json('result.content.0.text'));

        self::assertSame(10, $moviePayload['pagination']['per_page']);
        self::assertSame(1, $moviePayload['pagination']['total']);

        $performerResponse = $this->callMcpTool('lookup_performers', [
            'age_min' => null,
            'location' => null,
            'sort' => null,
            'cursor' => null,
            'per_page' => null,
            'count_only' => null,
        ])->assertOk()->assertJsonPath('result.isError', false);
        $performerPayload = $this->toolPayload($performerResponse->json('result.content.0.text'));

        self::assertSame(10, $performerPayload['pagination']['per_page']);
        self::assertGreaterThanOrEqual(1, $performerPayload['pagination']['total']);
    }

    #[DataProvider('invalidToolArguments')]
    public function test_invalid_tool_arguments_return_clear_tool_errors_without_http_errors(
        string $name,
        array $arguments,
        string $error,
        string $message,
    ): void {
        $response = $this->callMcpTool($name, $arguments)->assertOk()->assertJsonPath('result.isError', true);
        $payload = $this->toolPayload($response->json('result.content.0.text'));

        self::assertSame($error, $payload['error']);
        if ($message !== '') {
            self::assertStringContainsString($message, $payload['message']);
        }
    }

    public function test_movie_and_performer_details_match_the_rest_api_over_http(): void
    {
        $restMovie = $this->getJson('/api/v1/movies/SSIS-001', ['X-API-Key' => $this->apiKey])
            ->assertOk()
            ->json('data');
        $mcpMovie = $this->toolPayload($this->callMcpTool('get_movie', ['code' => 'SSIS-001'])->assertOk()->json('result.content.0.text'));
        self::assertSame($restMovie, $mcpMovie);

        $restPerformer = $this->getJson('/api/v1/performers/' . $this->performer->uuid, ['X-API-Key' => $this->apiKey])
            ->assertOk()
            ->json('data');
        $mcpPerformer = $this->toolPayload($this->callMcpTool('get_performer', ['id' => $this->performer->uuid])
            ->assertOk()
            ->json('result.content.0.text'));
        self::assertSame($restPerformer, $mcpPerformer);
    }

    public function test_tools_call_rejects_unknown_tool(): void
    {
        $this->callMcpTool('nope', [])->assertOk()->assertJsonPath('result.isError', true);
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
        $this->post('/api/v1/mcp', [], ['X-API-Key' => $this->apiKey, 'CONTENT_TYPE' => 'application/json'], '{bad json')
            ->assertBadRequest()
            ->assertJsonPath('error.code', -32700);
    }

    public static function toolCallCases(): array
    {
        return self::toolInputExamples();
    }

    public static function toolInputExamples(): array
    {
        return [
            'lookup_movies' => ['lookup_movies', ['per_page' => 1]],
            'get_movie' => ['get_movie', ['code' => 'SSIS-001']],
            'search' => ['search', ['q' => 'MCP-JAVM-89', 'entity' => 'performer']],
            'lookup_performers' => ['lookup_performers', ['q' => 'MCP-JAVM-89', 'per_page' => 1]],
            'get_counts' => ['get_counts', []],
            'crawl_status' => ['crawl_status', []],
            'system_status' => ['system_status', []],
            'get_performer' => ['get_performer', ['id' => '{{performer_uuid}}']],
            'get_catalog' => ['get_catalog', ['kind' => 'makers']],
            'suggest' => ['suggest', ['q' => 'abc']],
            'get_related_movies' => ['get_related_movies', ['code' => 'SSIS-001']],
            'get_release_calendar' => ['get_release_calendar', ['month' => '2026-10']],
            'search_scenes' => ['search_scenes', ['q' => 'generated scene', 'per_page' => 5]],
        ];
    }

    public static function invalidToolArguments(): array
    {
        return [
            'missing movie code' => ['get_movie', [], 'invalid_filter', 'code is required.'],
            'malformed movie code' => ['get_movie', ['code' => '!!!'], 'invalid_code', ''],
            'non-string sort' => ['lookup_movies', ['sort' => ['release_date']], 'invalid_filter', 'sort must be a string.'],
            'non-string filter' => ['lookup_movies', ['maker' => ['Studio']], 'invalid_filter', 'maker must be a string.'],
            'invalid page size' => ['lookup_movies', ['per_page' => []], 'invalid_filter', 'per_page must be an integer.'],
            'invalid date' => ['lookup_movies', ['released_from' => '2026-02-30'], 'invalid_filter', 'valid date in YYYY-MM-DD format.'],
            'NUL byte in a date' => ['lookup_movies', ['released_from' => "2026-01-01\0"], 'invalid_filter', 'released_from must not contain control characters.'],
            'NUL byte in a text filter' => ['lookup_movies', ['maker' => "Studio\0"], 'invalid_filter', 'maker must not contain control characters.'],
            'invalid censorship flag' => ['lookup_movies', ['censored' => 'unknown'], 'invalid_filter', 'censored must be censored, uncensored, 0 or 1.'],
            'invalid count flag' => ['lookup_movies', ['count_only' => 'sometimes'], 'invalid_filter', 'count_only must be a boolean.'],
            'non-string search query' => ['search', ['q' => 42], 'invalid_filter', 'q must be a string.'],
            'invalid search entity' => ['search', ['q' => 'MCP', 'entity' => []], 'invalid_filter', 'entity must be movie, performer or all.'],
            'non-integer performer filter' => ['lookup_performers', ['age_min' => 'adult'], 'invalid_filter', 'age_min must be an integer.'],
            'non-string performer cursor' => ['lookup_performers', ['cursor' => []], 'invalid_filter', 'cursor must be a string.'],
            'non-string performer id' => ['get_performer', ['id' => []], 'invalid_filter', 'id must be a string.'],
        ];
    }

    /** @param array<string, mixed> $arguments */
    private function callMcpTool(string $name, array $arguments): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 10,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ], ['X-API-Key' => $this->apiKey]);
    }

    /** @param array<string, mixed> $arguments
     *  @return array<string, mixed>
     */
    private function resolveArguments(array $arguments): array
    {
        foreach ($arguments as $key => $value) {
            if ($value === '{{performer_uuid}}') {
                $arguments[$key] = $this->performer->uuid;
            }
        }

        return $arguments;
    }

    /** @return array<string, mixed> */
    private function toolPayload(string $text): array
    {
        $payload = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }
}
