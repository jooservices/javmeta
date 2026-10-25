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

    public function test_command_finds_never_indexed_and_vectorless_rows_and_leaves_complete_rows_alone(): void
    {
        $neverIndexed = Movie::factory()->create(['title_en' => fake()->sentence()]);
        $withoutVector = Movie::factory()->create(['title_en' => fake()->sentence()]);
        $complete = Movie::factory()->create(['title_en' => fake()->sentence()]);
        $performer = Performer::factory()->create(['name_romaji' => fake()->name()]);
        $this->configureSearchServices();
        config()->set([
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.email' => '',
            'openobserve.password' => '',
            'openobserve.timeout' => 1,
        ]);

        Http::fake(function (ClientRequest $request) use ($neverIndexed, $withoutVector, $complete, $performer) {
            if (str_ends_with($request->url(), '/healthz')) {
                return Http::response(['status' => 'ok']);
            }

            if (str_ends_with($request->url(), '/v1/embeddings')) {
                return Http::response(['data' => [['embedding' => array_fill(0, 384, 0.25)]]]);
            }

            if (str_ends_with($request->url(), '/' . config('elasticsearch.performers_index') . '/_search')) {
                return Http::response(['error' => ['type' => 'index_not_found_exception']], 404);
            }

            if (str_ends_with($request->url(), '/_search')) {
                return Http::response($this->vectorSearchResponse($request->data(), [
                    $complete->uuid => 'complete',
                ]));
            }

            return Http::response(['code' => 200]);
        });

        $this->artisan('search:backfill-embeddings', ['--entity' => 'all'])
            ->assertSuccessful()
            ->expectsOutputToContain('Scanned: 4')
            ->expectsOutputToContain('Missing: 3')
            ->expectsOutputToContain('Fixed: 3')
            ->expectsOutputToContain('Complete: 1')
            ->expectsOutputToContain('Still missing: 0');

        Http::assertSent(static fn(ClientRequest $request): bool => str_ends_with($request->url(), '/_search'));
        Http::assertSent(static fn(ClientRequest $request): bool => str_ends_with(
            $request->url(),
            '/' . config('elasticsearch.movies_index') . '/_doc/' . $neverIndexed->uuid,
        ));
        Http::assertSent(static fn(ClientRequest $request): bool => str_ends_with(
            $request->url(),
            '/' . config('elasticsearch.movies_index') . '/_doc/' . $withoutVector->uuid,
        ));
        Http::assertSent(static fn(ClientRequest $request): bool => str_ends_with(
            $request->url(),
            '/' . config('elasticsearch.performers_index') . '/_doc/' . $performer->uuid,
        ));
        Http::assertNotSent(static fn(ClientRequest $request): bool => str_ends_with(
            $request->url(),
            '/' . config('elasticsearch.movies_index') . '/_doc/' . $complete->uuid,
        ));

        $metrics = $this->backfillMetricValues();
        $this->assertSame([
            'javmeta_search_embedding_backfill_missing:movies' => 2.0,
            'javmeta_search_embedding_backfill_fixed:movies' => 2.0,
            'javmeta_search_embedding_backfill_missing:performers' => 1.0,
            'javmeta_search_embedding_backfill_fixed:performers' => 1.0,
        ], $metrics);
    }

    public function test_command_reads_database_rows_in_bounded_batches_and_obeys_the_limit(): void
    {
        Movie::factory()->count(4)->create(['title_en' => fake()->sentence()]);
        $this->configureSearchServices();
        $batchSizes = [];

        Http::fake(function (ClientRequest $request) use (&$batchSizes) {
            if (str_ends_with($request->url(), '/healthz')) {
                return Http::response(['status' => 'ok']);
            }

            if (str_ends_with($request->url(), '/v1/embeddings')) {
                return Http::response(['data' => [['embedding' => array_fill(0, 384, 0.25)]]]);
            }

            if (str_ends_with($request->url(), '/_search')) {
                $uuids = $request->data()['query']['bool']['filter'][0]['ids']['values'] ?? [];
                $batchSizes[] = count($uuids);

                return Http::response($this->vectorSearchResponse($request->data(), []));
            }

            return Http::response(['result' => 'created']);
        });

        $this->artisan('search:backfill-embeddings', ['--entity' => 'movies', '--batch' => 2, '--limit' => 3])
            ->assertSuccessful()
            ->expectsOutputToContain('Scanned: 3')
            ->expectsOutputToContain('Missing: 3')
            ->expectsOutputToContain('Fixed: 3');

        $this->assertSame([2, 1], $batchSizes);
        $this->assertSame(2, Http::recorded()->map(static fn(array $recorded): ClientRequest => $recorded[0])
            ->filter(static fn(ClientRequest $request): bool => str_ends_with($request->url(), '/healthz'))
            ->count());
    }

    public function test_metrics_omit_entities_skipped_when_the_limit_is_exhausted(): void
    {
        Movie::factory()->create(['title_en' => fake()->sentence()]);
        Performer::factory()->create(['name_romaji' => fake()->name()]);
        $this->configureSearchServices();
        $this->enableBackfillMetrics();

        Http::fake(function (ClientRequest $request) {
            if (str_ends_with($request->url(), '/healthz')) {
                return Http::response(['status' => 'ok']);
            }

            if (str_ends_with($request->url(), '/v1/embeddings')) {
                return Http::response(['data' => [['embedding' => array_fill(0, 384, 0.25)]]]);
            }

            if (str_ends_with($request->url(), '/_search')) {
                return Http::response($this->vectorSearchResponse($request->data(), []));
            }

            return Http::response(['result' => 'created']);
        });

        $this->artisan('search:backfill-embeddings', ['--entity' => 'all', '--limit' => 1])
            ->assertSuccessful()
            ->expectsOutputToContain('Scanned: 1')
            ->expectsOutputToContain('Missing: 1')
            ->expectsOutputToContain('Fixed: 1');

        $this->assertSame([
            'javmeta_search_embedding_backfill_missing:movies' => 1.0,
            'javmeta_search_embedding_backfill_fixed:movies' => 1.0,
        ], $this->backfillMetricValues());
    }

    public function test_dry_run_counts_missing_vectors_without_health_checks_or_writes(): void
    {
        Movie::factory()->create(['title_en' => fake()->sentence()]);
        $this->configureSearchServices();
        Http::fake(function (ClientRequest $request) {
            if (str_ends_with($request->url(), '/_search')) {
                return Http::response($this->vectorSearchResponse($request->data(), []));
            }

            return Http::response(['result' => 'created']);
        });

        $this->artisan('search:backfill-embeddings', ['--entity' => 'movies', '--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Scanned: 1')
            ->expectsOutputToContain('Missing: 1')
            ->expectsOutputToContain('Fixed: 0')
            ->expectsOutputToContain('Still missing: 1');

        Http::assertNotSent(static fn(ClientRequest $request): bool => str_ends_with($request->url(), '/healthz'));
        Http::assertNotSent(static fn(ClientRequest $request): bool => str_ends_with($request->url(), '/v1/embeddings'));
        Http::assertNotSent(static fn(ClientRequest $request): bool => str_contains($request->url(), '/_doc/'));
    }

    public function test_command_stops_before_indexing_when_the_embedder_is_down_and_omits_unprocessed_entity_metrics(): void
    {
        Movie::factory()->create(['title_en' => fake()->sentence()]);
        Performer::factory()->create(['name_romaji' => fake()->name()]);
        $this->configureSearchServices();
        $this->enableBackfillMetrics();
        Http::fake(function (ClientRequest $request) {
            if (str_ends_with($request->url(), '/healthz')) {
                return Http::response([], 503);
            }

            if (str_ends_with($request->url(), '/_search')) {
                return Http::response($this->vectorSearchResponse($request->data(), []));
            }

            return Http::response(['result' => 'created']);
        });

        $this->artisan('search:backfill-embeddings', ['--entity' => 'all'])
            ->assertFailed()
            ->expectsOutputToContain('Embedder health check failed')
            ->expectsOutputToContain('Missing: 1')
            ->expectsOutputToContain('Fixed: 0')
            ->expectsOutputToContain('Still missing: 1');

        Http::assertNotSent(static fn(ClientRequest $request): bool => str_ends_with($request->url(), '/v1/embeddings'));
        Http::assertNotSent(static fn(ClientRequest $request): bool => str_contains($request->url(), '/_doc/'));
        $this->assertSame([
            'javmeta_search_embedding_backfill_missing:movies' => 1.0,
            'javmeta_search_embedding_backfill_fixed:movies' => 0.0,
        ], $this->backfillMetricValues());
    }

    public function test_command_fails_when_elasticsearch_cannot_compare_the_batch(): void
    {
        Movie::factory()->create(['title_en' => fake()->sentence()]);
        $this->configureSearchServices();
        Http::fake(static function (ClientRequest $request) {
            return str_ends_with($request->url(), '/_search')
                ? Http::response([], 503)
                : Http::response(['result' => 'created']);
        });

        $this->artisan('search:backfill-embeddings', ['--entity' => 'movies'])
            ->assertFailed()
            ->expectsOutputToContain('Elasticsearch vector lookup failed')
            ->expectsOutputToContain('Scanned: 1')
            ->expectsOutputToContain('Missing: 0')
            ->expectsOutputToContain('Fixed: 0');

        Http::assertNotSent(static fn(ClientRequest $request): bool => str_ends_with($request->url(), '/healthz'));
    }

    private function configureSearchServices(): void
    {
        config()->set([
            'elasticsearch.host' => 'http://elasticsearch.test',
            'elasticsearch.username' => '',
            'elasticsearch.password' => '',
            'elasticsearch.api_key' => '',
            'elasticsearch.verify_ssl' => false,
            'elasticsearch.embedder_url' => 'http://embedder.test',
            'openobserve.enabled' => false,
        ]);
    }

    private function enableBackfillMetrics(): void
    {
        config()->set([
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.email' => '',
            'openobserve.password' => '',
            'openobserve.timeout' => 1,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $states
     * @return array{hits: array{hits: list<array{_id: string}>}}
     */
    private function vectorSearchResponse(array $body, array $states): array
    {
        $uuids = $body['query']['bool']['filter'][0]['ids']['values'] ?? [];
        $hits = [];
        foreach ($uuids as $uuid) {
            if (($states[$uuid] ?? 'missing') === 'complete') {
                $hits[] = ['_id' => (string) $uuid];
            }
        }

        return ['hits' => ['hits' => $hits]];
    }

    /** @return array<string, float> */
    private function backfillMetricValues(): array
    {
        $metricsRequest = Http::recorded()
            ->map(static fn(array $recorded): ClientRequest => $recorded[0])
            ->first(static fn(ClientRequest $request): bool => str_ends_with($request->url(), '/v1/metrics'));

        self::assertInstanceOf(ClientRequest::class, $metricsRequest);
        $points = $metricsRequest->data()['resourceMetrics'][0]['scopeMetrics'][0]['metrics'] ?? [];
        $values = [];
        foreach ($points as $point) {
            $entity = collect($point['gauge']['dataPoints'][0]['attributes'] ?? [])
                ->firstWhere('key', 'entity')['value']['stringValue'] ?? '';
            $values[($point['name'] ?? '') . ':' . $entity] = (float) ($point['gauge']['dataPoints'][0]['asDouble'] ?? 0);
        }

        return $values;
    }
}
