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
use Illuminate\Support\Facades\Queue;
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
            if ($this->client->enabled() && $this->shouldQueueSpan($traceId)) {
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

    private function shouldQueueSpan(string $traceId): bool
    {
        if (config('queue.default') === 'sync') {
            return false;
        }

        $samplePercent = (int) config('javmeta_queues.observability_sample_percent', 10);
        if ($samplePercent <= 0 || ($samplePercent < 100 && hexdec(substr($traceId, -2)) % 100 >= $samplePercent)) {
            return false;
        }

        $queue = (string) config('javmeta_queues.observability_queue', 'observability');
        $depthLimit = max(1, (int) config('javmeta_queues.observability_depth_limit', 1000));

        return Queue::size($queue) < $depthLimit;
    }
}
