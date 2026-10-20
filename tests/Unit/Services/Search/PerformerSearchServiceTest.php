<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Search;

use App\Models\Performer;
use App\Repositories\PerformerRepository;
use App\Services\Embedding\EmbedderClient;
use App\Services\Search\ElasticsearchHttpClient;
use App\Services\Search\ElasticsearchIndexer;
use App\Services\Search\PerformerSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

        $service = new PerformerSearchService(
            new ElasticsearchIndexer(new EmbedderClient(), app(ElasticsearchHttpClient::class)),
            app(PerformerRepository::class),
        );

        $result = $service->semanticSearch('Mina Semantic', 10);

        $this->assertSame([$performer->uuid], $result['items']->pluck('uuid')->all());
        Http::assertNothingSent();
    }
}
