<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Search;

use App\Models\Movie;
use App\Models\Performer;
use App\Models\PerformerSource;
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
        $movieIndex = (string) config('elasticsearch.movies_index');
        Http::assertNotSent(static fn(ClientRequest $request): bool => str_contains($request->url(), "/{$movieIndex}/_doc/"));
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

    public function test_movie_and_performer_index_and_search_calls_use_basic_authentication(): void
    {
        $host = 'http://elasticsearch.test';
        config()->set([
            'elasticsearch.host' => $host,
            'elasticsearch.username' => 'javmeta-test-user',
            'elasticsearch.password' => 'javmeta-test-password',
            'elasticsearch.api_key' => '',
            'elasticsearch.embedder_url' => 'http://embedder.test',
            'elasticsearch.embedder_enabled' => true,
        ]);
        $movie = Movie::factory()->create(['title_en' => fake()->sentence()]);
        $performer = Performer::factory()->create(['name_romaji' => fake()->name()]);
        Http::preventStrayRequests();
        Http::fake(static function (ClientRequest $request) {
            if (str_ends_with($request->url(), '/v1/embeddings')) {
                return Http::response(['data' => [['embedding' => array_fill(0, 384, 0.2)]]]);
            }

            if (str_contains($request->url(), '/_search')) {
                return Http::response(['hits' => ['hits' => []]]);
            }

            return Http::response(['result' => 'created']);
        });

        $indexer = app(ElasticsearchIndexer::class);
        self::assertTrue($indexer->indexMovie($movie));
        self::assertTrue($indexer->indexPerformer($performer));
        $indexer->searchMovieUuids(fake()->sentence());
        $indexer->semanticMovieUuids(fake()->sentence());
        $indexer->semanticPerformerUuids(fake()->sentence());

        $elasticsearchRequests = Http::recorded()
            ->map(static fn(array $recorded): ClientRequest => $recorded[0])
            ->filter(static fn(ClientRequest $request): bool => str_starts_with($request->url(), $host));
        self::assertCount(5, $elasticsearchRequests);
        foreach ($elasticsearchRequests as $request) {
            self::assertTrue($request->hasHeader(
                'Authorization',
                'Basic ' . base64_encode('javmeta-test-user:javmeta-test-password'),
            ));
        }
    }

    public function test_embedder_switched_off_still_indexes_the_document_without_a_vector(): void
    {
        config()->set('elasticsearch.embedder_enabled', false);
        $movie = Movie::factory()->create(['title_en' => fake()->sentence()]);
        $movieIndex = (string) config('elasticsearch.movies_index');
        Http::fake(['*' => Http::response(['result' => 'created'])]);

        $this->assertTrue(app(ElasticsearchIndexer::class)->indexMovie($movie));

        Http::assertNotSent(static fn(ClientRequest $request): bool => str_ends_with($request->url(), '/v1/embeddings'));
        Http::assertSent(static fn(ClientRequest $request): bool => str_contains($request->url(), "/{$movieIndex}/_doc/") && ! isset($request->data()['embedding_movie']));
    }

    public function test_movie_indexing_remains_fail_open_when_vector_is_unavailable(): void
    {
        $movie = Movie::factory()->create(['title_en' => fake()->sentence()]);
        $movieIndex = (string) config('elasticsearch.movies_index');

        Http::fake(static fn(ClientRequest $request) => str_ends_with($request->url(), '/v1/embeddings')
            ? Http::response([], 503)
            : Http::response(['result' => 'created']));

        $this->assertTrue(app(ElasticsearchIndexer::class)->indexMovie($movie));

        Http::assertSent(static function (ClientRequest $request) use ($movie, $movieIndex): bool {
            if (! str_ends_with($request->url(), "/{$movieIndex}/_doc/" . $movie->uuid)) {
                return false;
            }

            return ! array_key_exists('embedding_movie', $request->data());
        });
    }

    public function test_movie_indexing_returns_false_when_elasticsearch_put_fails(): void
    {
        $movie = Movie::factory()->create(['title_en' => fake()->sentence()]);
        $movieIndex = (string) config('elasticsearch.movies_index');

        Http::fake(static fn(ClientRequest $request) => str_ends_with($request->url(), '/v1/embeddings')
            ? Http::response(['data' => [['embedding' => array_fill(0, 384, 0.1)]]])
            : Http::response([], 503));

        $this->assertFalse(app(ElasticsearchIndexer::class)->indexMovie($movie));

        Http::assertSent(static function (ClientRequest $request) use ($movie, $movieIndex): bool {
            return str_ends_with($request->url(), "/{$movieIndex}/_doc/" . $movie->uuid)
                && array_key_exists('embedding_movie', $request->data());
        });
    }

    public function test_performer_indexing_skips_elasticsearch_put_when_vector_is_required_but_unavailable(): void
    {
        $performer = Performer::factory()->create(['name_romaji' => fake()->name()]);
        $performerIndex = (string) config('elasticsearch.performers_index');

        Http::fake(static fn(ClientRequest $request) => str_ends_with($request->url(), '/v1/embeddings')
            ? Http::response([], 503)
            : Http::response(['result' => 'created']));

        $this->assertFalse(app(ElasticsearchIndexer::class)->indexPerformer($performer, requireVector: true));

        Http::assertSent(static fn(ClientRequest $request): bool => str_ends_with($request->url(), '/v1/embeddings'));
        Http::assertNotSent(static fn(ClientRequest $request): bool => str_contains($request->url(), "/{$performerIndex}/_doc/"));
    }

    public function test_performer_indexing_remains_fail_open_when_vector_is_unavailable(): void
    {
        $performer = Performer::factory()->create(['name_romaji' => fake()->name()]);
        $performerIndex = (string) config('elasticsearch.performers_index');

        Http::fake(static fn(ClientRequest $request) => str_ends_with($request->url(), '/v1/embeddings')
            ? Http::response([], 503)
            : Http::response(['result' => 'created']));

        $this->assertTrue(app(ElasticsearchIndexer::class)->indexPerformer($performer));

        Http::assertSent(static function (ClientRequest $request) use ($performer, $performerIndex): bool {
            if (! str_ends_with($request->url(), "/{$performerIndex}/_doc/" . $performer->uuid)) {
                return false;
            }

            return ! array_key_exists('embedding_performer', $request->data());
        });
    }

    public function test_performer_index_contains_canonical_source_records(): void
    {
        config()->set('elasticsearch.embedder_enabled', false);
        $performer = Performer::factory()->create(['name_romaji' => 'Aoi Sakura']);
        PerformerSource::query()->create([
            'performer_id' => $performer->id,
            'source_performer_id' => $performer->id,
            'site_slug' => 'source-a',
            'mongo_id' => 'local:' . $performer->id,
            'external_id' => 'person-a',
            'source_url' => 'https://source-a.test/person-a',
            'name_romaji' => 'Sakura Aoi',
            'birth_date' => '1990-01-01',
            'crawled_at' => now(),
        ]);
        Http::fake(['*' => Http::response(['result' => 'created'])]);

        $this->assertTrue(app(ElasticsearchIndexer::class)->indexPerformer($performer));

        Http::assertSent(static function (ClientRequest $request) use ($performer): bool {
            if (! str_ends_with($request->url(), '/javmeta_performers/_doc/' . $performer->uuid)) {
                return false;
            }

            return count($request->data()['sources'] ?? []) === 1
                && $request->data()['sources'][0]['source_slug'] === 'source-a'
                && in_array('Sakura Aoi', $request->data()['performer_names'], true);
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
        $performerIndex = (string) config('elasticsearch.performers_index');
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

        Http::assertSent(static function (ClientRequest $request) use ($performer, $performerIndex): bool {
            if (! str_ends_with($request->url(), "/{$performerIndex}/_doc/" . $performer->uuid)) {
                return false;
            }

            return array_key_exists('embedding_performer', $request->data());
        });
    }

    public function test_semantic_performer_search_queries_the_performer_vector_field(): void
    {
        $uuid = fake()->uuid();
        $performerIndex = (string) config('elasticsearch.performers_index');

        Http::fake(static function (ClientRequest $request) use ($uuid) {
            if (str_ends_with($request->url(), '/v1/embeddings')) {
                return Http::response(['data' => [['embedding' => array_fill(0, 384, 0.2)]], 'model' => 'javmeta-query']);
            }

            return Http::response(['hits' => ['hits' => [['_source' => ['uuid' => $uuid]]]]]);
        });

        $uuids = app(ElasticsearchIndexer::class)->semanticPerformerUuids(fake()->sentence(), 5);

        $this->assertSame([$uuid], $uuids);
        Http::assertSent(static function (ClientRequest $request) use ($performerIndex): bool {
            if (! str_ends_with($request->url(), "/{$performerIndex}/_search")) {
                return false;
            }

            return ($request->data()['knn']['field'] ?? null) === 'embedding_performer';
        });
    }

    public function test_semantic_movie_search_queries_the_movie_vector_field(): void
    {
        $uuid = fake()->uuid();
        $movieIndex = (string) config('elasticsearch.movies_index');

        Http::fake(static function (ClientRequest $request) use ($uuid) {
            if (str_ends_with($request->url(), '/v1/embeddings')) {
                return Http::response(['data' => [['embedding' => array_fill(0, 384, 0.3)]], 'model' => 'javmeta-query']);
            }

            return Http::response(['hits' => ['hits' => [['_source' => ['uuid' => $uuid]]]]]);
        });

        $uuids = app(ElasticsearchIndexer::class)->semanticMovieUuids(fake()->sentence(), 5);

        $this->assertSame([$uuid], $uuids);
        Http::assertSent(static function (ClientRequest $request) use ($movieIndex): bool {
            if (! str_ends_with($request->url(), "/{$movieIndex}/_search")) {
                return false;
            }

            return ($request->data()['knn']['field'] ?? null) === 'embedding_movie';
        });
    }
}
