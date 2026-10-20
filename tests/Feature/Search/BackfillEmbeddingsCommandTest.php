<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\Movie;
use App\Models\Performer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class BackfillEmbeddingsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_reindexes_missing_movie_and_performer_vectors(): void
    {
        $movie = Movie::factory()->create(['title_en' => fake()->sentence()]);
        $performer = Performer::factory()->create(['name_romaji' => fake()->name()]);
        $movieIndex = (string) config('elasticsearch.movies_index');
        $performerIndex = (string) config('elasticsearch.performers_index');

        config()->set([
            'elasticsearch.host' => 'http://elasticsearch.test',
            'elasticsearch.username' => 'javmeta-test-user',
            'elasticsearch.password' => 'javmeta-test-password',
            'elasticsearch.api_key' => '',
            'elasticsearch.embedder_url' => 'http://embedder.test',
        ]);

        Http::fake(static function (ClientRequest $request) use ($movie, $performer, $movieIndex, $performerIndex) {
            if (str_ends_with($request->url(), '/healthz')) {
                return Http::response(['status' => 'ok']);
            }

            if (str_ends_with($request->url(), '/v1/embeddings')) {
                return Http::response(['data' => [['embedding' => array_fill(0, 384, 0.25)]]]);
            }

            if (str_ends_with($request->url(), "/{$movieIndex}/_search")) {
                return Http::response(['hits' => ['hits' => [[
                    '_id' => $movie->uuid,
                    '_source' => ['uuid' => $movie->uuid],
                    'sort' => [$movie->uuid],
                ]]]]);
            }

            if (str_ends_with($request->url(), "/{$performerIndex}/_search")) {
                return Http::response(['hits' => ['hits' => [[
                    '_id' => $performer->uuid,
                    '_source' => ['uuid' => $performer->uuid],
                    'sort' => [$performer->uuid],
                ]]]]);
            }

            return Http::response(['result' => 'created']);
        });

        $this->artisan('search:backfill-embeddings', ['--entity' => 'all'])
            ->assertSuccessful()
            ->expectsOutputToContain('Found: 2')
            ->expectsOutputToContain('Re-indexed: 2')
            ->expectsOutputToContain('Still missing: 0')
            ->expectsOutputToContain('Skipped: 0');

        Http::assertSent(static function (ClientRequest $request) use ($movie, $movieIndex): bool {
            return str_ends_with($request->url(), "/{$movieIndex}/_doc/{$movie->uuid}")
                && array_key_exists('embedding_movie', $request->data());
        });
        Http::assertSent(static function (ClientRequest $request) use ($performer, $performerIndex): bool {
            return str_ends_with($request->url(), "/{$performerIndex}/_doc/{$performer->uuid}")
                && array_key_exists('embedding_performer', $request->data());
        });
        Http::assertSent(static fn(ClientRequest $request): bool => str_ends_with($request->url(), '/healthz'));
        $elasticsearchRequests = Http::recorded()
            ->map(static fn(array $recorded): ClientRequest => $recorded[0])
            ->filter(static fn(ClientRequest $request): bool => str_starts_with($request->url(), 'http://elasticsearch.test'));
        self::assertCount(4, $elasticsearchRequests);
        foreach ($elasticsearchRequests as $request) {
            self::assertTrue($request->hasHeader(
                'Authorization',
                'Basic ' . base64_encode('javmeta-test-user:javmeta-test-password'),
            ));
        }
    }

    public function test_command_stops_before_indexing_when_embedder_health_is_down(): void
    {
        $movie = Movie::factory()->create();
        $missingUuid = fake()->uuid();
        $uuids = [$movie->uuid, $missingUuid];
        sort($uuids);
        $movieIndex = (string) config('elasticsearch.movies_index');
        config()->set([
            'elasticsearch.host' => 'http://elasticsearch.test',
            'elasticsearch.embedder_url' => 'http://embedder.test',
        ]);

        Http::fake(static function (ClientRequest $request) use ($uuids, $movieIndex) {
            if (str_ends_with($request->url(), '/healthz')) {
                return Http::response([], 503);
            }

            if (str_ends_with($request->url(), "/{$movieIndex}/_search")) {
                return Http::response(['hits' => ['hits' => array_map(
                    static fn(string $uuid): array => ['_id' => $uuid, '_source' => ['uuid' => $uuid], 'sort' => [$uuid]],
                    $uuids,
                )]]);
            }

            return Http::response(['result' => 'created']);
        });

        $this->artisan('search:backfill-embeddings', ['--entity' => 'movies'])
            ->assertFailed()
            ->expectsOutputToContain('Embedder health check failed')
            ->expectsOutputToContain('Found: 2')
            ->expectsOutputToContain('Still missing: 1')
            ->expectsOutputToContain('Skipped: 1');

        Http::assertSent(static fn(ClientRequest $request): bool => str_ends_with($request->url(), '/healthz'));
        Http::assertNotSent(static fn(ClientRequest $request): bool => str_ends_with($request->url(), '/v1/embeddings'));
        Http::assertNotSent(static fn(ClientRequest $request): bool => str_contains($request->url(), "/{$movieIndex}/_doc/"));
    }

    public function test_dry_run_counts_missing_documents_without_embedding_or_indexing(): void
    {
        $movie = Movie::factory()->create();
        $movieIndex = (string) config('elasticsearch.movies_index');
        config()->set([
            'elasticsearch.host' => 'http://elasticsearch.test',
            'elasticsearch.embedder_url' => 'http://embedder.test',
        ]);

        Http::fake(static function (ClientRequest $request) use ($movie, $movieIndex) {
            if (str_ends_with($request->url(), '/healthz')) {
                return Http::response(['status' => 'ok']);
            }

            if (str_ends_with($request->url(), "/{$movieIndex}/_search")) {
                return Http::response(['hits' => ['hits' => [[
                    '_id' => $movie->uuid,
                    '_source' => ['uuid' => $movie->uuid],
                    'sort' => [$movie->uuid],
                ]]]]);
            }

            return Http::response(['result' => 'created']);
        });

        $this->artisan('search:backfill-embeddings', ['--entity' => 'movies', '--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Found: 1')
            ->expectsOutputToContain('Re-indexed: 0')
            ->expectsOutputToContain('Still missing: 1');

        Http::assertNotSent(static fn(ClientRequest $request): bool => str_ends_with($request->url(), '/v1/embeddings'));
        Http::assertNotSent(static fn(ClientRequest $request): bool => str_ends_with($request->url(), '/healthz'));
        Http::assertNotSent(static fn(ClientRequest $request): bool => str_contains($request->url(), "/{$movieIndex}/_doc/"));
    }

    public function test_command_uses_search_after_to_page_beyond_one_batch(): void
    {
        $movies = Movie::factory()->count(3)->create(['title_en' => fake()->sentence()]);
        $uuids = $movies->pluck('uuid')->sort()->values()->all();
        $movieIndex = (string) config('elasticsearch.movies_index');
        config()->set([
            'elasticsearch.host' => 'http://elasticsearch.test',
            'elasticsearch.embedder_url' => 'http://embedder.test',
        ]);
        $searchRequests = [];
        $embeddingNumber = 0;
        $healthChecks = 0;

        Http::fake(function (ClientRequest $request) use ($uuids, $movieIndex, &$searchRequests, &$embeddingNumber, &$healthChecks) {
            if (str_ends_with($request->url(), '/healthz')) {
                $healthChecks++;

                return Http::response(['status' => 'ok']);
            }

            if (str_ends_with($request->url(), '/v1/embeddings')) {
                $embeddingNumber++;

                return Http::response(['data' => [['embedding' => array_fill(0, 384, 0.25)]]]);
            }

            if (str_ends_with($request->url(), "/{$movieIndex}/_search")) {
                $searchRequests[] = $request->data();
                $hits = $request->data()['search_after'] ?? null;
                $pageUuids = $hits === null ? array_slice($uuids, 0, 2) : array_slice($uuids, 2, 1);

                return Http::response(['hits' => ['hits' => array_map(
                    static fn(string $uuid): array => ['_id' => $uuid, '_source' => ['uuid' => $uuid], 'sort' => [$uuid]],
                    $pageUuids,
                )]]);
            }

            return Http::response(['result' => 'created']);
        });

        $this->artisan('search:backfill-embeddings', ['--entity' => 'movies', '--batch' => 2])
            ->assertSuccessful()
            ->expectsOutputToContain('Found: 3')
            ->expectsOutputToContain('Re-indexed: 3');

        $this->assertCount(2, $searchRequests);
        $this->assertSame(2, $searchRequests[0]['size']);
        $this->assertSame(2, $healthChecks);
        $this->assertSame([$uuids[1]], $searchRequests[1]['search_after']);
        $this->assertSame([['exists' => ['field' => 'embedding_movie']]], $searchRequests[0]['query']['bool']['must_not']);
        $this->assertSame([['uuid' => ['order' => 'asc']]], $searchRequests[0]['sort']);
        $this->assertArrayNotHasKey('from', $searchRequests[0]);
        $this->assertArrayNotHasKey('from', $searchRequests[1]);
    }

    public function test_command_counts_documents_without_models_as_skipped(): void
    {
        $uuid = fake()->uuid();
        $movieIndex = (string) config('elasticsearch.movies_index');
        config()->set([
            'elasticsearch.host' => 'http://elasticsearch.test',
            'elasticsearch.embedder_url' => 'http://embedder.test',
        ]);

        Http::fake(static function (ClientRequest $request) use ($uuid, $movieIndex) {
            if (str_ends_with($request->url(), '/healthz')) {
                return Http::response(['status' => 'ok']);
            }

            if (str_ends_with($request->url(), "/{$movieIndex}/_search")) {
                return Http::response(['hits' => ['hits' => [[
                    '_id' => $uuid,
                    '_source' => ['uuid' => $uuid],
                    'sort' => [$uuid],
                ]]]]);
            }

            return Http::response(['result' => 'created']);
        });

        $this->artisan('search:backfill-embeddings', ['--entity' => 'movies'])
            ->assertSuccessful()
            ->expectsOutputToContain('Found: 1')
            ->expectsOutputToContain('Skipped: 1');

        Http::assertNotSent(static fn(ClientRequest $request): bool => str_ends_with($request->url(), '/v1/embeddings'));
        Http::assertNotSent(static fn(ClientRequest $request): bool => str_contains($request->url(), "/{$movieIndex}/_doc/"));
    }

    public function test_command_counts_a_failed_elasticsearch_put_as_still_missing(): void
    {
        $movie = Movie::factory()->create(['title_en' => fake()->sentence()]);
        $movieIndex = (string) config('elasticsearch.movies_index');
        config()->set([
            'elasticsearch.host' => 'http://elasticsearch.test',
            'elasticsearch.embedder_url' => 'http://embedder.test',
        ]);

        Http::fake(static function (ClientRequest $request) use ($movie, $movieIndex) {
            if (str_ends_with($request->url(), '/healthz')) {
                return Http::response(['status' => 'ok']);
            }

            if (str_ends_with($request->url(), '/v1/embeddings')) {
                return Http::response(['data' => [['embedding' => array_fill(0, 384, 0.25)]]]);
            }

            if (str_ends_with($request->url(), "/{$movieIndex}/_search")) {
                return Http::response(['hits' => ['hits' => [[
                    '_id' => $movie->uuid,
                    '_source' => ['uuid' => $movie->uuid],
                    'sort' => [$movie->uuid],
                ]]]]);
            }

            return Http::response([], 503);
        });

        $this->artisan('search:backfill-embeddings', ['--entity' => 'movies'])
            ->assertSuccessful()
            ->expectsOutputToContain('Found: 1')
            ->expectsOutputToContain('Re-indexed: 0')
            ->expectsOutputToContain('Still missing: 1');

        Http::assertSent(static function (ClientRequest $request) use ($movie, $movieIndex): bool {
            return str_ends_with($request->url(), "/{$movieIndex}/_doc/{$movie->uuid}")
                && array_key_exists('embedding_movie', $request->data());
        });
    }

    public function test_command_counts_strict_embedding_failures_as_still_missing(): void
    {
        $movies = Movie::factory()->count(2)->create(['title_en' => fake()->sentence()]);
        $uuids = $movies->pluck('uuid')->sort()->values()->all();
        $movieIndex = (string) config('elasticsearch.movies_index');
        config()->set([
            'elasticsearch.host' => 'http://elasticsearch.test',
            'elasticsearch.embedder_url' => 'http://embedder.test',
            'openobserve.enabled' => false,
        ]);
        $embeddingNumber = 0;

        Http::fake(function (ClientRequest $request) use ($uuids, $movieIndex, &$embeddingNumber) {
            if (str_ends_with($request->url(), '/healthz')) {
                return Http::response(['status' => 'ok']);
            }

            if (str_ends_with($request->url(), '/v1/embeddings')) {
                $embeddingNumber++;

                return $embeddingNumber === 1
                    ? Http::response(['data' => [['embedding' => array_fill(0, 384, 0.25)]]])
                    : Http::response([], 503);
            }

            if (str_ends_with($request->url(), "/{$movieIndex}/_search")) {
                if (isset($request->data()['search_after'])) {
                    return Http::response(['hits' => ['hits' => []]]);
                }

                return Http::response(['hits' => ['hits' => array_map(
                    static fn(string $uuid): array => ['_id' => $uuid, '_source' => ['uuid' => $uuid], 'sort' => [$uuid]],
                    $uuids,
                )]]);
            }

            return Http::response(['result' => 'created']);
        });

        $this->artisan('search:backfill-embeddings', ['--entity' => 'movies', '--batch' => 2])
            ->assertSuccessful()
            ->expectsOutputToContain('Found: 2')
            ->expectsOutputToContain('Re-indexed: 1')
            ->expectsOutputToContain('Still missing: 1');

        Http::assertSentCount(6);
        Http::assertSent(static function (ClientRequest $request) use ($uuids, $movieIndex): bool {
            return str_ends_with($request->url(), "/{$movieIndex}/_doc/{$uuids[0]}");
        });
        Http::assertNotSent(static function (ClientRequest $request) use ($uuids, $movieIndex): bool {
            return str_ends_with($request->url(), "/{$movieIndex}/_doc/{$uuids[1]}");
        });
    }
}
