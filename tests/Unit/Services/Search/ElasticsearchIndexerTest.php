<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Search;

use App\Models\Movie;
use App\Models\Performer;
use App\Services\Search\ElasticsearchIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ElasticsearchIndexerTest extends TestCase
{
    use RefreshDatabase;

    public function test_movie_indexing_skips_elasticsearch_put_when_vector_is_required_but_unavailable(): void
    {
        $movie = Movie::factory()->create(['title_en' => fake()->sentence()]);

        Http::fake(static fn(ClientRequest $request) => str_ends_with($request->url(), '/v1/embeddings')
            ? Http::response([], 503)
            : Http::response(['result' => 'created']));

        $this->assertFalse(app(ElasticsearchIndexer::class)->indexMovie($movie, requireVector: true));

        Http::assertSent(static fn(ClientRequest $request): bool => str_ends_with($request->url(), '/v1/embeddings'));
        Http::assertNotSent(static fn(ClientRequest $request): bool => str_contains($request->url(), '/javmeta_movies/_doc/'));
    }

    public function test_search_switched_off_skips_indexing_without_any_request(): void
    {
        config()->set('elasticsearch.enabled', false);
        $movie = Movie::factory()->create(['title_en' => fake()->sentence()]);
        $performer = Performer::factory()->create();
        Http::fake();

        $this->assertFalse(app(ElasticsearchIndexer::class)->indexMovie($movie));
        $this->assertFalse(app(ElasticsearchIndexer::class)->indexPerformer($performer));

        Http::assertNothingSent();
    }

    public function test_embedder_switched_off_still_indexes_the_document_without_a_vector(): void
    {
        config()->set('elasticsearch.embedder_enabled', false);
        $movie = Movie::factory()->create(['title_en' => fake()->sentence()]);
        Http::fake(['*' => Http::response(['result' => 'created'])]);

        $this->assertTrue(app(ElasticsearchIndexer::class)->indexMovie($movie));

        Http::assertNotSent(static fn(ClientRequest $request): bool => str_ends_with($request->url(), '/v1/embeddings'));
        Http::assertSent(static fn(ClientRequest $request): bool => str_contains($request->url(), '/javmeta_movies/_doc/') && ! isset($request->data()['embedding_movie']));
    }

    public function test_movie_indexing_remains_fail_open_when_vector_is_unavailable(): void
    {
        $movie = Movie::factory()->create(['title_en' => fake()->sentence()]);

        Http::fake(static fn(ClientRequest $request) => str_ends_with($request->url(), '/v1/embeddings')
            ? Http::response([], 503)
            : Http::response(['result' => 'created']));

        $this->assertTrue(app(ElasticsearchIndexer::class)->indexMovie($movie));

        Http::assertSent(static function (ClientRequest $request) use ($movie): bool {
            if (! str_ends_with($request->url(), '/javmeta_movies/_doc/' . $movie->uuid)) {
                return false;
            }

            return ! array_key_exists('embedding_movie', $request->data());
        });
    }

    public function test_movie_indexing_returns_false_when_elasticsearch_put_fails(): void
    {
        $movie = Movie::factory()->create(['title_en' => fake()->sentence()]);

        Http::fake(static fn(ClientRequest $request) => str_ends_with($request->url(), '/v1/embeddings')
            ? Http::response(['data' => [['embedding' => array_fill(0, 384, 0.1)]]])
            : Http::response([], 503));

        $this->assertFalse(app(ElasticsearchIndexer::class)->indexMovie($movie));

        Http::assertSent(static function (ClientRequest $request) use ($movie): bool {
            return str_ends_with($request->url(), '/javmeta_movies/_doc/' . $movie->uuid)
                && array_key_exists('embedding_movie', $request->data());
        });
    }

    public function test_performer_indexing_skips_elasticsearch_put_when_vector_is_required_but_unavailable(): void
    {
        $performer = Performer::factory()->create(['name_romaji' => fake()->name()]);

        Http::fake(static fn(ClientRequest $request) => str_ends_with($request->url(), '/v1/embeddings')
            ? Http::response([], 503)
            : Http::response(['result' => 'created']));

        $this->assertFalse(app(ElasticsearchIndexer::class)->indexPerformer($performer, requireVector: true));

        Http::assertSent(static fn(ClientRequest $request): bool => str_ends_with($request->url(), '/v1/embeddings'));
        Http::assertNotSent(static fn(ClientRequest $request): bool => str_contains($request->url(), '/javmeta_performers/_doc/'));
    }

    public function test_performer_indexing_remains_fail_open_when_vector_is_unavailable(): void
    {
        $performer = Performer::factory()->create(['name_romaji' => fake()->name()]);

        Http::fake(static fn(ClientRequest $request) => str_ends_with($request->url(), '/v1/embeddings')
            ? Http::response([], 503)
            : Http::response(['result' => 'created']));

        $this->assertTrue(app(ElasticsearchIndexer::class)->indexPerformer($performer));

        Http::assertSent(static function (ClientRequest $request) use ($performer): bool {
            if (! str_ends_with($request->url(), '/javmeta_performers/_doc/' . $performer->uuid)) {
                return false;
            }

            return ! array_key_exists('embedding_performer', $request->data());
        });
    }

    public function test_index_performer_embeds_rich_profile_and_uses_performer_field(): void
    {
        $bio = fake()->paragraph();
        $location = fake()->city();
        $performer = Performer::factory()->create([
            'bio_text' => $bio,
            'location' => $location,
        ]);
        $alias = fake()->unique()->name();
        $performer->aliases()->create(['alias' => $alias, 'kind' => 'stage']);

        Http::fake(static fn(ClientRequest $request) => str_ends_with($request->url(), '/v1/embeddings')
            ? Http::response(['data' => [['embedding' => array_fill(0, 384, 0.1)]], 'model' => 'javmeta-passage'])
            : Http::response(['result' => 'created']));

        app(ElasticsearchIndexer::class)->indexPerformer($performer);

        Http::assertSent(static function (ClientRequest $request) use ($bio, $location, $alias): bool {
            if (! str_ends_with($request->url(), '/v1/embeddings')) {
                return false;
            }

            $input = $request->data()['input'][0] ?? '';

            return is_string($input)
                && str_contains($input, $bio)
                && str_contains($input, $location)
                && str_contains($input, $alias);
        });

        Http::assertSent(static function (ClientRequest $request) use ($performer): bool {
            if (! str_ends_with($request->url(), '/javmeta_performers/_doc/' . $performer->uuid)) {
                return false;
            }

            return array_key_exists('embedding_performer', $request->data());
        });
    }

    public function test_semantic_performer_search_queries_the_performer_vector_field(): void
    {
        $uuid = fake()->uuid();

        Http::fake(static function (ClientRequest $request) use ($uuid) {
            if (str_ends_with($request->url(), '/v1/embeddings')) {
                return Http::response(['data' => [['embedding' => array_fill(0, 384, 0.2)]], 'model' => 'javmeta-query']);
            }

            return Http::response(['hits' => ['hits' => [['_source' => ['uuid' => $uuid]]]]]);
        });

        $uuids = app(ElasticsearchIndexer::class)->semanticPerformerUuids(fake()->sentence(), 5);

        $this->assertSame([$uuid], $uuids);
        Http::assertSent(static function (ClientRequest $request): bool {
            if (! str_ends_with($request->url(), '/javmeta_performers/_search')) {
                return false;
            }

            return ($request->data()['knn']['field'] ?? null) === 'embedding_performer';
        });
    }

    public function test_semantic_movie_search_queries_the_movie_vector_field(): void
    {
        $uuid = fake()->uuid();

        Http::fake(static function (ClientRequest $request) use ($uuid) {
            if (str_ends_with($request->url(), '/v1/embeddings')) {
                return Http::response(['data' => [['embedding' => array_fill(0, 384, 0.3)]], 'model' => 'javmeta-query']);
            }

            return Http::response(['hits' => ['hits' => [['_source' => ['uuid' => $uuid]]]]]);
        });

        $uuids = app(ElasticsearchIndexer::class)->semanticMovieUuids(fake()->sentence(), 5);

        $this->assertSame([$uuid], $uuids);
        Http::assertSent(static function (ClientRequest $request): bool {
            if (! str_ends_with($request->url(), '/javmeta_movies/_search')) {
                return false;
            }

            return ($request->data()['knn']['field'] ?? null) === 'embedding_movie';
        });
    }
}
