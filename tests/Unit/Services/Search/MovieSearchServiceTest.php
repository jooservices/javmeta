<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Search;

use App\Models\Movie;
use App\Repositories\MovieRepository;
use App\Services\Dependencies\DependencyMonitor;
use App\Services\Embedding\EmbedderClient;
use App\Services\Search\ElasticsearchHttpClient;
use App\Services\Search\ElasticsearchIndexer;
use App\Services\Search\MovieSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\DependencyMonitorTestHelper;
use Tests\TestCase;

final class MovieSearchServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DependencyMonitorTestHelper::bind();
    }

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
            app(DependencyMonitor::class),
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
            app(DependencyMonitor::class),
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
            app(DependencyMonitor::class),
        ))
            ->semanticSearch(fake()->sentence(), 1);

        $this->assertSame([$movie->uuid], $result['items']->pluck('uuid')->all());
        $this->assertSame('relevance', $result['sort']);
    }

    public function test_disabled_search_uses_the_repository_for_keyword_and_semantic_queries(): void
    {
        $movie = Movie::factory()->create([
            'title_en' => 'Disabled Search Fallback',
        ]);
        $this->enableElasticsearch();
        config()->set('elasticsearch.enabled', false);
        Http::fake();

        $service = new MovieSearchService(
            new ElasticsearchIndexer(new EmbedderClient(), app(ElasticsearchHttpClient::class)),
            app(MovieRepository::class),
            app(DependencyMonitor::class),
        );

        $keyword = $service->search(['q' => 'Disabled Search'], 'relevance', null, 10);
        $semantic = $service->semanticSearch('Disabled Search', 10);

        $this->assertSame([$movie->uuid], $keyword['items']->pluck('uuid')->all());
        $this->assertSame([$movie->uuid], $semantic['items']->pluck('uuid')->all());
        Http::assertNothingSent();
    }

    public function test_keyword_search_falls_back_when_dependency_monitor_reports_elasticsearch_unavailable(): void
    {
        $movie = Movie::factory()->create(['title_en' => 'Elasticsearch Outage Fallback']);
        $this->enableElasticsearch();
        Http::fake();
        DependencyMonitorTestHelper::bind(['elasticsearch' => false]);

        $result = $this->makeService()->search(['q' => 'Elasticsearch Outage'], 'relevance', null, 10);

        $this->assertSame([$movie->uuid], $result['items']->pluck('uuid')->all());
        Http::assertNothingSent();
    }

    public function test_semantic_search_falls_back_when_dependency_monitor_reports_embedder_unavailable(): void
    {
        $movie = Movie::factory()->create(['title_en' => 'Embedder Outage Fallback']);
        $this->enableElasticsearch();
        Http::fake();
        DependencyMonitorTestHelper::bind(['embedder' => false]);

        $result = $this->makeService()->semanticSearch('Embedder Outage', 10);

        $this->assertSame([$movie->uuid], $result['items']->pluck('uuid')->all());
        Http::assertNothingSent();
    }

    private function makeService(): MovieSearchService
    {
        return new MovieSearchService(
            new ElasticsearchIndexer(new EmbedderClient(), app(ElasticsearchHttpClient::class)),
            app(MovieRepository::class),
            app(DependencyMonitor::class),
        );
    }

    private function enableElasticsearch(): void
    {
        $this->app->instance('env', 'production');
    }
}
