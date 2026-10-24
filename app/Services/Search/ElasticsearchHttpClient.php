<?php

declare(strict_types=1);

namespace App\Services\Search;

use InvalidArgumentException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class ElasticsearchHttpClient
{
    public function __construct(private readonly ElasticsearchRequestHealth $health) {}

    /** @param array<string, mixed> $query */
    public function get(
        string $operation,
        string $path,
        int $timeout = 5,
        array $query = [],
        ?int $connectTimeout = null,
    ): Response {
        return $this->send($operation, 'GET', $path, null, $timeout, $query, connectTimeout: $connectTimeout);
    }

    /** @param list<int> $acceptedStatuses */
    public function head(string $operation, string $path, int $timeout = 10, array $acceptedStatuses = []): Response
    {
        return $this->send($operation, 'HEAD', $path, null, $timeout, acceptedStatuses: $acceptedStatuses);
    }

    /** @param array<string, mixed> $body */
    public function post(string $operation, string $path, array $body, int $timeout = 5): Response
    {
        return $this->send($operation, 'POST', $path, $body, $timeout);
    }

    /** @param array<string, array{path: string, body: array<string, mixed>}> $requests
     * @return array<string, Response>
     */
    public function postPool(array $requests): array
    {
        $host = rtrim((string) config('elasticsearch.host', 'http://elasticsearch:9200'), '/');
        if (app(SearchBudget::class)->expired()) {
            return [];
        }
        try {
            $responses = Http::pool(function (Pool $pool) use ($requests, $host): array {
                $pending = [];
                foreach ($requests as $name => $request) {
                    $pending[$name] = $this->request(5, null, $pool->as($name))->post($host . '/' . ltrim($request['path'], '/'), $request['body']);
                }

                return $pending;
            });
        } catch (Throwable) {
            $this->health->recordFailure('search', null);

            return [];
        }
        $results = [];
        foreach ($responses as $name => $response) {
            if ($response instanceof Response && $response->successful()) {
                $this->health->recordSuccess('search');
                $results[$name] = $response;
            } else {
                $this->health->recordFailure('search', $response instanceof Response ? $response->status() : null);
            }
        }

        return $results;
    }

    /** @param array<string, mixed> $body */
    public function put(string $operation, string $path, array $body, int $timeout = 5): Response
    {
        return $this->send($operation, 'PUT', $path, $body, $timeout);
    }

    /** @param list<int> $acceptedStatuses */
    public function delete(string $operation, string $path, int $timeout = 10, array $acceptedStatuses = []): Response
    {
        return $this->send($operation, 'DELETE', $path, null, $timeout, acceptedStatuses: $acceptedStatuses);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, mixed> $query
     * @param list<int> $acceptedStatuses
     */
    private function send(
        string $operation,
        string $method,
        string $path,
        ?array $body,
        int $timeout,
        array $query = [],
        array $acceptedStatuses = [],
        ?int $connectTimeout = null,
    ): Response {
        $host = rtrim((string) config('elasticsearch.host', 'http://elasticsearch:9200'), '/');
        $url = $host . '/' . ltrim($path, '/');

        try {
            $request = $this->request($timeout, $connectTimeout);
            $response = match ($method) {
                'GET' => $request->get($url, $query),
                'HEAD' => $request->head($url),
                'POST' => $request->post($url, $body ?? []),
                'PUT' => $request->put($url, $body ?? []),
                'DELETE' => $request->delete($url),
                default => throw new InvalidArgumentException('Unsupported Elasticsearch HTTP method.'),
            };
        } catch (Throwable $exception) {
            $this->health->recordFailure($operation, null);

            throw $exception;
        }

        $ok = $response->successful() || in_array($response->status(), $acceptedStatuses, true);
        if ($ok) {
            $this->health->recordSuccess($operation);
        } else {
            $this->health->recordFailure($operation, $response->status());
        }

        return $response;
    }

    private function request(int $timeout, ?int $connectTimeout, ?PendingRequest $pending = null): PendingRequest
    {
        $seconds = app(SearchBudget::class)->seconds($timeout);
        if ($seconds <= 0) {
            throw new RuntimeException('Search HTTP budget exhausted.');
        }
        $request = ($pending ?? Http::acceptJson())->acceptJson()->timeout($seconds)->connectTimeout($seconds);
        if ($connectTimeout !== null) {
            $request = $request->connectTimeout($connectTimeout);
        }

        $apiKey = (string) config('elasticsearch.api_key', '');
        if ($apiKey !== '') {
            $request = $request->withHeaders(['Authorization' => 'ApiKey ' . $apiKey]);
        } else {
            $username = (string) config('elasticsearch.username', '');
            $password = (string) config('elasticsearch.password', '');
            if ($username !== '' || $password !== '') {
                $request = $request->withBasicAuth($username, $password);
            }
        }

        if (! (bool) config('elasticsearch.verify_ssl', false)) {
            $request = $request->withoutVerifying();
        }

        return $request;
    }
}
