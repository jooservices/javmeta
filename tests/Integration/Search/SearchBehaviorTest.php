<?php

declare(strict_types=1);

namespace Tests\Integration\Search;

use App\Models\Movie;
use App\Models\MoviePerformer;
use App\Models\Performer;
use App\Services\Auth\ApiKeyService;
use App\Services\Mcp\McpToolService;
use App\Services\Search\ElasticsearchIndexer;
use Illuminate\Support\Facades\Http;
use Tests\Integration\IntegrationTestCase;

final class SearchBehaviorTest extends IntegrationTestCase
{
    private string $apiKey;

    protected function setUp(): void
    {
        parent::setUp();

        // Search services deliberately use the database only in the unit and feature test environment.
        $this->app->instance('env', 'local');
        $this->apiKey = app(ApiKeyService::class)->create('search-integration')->plaintext;
    }

    protected function tearDown(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        $embedder = rtrim((string) config('elasticsearch.embedder_url'), '/');
        Http::fake([
            $embedder . '/*' => static fn() => Http::response(['data' => []]),
        ]);

        foreach ([config('elasticsearch.movies_index'), config('elasticsearch.performers_index')] as $index) {
            $response = $this->es()->delete('integration_cleanup', '/' . $index, acceptedStatuses: [404]);
            self::assertTrue($response->successful() || $response->status() === 404);
        }

        parent::tearDown();
    }

    public function test_rest_and_mcp_keyword_search_find_movie_by_code_japanese_title_english_title_and_performer(): void
    {
        $movie = $this->indexMovieWithPerformer();

        foreach (['NOVA-001', '雨の東京で奏でる夜', 'Rainy Night Session', 'Mina Example'] as $query) {
            $response = $this->getJson('/api/v1/movies?' . http_build_query(['q' => $query]), [
                'X-API-Key' => $this->apiKey,
            ]);

            $response->assertOk()->assertJsonPath('data.0.code', $movie->display_code);

            $mcp = app(McpToolService::class)->call('lookup_movies', ['q' => $query]);
            self::assertSame($movie->display_code, $mcp['items'][0]['code'] ?? null, "MCP keyword search missed query: {$query}");
        }
    }

    public function test_rest_and_mcp_semantic_search_find_a_movie_by_descriptive_phrase(): void
    {
        $movie = $this->indexMovieWithPerformer();
        $query = 'a quiet musician walking through Tokyo rain after a late shift';

        $response = $this->getJson('/api/v1/movies?' . http_build_query([
            'q' => $query,
            'semantic' => 'true',
        ]), [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.0.code', $movie->display_code)
            ->assertJsonPath('meta.pagination.sort', 'relevance')
            ->assertJsonPath('meta.pagination.next_cursor', null);

        $mcp = app(McpToolService::class)->call('search', [
            'q' => $query,
            'entity' => 'movie',
        ]);

        self::assertSame($movie->display_code, $mcp['items'][0]['code'] ?? null);
    }

    public function test_keyword_search_remains_available_when_the_embedder_is_unavailable(): void
    {
        $movie = $this->indexMovieWithPerformer();
        $embedder = rtrim((string) config('elasticsearch.embedder_url'), '/');
        Http::fake([
            $embedder . '/*' => Http::response(['error' => 'unavailable'], 503),
        ]);

        $this->getJson('/api/v1/movies?' . http_build_query(['q' => 'NOVA-001']), [
            'X-API-Key' => $this->apiKey,
        ])
            ->assertOk()
            ->assertJsonPath('data.0.code', $movie->display_code);

        $this->getJson('/api/v1/movies?' . http_build_query([
            'q' => 'Rainy Night Session',
            'semantic' => 'true',
        ]), [
            'X-API-Key' => $this->apiKey,
        ])
            ->assertOk()
            ->assertJsonPath('data.0.code', $movie->display_code);
    }

    public function test_disabling_search_returns_database_keyword_results_without_calling_elasticsearch(): void
    {
        $movie = $this->indexMovieWithPerformer();
        config()->set('elasticsearch.enabled', false);
        Http::fake();

        $this->getJson('/api/v1/movies?' . http_build_query(['q' => 'Rainy Night Session']), [
            'X-API-Key' => $this->apiKey,
        ])
            ->assertOk()
            ->assertJsonPath('data.0.code', $movie->display_code);

        Http::assertNothingSent();
    }

    private function indexMovieWithPerformer(): Movie
    {
        $movie = Movie::factory()->create([
            'display_code' => 'NOVA-001',
            'code_normalized' => 'NOVA001',
            'title_jp' => '雨の東京で奏でる夜',
            'title_en' => 'Rainy Night Session',
            'description' => 'A quiet musician walks through Tokyo rain after a late shift.',
        ]);
        $performer = Performer::factory()->create([
            'name_romaji' => 'Mina Example',
            'name_kanji' => '美奈例',
        ]);
        MoviePerformer::factory()->create([
            'movie_id' => $movie->id,
            'performer_id' => $performer->id,
            'source_slug' => $performer->source_slug,
        ]);

        self::assertTrue(app(ElasticsearchIndexer::class)->indexMovie($movie, requireVector: true));
        self::assertTrue($this->es()->get(
            'integration_refresh',
            '/' . config('elasticsearch.movies_index') . '/_refresh',
            10,
        )->successful());

        return $movie;
    }
}
