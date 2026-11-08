<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Search;

use App\Services\Search\ElasticsearchHttpClient;
use App\Services\Search\ElasticsearchRequestHealth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class ElasticsearchHttpClientTest extends TestCase
{
    public function test_every_request_method_uses_basic_authentication(): void
    {
        $host = 'http://elasticsearch.test';
        config()->set([
            'elasticsearch.host' => $host,
            'elasticsearch.username' => 'javmeta-test-user',
            'elasticsearch.password' => 'javmeta-test-password',
            'elasticsearch.api_key' => '',
            'elasticsearch.verify_ssl' => true,
        ]);
        Http::preventStrayRequests();
        Http::fake([$host . '/*' => Http::response([], 200)]);

        $client = app(ElasticsearchHttpClient::class);
        $client->get('probe', '/_cluster/health', 2, connectTimeout: 1);
        $client->head('setup', '/javmeta_movies');
        $client->post('search', '/javmeta_movies/_search', ['query' => []]);
        $client->put('index', '/javmeta_movies/_doc/movie-id', ['uuid' => 'movie-id']);
        $client->delete('cleanup', '/javmeta_movies', acceptedStatuses: [404]);

        $requests = Http::recorded()->map(static fn(array $recorded): ClientRequest => $recorded[0]);
        self::assertCount(5, $requests);
        foreach ($requests as $request) {
            self::assertSame(['application/json'], $request->header('Accept'));
            self::assertTrue($request->hasHeader(
                'Authorization',
                'Basic ' . base64_encode('javmeta-test-user:javmeta-test-password'),
            ));
        }

        self::assertSame(0, app(ElasticsearchRequestHealth::class)->failureCount());
    }

    public function test_pooled_requests_send_one_json_accept_header_and_authentication(): void
    {
        config()->set([
            'elasticsearch.host' => 'http://elasticsearch.test',
            'elasticsearch.api_key' => 'test-encoded-api-key',
        ]);
        Http::preventStrayRequests();
        Http::fake(['elasticsearch.test/*' => Http::response(['hits' => ['hits' => []]], 200)]);

        $responses = app(ElasticsearchHttpClient::class)->postPool([
            'movies' => ['path' => '/movies/_search', 'body' => ['size' => 1]],
            'performers' => ['path' => '/performers/_search', 'body' => ['size' => 1]],
        ]);

        self::assertSame(['movies', 'performers'], array_keys($responses));
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            self::assertSame(['application/json'], $request->header('Accept'));
            self::assertTrue($request->hasHeader('Authorization', 'ApiKey test-encoded-api-key'));
        }
        self::assertSame(0, app(ElasticsearchRequestHealth::class)->failureCount());
    }

    public function test_api_key_takes_precedence_over_basic_authentication(): void
    {
        $host = 'http://elasticsearch.test';
        config()->set([
            'elasticsearch.host' => $host,
            'elasticsearch.username' => 'javmeta-test-user',
            'elasticsearch.password' => 'javmeta-test-password',
            'elasticsearch.api_key' => 'test-encoded-api-key',
        ]);
        Http::preventStrayRequests();
        Http::fake([$host . '/*' => Http::response([], 200)]);

        app(ElasticsearchHttpClient::class)->get('health', '/', 3);

        Http::assertSent(static fn(ClientRequest $request): bool => $request->hasHeader('Authorization', 'ApiKey test-encoded-api-key'));
    }

    public function test_failed_request_is_logged_counted_and_visible_as_health_data(): void
    {
        $host = 'http://elasticsearch.test';
        config()->set([
            'elasticsearch.host' => $host,
            'elasticsearch.username' => 'javmeta-test-user',
            'elasticsearch.password' => 'javmeta-test-password',
            'elasticsearch.api_key' => '',
        ]);
        Http::preventStrayRequests();
        Http::fake([$host . '/*' => Http::response([], 401)]);
        Log::spy();

        $response = app(ElasticsearchHttpClient::class)->post('search', '/javmeta_movies/_search', ['query' => []]);

        self::assertSame(401, $response->status());
        $health = app(ElasticsearchRequestHealth::class)->status();
        self::assertSame('down', $health['status']);
        self::assertSame(1, $health['failed_requests']);
        self::assertSame('search', $health['last_failure']['operation']);
        self::assertSame(401, $health['last_failure']['status_code']);
        self::assertIsString($health['last_failure']['failed_at']);
        Log::shouldHaveReceived('warning')
            ->with('Elasticsearch request failed.', ['operation' => 'search', 'status_code' => 401])
            ->once();
    }

    public function test_successful_health_probe_does_not_hide_a_search_failure_but_successful_search_recovers(): void
    {
        $host = 'http://elasticsearch.test';
        config()->set('elasticsearch.host', $host);
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push([], 503)
            ->push([], 200)
            ->push([], 200);

        $client = app(ElasticsearchHttpClient::class);
        $client->post('search', '/javmeta_movies/_search', ['query' => []]);
        $client->get('probe', '/_cluster/health', 2);
        self::assertSame('down', app(ElasticsearchRequestHealth::class)->status()['status']);

        $client->post('search', '/javmeta_movies/_search', ['query' => []]);

        self::assertSame('up', app(ElasticsearchRequestHealth::class)->status()['status']);
        self::assertSame(1, app(ElasticsearchRequestHealth::class)->failureCount());
    }

    public function test_connection_failure_is_recorded_without_exception_details(): void
    {
        $host = 'http://elasticsearch.test';
        config()->set('elasticsearch.host', $host);
        Http::preventStrayRequests();
        Http::fake(static function (ClientRequest $request): never {
            throw new ConnectionException('test connection failure');
        });
        Log::spy();

        try {
            app(ElasticsearchHttpClient::class)->get('probe', '/_cluster/health');
            self::fail('The connection exception should be rethrown to the probe.');
        } catch (ConnectionException) {
            self::assertSame(null, app(ElasticsearchRequestHealth::class)->status()['last_failure']['status_code']);
        }

        Log::shouldHaveReceived('warning')
            ->with('Elasticsearch request failed.', ['operation' => 'probe', 'status_code' => null])
            ->once();
    }
}
