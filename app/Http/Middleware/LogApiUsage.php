<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\RateLimitedException;
use App\Models\ApiKey;
use App\Observability\ObservabilityEmitter;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

final class LogApiUsage
{
    private const TERMINATION_FIELDS = 'javmeta.api_usage.fields';

    public function __construct(
        private readonly ObservabilityEmitter $observability,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);
        if ($request->attributes->get('javmeta.internal_ui') === true) {
            // The owner's UI is not an API consumer: no key limit, no usage row.
            return $next($request);
        }

        $apiKey = $request->attributes->get('api_key');
        $limiterKey = $this->limiterKey($request, $apiKey);
        $maxAttempts = $apiKey instanceof ApiKey ? max(1, $apiKey->abuse_rpm) : 60;

        if (RateLimiter::tooManyAttempts($limiterKey, $maxAttempts)) {
            $response = (new RateLimitedException(RateLimiter::availableIn($limiterKey)))->render($request);
            $this->record($request, $response, $startedAt, $apiKey instanceof ApiKey ? $apiKey : null);

            return $response;
        }

        RateLimiter::hit($limiterKey, 60);
        $response = $next($request);
        $this->record($request, $response, $startedAt, $apiKey instanceof ApiKey ? $apiKey : null);

        return $response;
    }

    private function limiterKey(Request $request, mixed $apiKey): string
    {
        if ($apiKey instanceof ApiKey) {
            return 'api-key:' . $apiKey->getKey();
        }

        return 'api-ip:' . $this->ipHash($request);
    }

    private function record(Request $request, Response $response, int|float $startedAt, ?ApiKey $apiKey): void
    {
        $endpoint = '/' . ltrim($request->path(), '/');
        $responseMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
        $statusCode = $response->getStatusCode();

        $fields = [
            'api_key_id' => $apiKey?->getKey(),
            'endpoint' => $endpoint,
            'method' => $request->method(),
            'status_code' => $statusCode,
            'response_ms' => $responseMs,
            'ip_hash' => $this->ipHash($request),
        ];
        if ($endpoint === '/api/v1/search/parse') {
            $fields = [...$fields, ...$this->searchParseTelemetryFields($request)];
        }

        $request->attributes->set(self::TERMINATION_FIELDS, $fields);
    }

    /** @return array<string, scalar|null> */
    private function searchParseTelemetryFields(Request $request): array
    {
        $telemetry = $request->attributes->get('javmeta.search_parse.telemetry');
        if (! is_array($telemetry)) {
            return [];
        }

        $fields = [];
        $enums = [
            'parse_outcome' => ['success', 'failed'],
            'parse_cache_status' => ['hit', 'miss'],
            'parse_backend' => ['ollama', 'rules', 'none'],
            'parse_fallback_reason' => [
                'dependency_unavailable',
                'operation_failed',
                'ollama_http_error',
                'ollama_timeout',
                'ollama_transport_error',
                'ollama_client_error',
                'ollama_invalid_json',
                'ollama_budget_exhausted',
                'ollama_output_rejected',
            ],
            'ollama_chat_outcome' => [
                'not_attempted',
                'success',
                'http_error',
                'timeout',
                'transport_error',
                'client_error',
                'invalid_json',
                'budget_exhausted',
                'cache_hit',
            ],
        ];
        foreach ($enums as $field => $allowed) {
            $value = $telemetry[$field] ?? null;
            if (is_string($value) && in_array($value, $allowed, true)) {
                $fields[$field] = $value;
            }
        }

        foreach (['parse_duration_ms', 'ollama_chat_duration_ms'] as $field) {
            $value = $telemetry[$field] ?? null;
            if (is_int($value) && $value >= 0 && $value <= 3_600_000) {
                $fields[$field] = $value;
            }
        }

        $status = $telemetry['ollama_chat_http_status'] ?? null;
        if (is_int($status) && $status >= 100 && $status <= 599) {
            $fields['ollama_chat_http_status'] = $status;
        }

        return $fields;
    }

    public function terminate(Request $request, Response $response): void
    {
        $fields = $request->attributes->get(self::TERMINATION_FIELDS);
        $request->attributes->remove(self::TERMINATION_FIELDS);

        if (is_array($fields)) {
            $this->observability->emitApiUsage($fields);
        }
    }

    private function ipHash(Request $request): string
    {
        return hash('sha256', (string) $request->ip());
    }
}
