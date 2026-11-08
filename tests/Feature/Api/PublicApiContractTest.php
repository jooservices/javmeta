<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Genre;
use App\Models\Movie;
use App\Models\MovieCode;
use App\Models\Performer;
use App\Services\Auth\ApiKeyService;
use App\Services\Crawl\WorkerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\Support\DependencyMonitorTestHelper;

final class PublicApiContractTest extends BaseTestCase
{
    use RefreshDatabase;

    private string $apiKey;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('queue.default', 'sync');
        DependencyMonitorTestHelper::bind();
        $this->apiKey = app(ApiKeyService::class)->create('public-api-contract', 120)->plaintext;
    }

    public function test_documented_data_routes_reject_missing_and_revoked_api_keys(): void
    {
        $apiKeys = app(ApiKeyService::class);
        $revoked = $apiKeys->create('revoked-public-api-contract');
        $apiKeys->revoke((int) $revoked->model->getKey());

        $endpoints = [
            ['GET', '/api/v1/movies/SSIS-001', []],
            ['GET', '/api/v1/movies', []],
            ['POST', '/api/v1/movies:bulk', ['codes' => ['SSIS-001']]],
            ['GET', '/api/v1/performers', []],
            ['GET', '/api/v1/performers/1', []],
            ['GET', '/api/v1/meta/genres', []],
            ['GET', '/api/v1/auth/verify', []],
        ];

        foreach ([[], ['X-API-Key' => $revoked->plaintext]] as $headers) {
            foreach ($endpoints as [$method, $uri, $payload]) {
                $response = $this->callApiEndpoint($method, $uri, $payload, $headers);

                $response->assertUnauthorized()->assertJsonPath('type', 'unauthorized');
                self::assertSame('API key is missing, invalid, or revoked.', $response->json('detail'));
            }
        }
    }

    public function test_movie_lookup_search_and_cursor_pagination_follow_the_documented_contract(): void
    {
        $movie = Movie::factory()->create(['display_code' => 'SSIS-001', 'code_normalized' => 'SSIS001']);
        MovieCode::factory()->create([
            'movie_id' => $movie->id,
            'code' => 'SSIS-001',
            'code_normalized' => 'SSIS001',
            'source_slug' => 'javdb',
        ]);
        Movie::factory()->create();

        foreach (['ssis001', 'SSIS-1', ' ssis-001 '] as $variant) {
            $this->getJson('/api/v1/movies/' . rawurlencode($variant), ['X-API-Key' => $this->apiKey])
                ->assertOk()
                ->assertJsonPath('data.code', 'SSIS-001');
        }

        $this->getJson('/api/v1/movies/XYZ-999', ['X-API-Key' => $this->apiKey])
            ->assertNotFound()
            ->assertJsonPath('type', 'movie_not_found');

        $search = $this->getJson('/api/v1/movies', ['X-API-Key' => $this->apiKey]);
        $search->assertOk()->assertJsonPath('meta.pagination.total', 2);

        $invalidFilter = $this->getJson('/api/v1/movies?sort=unknown', ['X-API-Key' => $this->apiKey]);
        $invalidFilter->assertBadRequest()->assertJsonPath('type', 'invalid_filter');
        self::assertSame('Invalid movie search filter.', $invalidFilter->json('detail'));
        self::assertIsArray($invalidFilter->json('errors'));

        $firstPage = $this->getJson('/api/v1/movies?sort=release_date&per_page=1', ['X-API-Key' => $this->apiKey]);
        $firstPage->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.pagination.total', 2);
        $secondPage = $this->getJson(
            '/api/v1/movies?sort=release_date&per_page=1&cursor=' . urlencode((string) $firstPage->json('meta.pagination.next_cursor')),
            ['X-API-Key' => $this->apiKey],
        );
        $secondPage->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.pagination.total', 2);
        $emptyPage = $this->getJson(
            '/api/v1/movies?sort=release_date&per_page=1&cursor=' . urlencode((string) $secondPage->json('meta.pagination.next_cursor')),
            ['X-API-Key' => $this->apiKey],
        );

        $emptyPage->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['pagination' => ['total', 'has_more', 'next_cursor']]])
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('meta.pagination.has_more', false)
            ->assertJsonPath('meta.pagination.next_cursor', null);
    }

    public function test_bulk_performer_and_genre_routes_return_the_documented_shapes(): void
    {
        $movie = Movie::factory()->create(['display_code' => 'ABC-123', 'code_normalized' => 'ABC123']);
        MovieCode::factory()->create([
            'movie_id' => $movie->id,
            'code' => 'ABC-123',
            'code_normalized' => 'ABC123',
            'source_slug' => 'javdb',
        ]);

        $bulk = $this->postJson('/api/v1/movies:bulk', [
            'codes' => ['ABC-123', 'XYZ-999'],
        ], ['X-API-Key' => $this->apiKey]);
        $bulk->assertOk()
            ->assertJsonPath('data.0.status', 'found')
            ->assertJsonPath('data.0.movie.code', 'ABC-123')
            ->assertJsonPath('data.1.status', 'not_found')
            ->assertJsonPath('data.1.movie', null);

        $invalidBulk = $this->postJson('/api/v1/movies:bulk', [], ['X-API-Key' => $this->apiKey]);
        $invalidBulk->assertBadRequest()->assertJsonPath('type', 'invalid_filter');
        self::assertNotSame('', trim((string) $invalidBulk->json('detail')));

        $performer = Performer::factory()->create();
        $this->getJson('/api/v1/performers', ['X-API-Key' => $this->apiKey])
            ->assertOk()
            ->assertJsonPath('data.0.id', $performer->id)
            ->assertJsonPath('meta.pagination.total', 1);
        $this->getJson('/api/v1/performers/' . $performer->id, ['X-API-Key' => $this->apiKey])
            ->assertOk()
            ->assertJsonPath('data.id', $performer->id);
        $this->getJson('/api/v1/performers/999999', ['X-API-Key' => $this->apiKey])
            ->assertNotFound()
            ->assertJsonPath('type', 'performer_not_found');

        $invalidPerformerFilter = $this->getJson('/api/v1/performers?age_min=40&age_max=20', ['X-API-Key' => $this->apiKey]);
        $invalidPerformerFilter->assertBadRequest()->assertJsonPath('type', 'invalid_filter');
        self::assertSame('Invalid performer search filter.', $invalidPerformerFilter->json('detail'));

        Genre::factory()->create(['label_normalized' => 'drama', 'label_raw' => 'Drama']);
        Genre::factory()->create(['label_normalized' => 'comedy', 'label_raw' => 'Comedy']);
        $this->getJson('/api/v1/meta/genres', ['X-API-Key' => $this->apiKey])
            ->assertOk()
            ->assertJsonPath('data', ['Comedy', 'Drama']);
    }

    public function test_auth_verify_and_rate_limit_return_documented_responses(): void
    {
        $this->getJson('/api/v1/auth/verify', ['X-API-Key' => $this->apiKey])
            ->assertOk()
            ->assertJsonPath('data.status', 'ok');

        $apiKey = app(ApiKeyService::class)->create('limited-public-api-contract', 1);
        RateLimiter::clear('api-key:' . $apiKey->model->getKey());

        $this->getJson('/api/v1/auth/verify', ['X-API-Key' => $apiKey->plaintext])->assertOk();
        $limited = $this->getJson('/api/v1/auth/verify', ['X-API-Key' => $apiKey->plaintext]);

        $limited->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonPath('type', 'rate_limited');
        self::assertSame('API rate limit exceeded.', $limited->json('detail'));
    }

    public function test_health_is_public_and_documents_current_service_status(): void
    {
        app(WorkerHeartbeat::class)->beat();

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('database', 'ok');
    }

    public function test_public_endpoint_contract_is_documented(): void
    {
        $document = file_get_contents(dirname(__DIR__, 3) . '/docs/03-operations/api-contract.md');
        self::assertIsString($document);

        foreach ([
            '`GET /api/v1/movies/{code}`',
            '`GET /api/v1/movies`',
            '`POST /api/v1/movies:bulk`',
            '`GET /api/v1/performers`',
            '`GET /api/v1/performers/{id}`',
            '`GET /api/v1/meta/genres`',
            '`GET /api/v1/auth/verify`',
            '`GET /api/health`',
            '401',
            '404',
            '400',
            '429',
            'invalid_filter',
            'PublicApiContractTest',
        ] as $contract) {
            self::assertStringContainsString($contract, $document, "The API contract documentation is missing {$contract}.");
        }
    }

    /** @param array<string, mixed> $payload @param array<string, string> $headers */
    private function callApiEndpoint(string $method, string $uri, array $payload, array $headers): TestResponse
    {
        return match ($method) {
            'GET' => $this->getJson($uri, $headers),
            'POST' => $this->postJson($uri, $payload, $headers),
            default => self::fail("Unsupported contract method {$method}."),
        };
    }
}
