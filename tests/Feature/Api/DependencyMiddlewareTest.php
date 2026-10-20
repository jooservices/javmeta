<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Movie;
use App\Models\MovieCode;
use App\Services\Auth\ApiKeyService;
use App\Services\Dependencies\Dependency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DependencyMonitorTestHelper;
use Tests\TestCase;

final class DependencyMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->apiKey = app(ApiKeyService::class)->create(fake()->words(2, true))->plaintext;
        DependencyMonitorTestHelper::bind();
    }

    public function test_movie_search_returns_database_results_when_elasticsearch_is_down(): void
    {
        $movie = Movie::factory()->create(['title_en' => 'Quiet Harbor']);
        DependencyMonitorTestHelper::bind([Dependency::Elasticsearch->value => false]);

        $this->getJson('/api/v1/movies?q=quiet%20harbor', ['X-API-Key' => $this->apiKey])
            ->assertOk()
            ->assertJsonPath('data.0.title_en', $movie->title_en);
    }

    public function test_movie_lookup_remains_available_when_elasticsearch_is_down(): void
    {
        $movie = Movie::factory()->create();
        MovieCode::factory()->create([
            'movie_id' => $movie->id,
            'code' => $movie->display_code,
            'code_normalized' => $movie->code_normalized,
        ]);
        DependencyMonitorTestHelper::bind([Dependency::Elasticsearch->value => false]);

        $this->getJson('/api/v1/movies/' . $movie->display_code, ['X-API-Key' => $this->apiKey])
            ->assertOk()
            ->assertJsonPath('data.code', $movie->display_code);
    }

    public function test_postgres_down_returns_503_for_data_routes(): void
    {
        DependencyMonitorTestHelper::bind([Dependency::Postgres->value => false]);

        $this->getJson('/api/v1/meta/genres', ['X-API-Key' => $this->apiKey])
            ->assertServiceUnavailable()
            ->assertHeader('Retry-After', '30')
            ->assertJsonPath('type', 'dependency_unavailable');
    }

    public function test_unauthenticated_search_still_returns_401_when_elasticsearch_is_down(): void
    {
        DependencyMonitorTestHelper::bind([Dependency::Elasticsearch->value => false]);

        $this->getJson('/api/v1/movies')
            ->assertUnauthorized()
            ->assertJsonPath('type', 'unauthorized');
    }
}
