<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Jobs\EmitObservabilitySpan;
use App\Models\ApiKey;
use App\Observability\OpenObserveClient;
use App\Observability\TraceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class TraceHttpRequest
{
    public function __construct(
        private readonly OpenObserveClient $client,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $parsed = TraceContext::parseTraceparent($request->headers->get('traceparent'));
        $traceId = $parsed['trace_id'] ?? TraceContext::newTraceId();
        $parentSpanId = $parsed['span_id'] ?? null;
        $spanId = TraceContext::newSpanId();
        $startNs = (int) (hrtime(true));

        $request->attributes->set('oo_trace_id', $traceId);
        $request->attributes->set('oo_span_id', $spanId);

        $response = $next($request);

        $apiKey = $request->attributes->get('api_key');
        try {
            if ($this->client->enabled()) {
                EmitObservabilitySpan::dispatch([
                    'name' => $request->method() . ' ' . '/' . ltrim($request->path(), '/'),
                    'trace_id' => $traceId,
                    'span_id' => $spanId,
                    'parent_span_id' => $parentSpanId,
                    'start_ns' => $startNs,
                    'end_ns' => (int) hrtime(true),
                    'status_code' => $response->getStatusCode(),
                    'attributes' => [
                        'http.method' => $request->method(),
                        'http.route' => '/' . ltrim($request->path(), '/'),
                        'http.status_code' => $response->getStatusCode(),
                        'api_key_id' => $apiKey instanceof ApiKey ? $apiKey->getKey() : null,
                    ],
                ]);
            }
        } catch (Throwable) {
            // Telemetry must never delay or fail the API response.
        }

        $response->headers->set('traceparent', TraceContext::formatTraceparent($traceId, $spanId));

        return $response;
    }
}
