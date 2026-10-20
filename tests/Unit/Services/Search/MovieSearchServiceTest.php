<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Search;

use App\Models\Movie;
use App\Repositories\MovieRepository;
use App\Services\Embedding\EmbedderClient;
use App\Services\Search\ElasticsearchHttpClient;
use App\Services\Search\ElasticsearchIndexer;
use App\Services\Search\MovieSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class MovieSearchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_keyword_search_hydrates_and_preserves_elasticsearch_order(): void
    {
        $first = Movie::factory()->create();
        $second = Movie::factory()->create();
        $query = fake()->word();
        Http::fake(fn() => Http::response([
            'hits' => ['hits' => [
                ['_source' => ['uuid' => $second->uuid]],
                ['_source' => ['uuid' => $first->uuid]],
            ]],
        ], 200));

        $service = new MovieSearchService(
            new ElasticsearchIndexer(new EmbedderClient(), app(ElasticsearchHttpClient::class)),
            app(MovieRepository::class),
        );
        $this->enableElasticsearch();

        $result = $service->search(['q' => $query], 'relevance', null, 2);

        $this->assertSame([$second->uuid, $first->uuid], $result['items']->pluck('uuid')->all());
        $this->assertSame(2, $result['total']);
        $this->assertFalse($result['has_more']);
    }

    public function test_stale_elasticsearch_hits_fall_back_to_repository_search(): void
    {
        $movie = Movie::factory()->create(['title_en' => fake()->sentence()]);
        Http::fake(fn() => Http::response([
            'hits' => ['hits' => [['_source' => ['uuid' => fake()->uuid()]]]],
        ], 200));

        $this->enableElasticsearch();
        $result = (new MovieSearchService(
            new ElasticsearchIndexer(new EmbedderClient(), app(ElasticsearchHttpClient::class)),
            app(MovieRepository::class),
        ))
            ->search(['q' => $movie->title_en], 'relevance', null, 1);

        $this->assertSame($movie->uuid, $result['items']->first()?->uuid);
    }

    public function test_semantic_search_hydrates_elasticsearch_results_when_not_testing(): void
    {
        $movie = Movie::factory()->create();
        Http::fake(function ($request) use ($movie) {
            if (str_ends_with($request->url(), '/v1/embeddings')) {
                return Http::response(['data' => [['embedding' => [fake()->randomFloat(6, -1, 1)]]]], 200);
            }

            return Http::response([
                'hits' => ['hits' => [['_source' => ['uuid' => $movie->uuid]]]],
            ], 200);
        });

        $this->enableElasticsearch();
        $result = (new MovieSearchService(
            new ElasticsearchIndexer(new EmbedderClient(), app(ElasticsearchHttpClient::class)),
            app(MovieRepository::class),
        ))
            ->semanticSearch(fake()->sentence(), 1);

        $this->assertSame([$movie->uuid], $result['items']->pluck('uuid')->all());
        $this->assertSame('relevance', $result['sort']);
    }

    private function enableElasticsearch(): void
    {
        $this->app->instance('env', 'production');
    }
}
