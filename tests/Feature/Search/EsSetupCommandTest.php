<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\Movie;
use App\Models\Performer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class EsSetupCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_creates_both_vector_indexes_with_auth_before_reindexing(): void
    {
        Movie::factory()->create();
        Performer::factory()->create();
        $host = 'http://elasticsearch.test';
        config()->set([
            'elasticsearch.host' => $host,
            'elasticsearch.username' => 'javmeta-test-user',
            'elasticsearch.password' => 'javmeta-test-password',
            'elasticsearch.api_key' => '',
        ]);
        Http::preventStrayRequests();
        Http::fake(static function (ClientRequest $request) {
            if (str_ends_with($request->url(), '/v1/embeddings')) {
                return Http::response(['data' => [['embedding' => array_fill(0, 384, 0.25)]]], 200);
            }

            return $request->method() === 'HEAD'
                ? Http::response([], 404)
                : Http::response(['acknowledged' => true], 200);
        });

        $this->artisan('es:setup', ['--reindex' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Created index: ' . config('elasticsearch.movies_index'))
            ->expectsOutputToContain('Created index: ' . config('elasticsearch.performers_index'));

        $requests = Http::recorded()->map(static fn(array $recorded): ClientRequest => $recorded[0])->values();
        self::assertSame(['HEAD', 'PUT', 'HEAD', 'PUT'], $requests->slice(0, 4)->map(static fn(ClientRequest $request): string => $request->method())->all());
        foreach ($requests as $request) {
            if (str_starts_with($request->url(), $host)) {
                self::assertTrue($request->hasHeader(
                    'Authorization',
                    'Basic ' . base64_encode('javmeta-test-user:javmeta-test-password'),
                ));
            }
        }

        $movieIndex = (string) config('elasticsearch.movies_index');
        $performerIndex = (string) config('elasticsearch.performers_index');
        self::assertSame(384, $requests[1]->data()['mappings']['properties']['embedding_movie']['dims']);
        self::assertSame('cosine', $requests[1]->data()['mappings']['properties']['embedding_movie']['similarity']);
        self::assertSame(384, $requests[3]->data()['mappings']['properties']['embedding_performer']['dims']);
        self::assertSame('cosine', $requests[3]->data()['mappings']['properties']['embedding_performer']['similarity']);
        self::assertSame('/' . $movieIndex, parse_url($requests[0]->url(), PHP_URL_PATH));
        self::assertSame('/' . $performerIndex, parse_url($requests[2]->url(), PHP_URL_PATH));
        $firstDocumentRequest = $requests->search(static fn(ClientRequest $request): bool => str_contains($request->url(), '/_doc/'));
        self::assertNotFalse($firstDocumentRequest);
        self::assertGreaterThan(3, $firstDocumentRequest);
    }

    public function test_setup_fails_without_indexing_when_an_index_cannot_be_checked(): void
    {
        $host = 'http://elasticsearch.test';
        config()->set('elasticsearch.host', $host);
        Http::preventStrayRequests();
        Http::fake([$host . '/*' => Http::response([], 403)]);

        $this->artisan('es:setup', ['--reindex' => true])
            ->assertFailed()
            ->expectsOutputToContain('Elasticsearch index check failed');

        Http::assertSentCount(1);
        Http::assertNotSent(static fn(ClientRequest $request): bool => str_contains($request->url(), '/_doc/'));
    }
}
