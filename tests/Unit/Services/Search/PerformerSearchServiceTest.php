<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Search;

use App\Models\Performer;
use App\Repositories\PerformerRepository;
use App\Services\Dependencies\DependencyMonitor;
use App\Services\Embedding\EmbedderClient;
use App\Services\Search\ElasticsearchHttpClient;
use App\Services\Search\ElasticsearchIndexer;
use App\Services\Search\PerformerSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\DependencyMonitorTestHelper;
use Tests\TestCase;

final class PerformerSearchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_search_uses_repository_for_semantic_performer_queries(): void
    {
        $performer = Performer::factory()->create([
            'name_romaji' => 'Mina Semantic Fallback',
        ]);
        $this->app->instance('env', 'production');
        config()->set('elasticsearch.enabled', false);
        Http::fake();
        DependencyMonitorTestHelper::bind();

        $service = new PerformerSearchService(
            new ElasticsearchIndexer(new EmbedderClient(), app(ElasticsearchHttpClient::class)),
            app(PerformerRepository::class),
            app(DependencyMonitor::class),
        );

        $result = $service->semanticSearch('Mina Semantic', 10);

        $this->assertSame([$performer->uuid], $result['items']->pluck('uuid')->all());
        Http::assertNothingSent();
    }

    public function test_semantic_performer_search_falls_back_when_dependency_monitor_reports_elasticsearch_unavailable(): void
    {
        $performer = Performer::factory()->create(['name_romaji' => 'Mina Elasticsearch Fallback']);
        $this->app->instance('env', 'production');
        Http::fake();
        DependencyMonitorTestHelper::bind(['elasticsearch' => false]);

        $service = new PerformerSearchService(
            new ElasticsearchIndexer(new EmbedderClient(), app(ElasticsearchHttpClient::class)),
            app(PerformerRepository::class),
            app(DependencyMonitor::class),
        );

        $result = $service->semanticSearch('Mina Elasticsearch', 10);

        $this->assertSame([$performer->uuid], $result['items']->pluck('uuid')->all());
        Http::assertNothingSent();
    }

    public function test_semantic_performer_search_falls_back_when_dependency_monitor_reports_embedder_unavailable(): void
    {
        $performer = Performer::factory()->create(['name_romaji' => 'Mina Embedder Fallback']);
        $this->app->instance('env', 'production');
        Http::fake();
        DependencyMonitorTestHelper::bind(['embedder' => false]);

        $service = new PerformerSearchService(
            new ElasticsearchIndexer(new EmbedderClient(), app(ElasticsearchHttpClient::class)),
            app(PerformerRepository::class),
            app(DependencyMonitor::class),
        );

        $result = $service->semanticSearch('Mina Embedder', 10);

        $this->assertSame([$performer->uuid], $result['items']->pluck('uuid')->all());
        Http::assertNothingSent();
    }
}
