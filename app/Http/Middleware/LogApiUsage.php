<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\RateLimitedException;
use App\Models\ApiKey;
use App\Models\ApiUsageLog;
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

        ApiUsageLog::query()->create([
            'api_key_id' => $apiKey?->getKey(),
            'endpoint' => $endpoint,
            'method' => $request->method(),
            'status_code' => $statusCode,
            'response_ms' => $responseMs,
            'ip_hash' => $this->ipHash($request),
            'created_at' => now(),
        ]);

        $fields = [
            'api_key_id' => $apiKey?->getKey(),
            'endpoint' => $endpoint,
            'method' => $request->method(),
            'status_code' => $statusCode,
            'response_ms' => $responseMs,
            'ip_hash' => $this->ipHash($request),
        ];

        $request->attributes->set(self::TERMINATION_FIELDS, $fields);
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
