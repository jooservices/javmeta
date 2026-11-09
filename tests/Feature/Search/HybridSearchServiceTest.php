<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\Movie;
use App\Models\Performer;
use App\Services\Search\HybridSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use App\Services\Search\SearchBudget;
use Tests\Support\DependencyMonitorTestHelper;
use Tests\TestCase;

final class HybridSearchServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('env', 'production');
        DependencyMonitorTestHelper::bind();
        config(['elasticsearch.enabled' => true, 'elasticsearch.embedder_enabled' => true, 'elasticsearch.movies_index' => 'movies', 'elasticsearch.performers_index' => 'performers', 'javmeta_search.budget_ms' => 1500]);
    }

    public function test_full_keyword_performer_page_skips_the_performer_vector_request(): void
    {
        Performer::factory()->count(4)->create(['name_romaji' => 'Mina']);
        $movie = Movie::factory()->create(['title_en' => 'Semantic result title']);
        Http::fake(fn(Request $request) => Http::response(str_contains($request->url(), 'embeddings')
            ? ['data' => [['embedding' => [0.1, 0.2]]]]
            : ['hits' => ['hits' => [['_source' => ['uuid' => $movie->uuid]]]]], 200));
        $result = app(HybridSearchService::class)->search('Mina', 4, false);
        $this->assertCount(4, $result['performers']);
        $this->assertSame([$movie->uuid], $result['movies']->pluck('uuid')->all());
        Http::assertNotSent(static fn(Request $request): bool => str_contains($request->url(), '/performers/_search'));
        $this->assertCount(1, Http::recorded(static fn(Request $request): bool => str_contains($request->url(), 'embeddings')));
    }

    public function test_sparse_keywords_share_one_embedding_and_hydrate_both_vector_results(): void
    {
        $movie = Movie::factory()->create(['title_en' => 'Semantic result title']);
        $performer = Performer::factory()->create(['name_romaji' => 'Other Name']);
        Http::fake(function (Request $request) use ($movie, $performer) {
            if (str_contains($request->url(), 'embeddings')) {
                return Http::response(['data' => [['embedding' => [0.1, 0.2]]]], 200);
            }
            $uuid = str_contains($request->url(), '/performers/') ? $performer->uuid : $movie->uuid;

            return Http::response(['hits' => ['hits' => [['_source' => ['uuid' => $uuid]]]]], 200);
        });
        $result = app(HybridSearchService::class)->search('unmatched', 4, false);
        $this->assertSame([$performer->uuid], $result['performers']->pluck('uuid')->all());
        $this->assertSame([$movie->uuid], $result['movies']->pluck('uuid')->all());
        $this->assertCount(1, Http::recorded(static fn(Request $r): bool => str_contains($r->url(), 'embeddings')));
        Http::assertSent(static fn(Request $r): bool => str_contains($r->url(), '/performers/_search') && ($r->data()['knn']['query_vector'] ?? null) === [0.1, 0.2]);
        Http::assertSent(static fn(Request $r): bool => str_contains($r->url(), '/movies/_search') && ($r->data()['knn']['query_vector'] ?? null) === [0.1, 0.2]);
    }

    public function test_one_failed_pool_branch_keeps_the_successful_branch_and_keyword_results(): void
    {
        $performer = Performer::factory()->create(['name_romaji' => 'Mina']);
        $movie = Movie::factory()->create(['title_en' => 'Semantic result title']);
        Http::fake(function (Request $request) use ($movie) {
            if (str_contains($request->url(), 'embeddings')) {
                return Http::response(['data' => [['embedding' => [0.1, 0.2]]]], 200);
            }
            if (str_contains($request->url(), '/performers/')) {
                return Http::response([], 503);
            }

            return Http::response(['hits' => ['hits' => [['_source' => ['uuid' => $movie->uuid]]]]], 200);
        });
        $result = app(HybridSearchService::class)->search('Mina', 4, false);
        $this->assertSame([$performer->uuid], $result['performers']->pluck('uuid')->all());
        $this->assertSame([$movie->uuid], $result['movies']->pluck('uuid')->all());
    }

    public function test_keyword_results_are_kept_when_the_embedder_times_out(): void
    {
        $movie = Movie::factory()->create(['title_en' => 'Keyword fallback survives']);
        Http::fake(static function (Request $request) use ($movie) {
            if (str_contains($request->url(), 'embeddings')) {
                throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
            }

            return Http::response(['hits' => ['hits' => [['_source' => ['uuid' => $movie->uuid]]]]], 200);
        });

        $result = app(HybridSearchService::class)->search('Keyword fallback survives', 4, false);

        self::assertContains($movie->uuid, $result['movies']->pluck('uuid')->all());
        self::assertContains('semantic_timeout', $result['interpretation']['degraded'] ?? []);
    }

    public function test_semantic_results_survive_a_keyword_query_that_uses_the_deadline(): void
    {
        config(['javmeta_search.budget_ms' => 8000]);
        $movie = Movie::factory()->create(['title_en' => 'Unrelated keyword title']);
        $now = 1_000_000_000;
        $budget = new SearchBudget(static function () use (&$now): int {
            return $now;
        });
        $this->app->instance(SearchBudget::class, $budget);
        $advanced = false;
        DB::listen(static function (QueryExecuted $event) use (&$now, &$advanced): void {
            if (! $advanced && str_contains(strtolower($event->sql), 'like')) {
                $now += 6_000_000_000;
                $advanced = true;
            }
        });
        Http::fake(static function (Request $request) use ($movie) {
            if (str_contains($request->url(), 'embeddings')) {
                return Http::response(['data' => [['embedding' => [0.1, 0.2]]]], 200);
            }

            return Http::response(['hits' => ['hits' => [['_source' => ['uuid' => $movie->uuid]]]]], 200);
        });

        $result = app(HybridSearchService::class)->search('missing keyword', 4, false);

        self::assertSame([$movie->uuid], $result['movies']->pluck('uuid')->all());
        self::assertContains('keyword_timeout', $result['interpretation']['degraded'] ?? []);
    }
}
