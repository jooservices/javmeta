<?php

declare(strict_types=1);

namespace App\Observability;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fail-open ingest to OpenObserve via JSON logs + OTLP HTTP (no SDK).
 */
final class OpenObserveClient
{
    private const LOG_BUFFER_CAPACITY = 1000;

    private const LOG_BATCH_SIZE = 50;

    private const FAILURE_THRESHOLD = 3;

    private const CIRCUIT_COOLDOWN_SECONDS = 30;

    private const MAX_SHIPPING_WAIT_NANOSECONDS = 200_000_000;

    /** @var list<array<string, mixed>> */
    private array $logBuffer = [];

    private int $droppedLogCount = 0;

    private int $consecutiveFailures = 0;

    private ?int $retryAt = null;

    private bool $halfOpen = false;

    private ?int $requestObjectId = null;

    private ?int $requestDeadlineNanoseconds = null;

    public function __construct(
        private readonly TelemetrySanitizer $sanitizer,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('openobserve.enabled', false);
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    public function ingestLogs(array $records): void
    {
        if (! $this->enabled() || $records === []) {
            return;
        }

        $stream = (string) config('openobserve.stream_logs');
        $payload = [];
        foreach ($records as $record) {
            $payload[] = array_merge(
                $this->sanitizer->sanitizeContext($record),
                [
                    '_timestamp' => (int) ((float) ($record['_timestamp'] ?? (microtime(true) * 1_000_000))),
                    'service' => (string) config('openobserve.service_name'),
                    'environment' => (string) config('app.env', 'production'),
                    'role' => (string) (($record['event'] ?? null) === 'api_usage'
                        ? ($record['role'] ?? config('dependencies.role', 'api'))
                        : config('dependencies.role', 'api')),
                ],
            );
        }

        $capacity = max(1, (int) config('openobserve.log_buffer_capacity', self::LOG_BUFFER_CAPACITY));
        $availableSlots = max(0, $capacity - count($this->logBuffer));
        $accepted = array_slice($payload, 0, $availableSlots);
        $this->logBuffer = [...$this->logBuffer, ...$accepted];
        $dropped = count($payload) - count($accepted);
        if ($dropped > 0) {
            $this->droppedLogCount += $dropped;
            Log::channel('single')->warning('OpenObserve log buffer is full; records were dropped.', [
                'dropped' => $dropped,
                'total_dropped' => $this->droppedLogCount,
            ]);
        }

        $this->flushLogBuffer($stream);
    }

    public function droppedLogCount(): int
    {
        return $this->droppedLogCount;
    }

    /**
     * @param  list<array{name: string, value: float|int, labels?: array<string, string>}>  $points
     */
    public function ingestMetrics(array $points): void
    {
        if (! $this->enabled() || $points === []) {
            return;
        }

        $nowNs = (string) (int) (microtime(true) * 1_000_000_000);
        $scopeMetrics = [];
        foreach ($points as $point) {
            $labels = $point['labels'] ?? [];
            $labels['environment'] = (string) config('app.env', 'production');
            $labels['role'] = (string) config('dependencies.role', 'api');
            $attributes = [];
            foreach ($labels as $key => $value) {
                $attributes[] = [
                    'key' => (string) $key,
                    'value' => ['stringValue' => (string) $value],
                ];
            }
            $scopeMetrics[] = [
                'name' => $point['name'],
                'gauge' => [
                    'dataPoints' => [[
                        'asDouble' => (float) $point['value'],
                        'timeUnixNano' => $nowNs,
                        'attributes' => $attributes,
                    ]],
                ],
            ];
        }

        $body = [
            'resourceMetrics' => [[
                'resource' => [
                    'attributes' => [[
                        'key' => 'service.name',
                        'value' => ['stringValue' => (string) config('openobserve.service_name')],
                    ]],
                ],
                'scopeMetrics' => [[
                    'metrics' => $scopeMetrics,
                ]],
            ]],
        ];

        $this->postJson($this->otlpUrl('metrics'), $body, 'application/json');
    }

    /**
     * @param  array{
     *     name: string,
     *     trace_id: string,
     *     span_id: string,
     *     parent_span_id?: string|null,
     *     start_ns: int,
     *     end_ns: int,
     *     attributes?: array<string, scalar|null>,
     *     status_code?: int
     * }  $span
     */
    public function ingestSpan(array $span): void
    {
        if (! $this->enabled()) {
            return;
        }

        if (! $this->shouldSample()) {
            return;
        }

        $attributes = [];
        foreach ($this->sanitizer->sanitizeContext($span['attributes'] ?? []) as $key => $value) {
            if ($value === null) {
                continue;
            }
            $attributes[] = [
                'key' => (string) $key,
                'value' => is_bool($value) || is_int($value) || is_float($value)
                    ? ['stringValue' => (string) $value]
                    : ['stringValue' => (string) $value],
            ];
        }

        $otlpSpan = [
            'traceId' => $span['trace_id'],
            'spanId' => $span['span_id'],
            'name' => $span['name'],
            'kind' => 1,
            'startTimeUnixNano' => (string) $span['start_ns'],
            'endTimeUnixNano' => (string) $span['end_ns'],
            'attributes' => $attributes,
            'status' => [
                'code' => ($span['status_code'] ?? 0) >= 400 ? 2 : 1,
            ],
        ];
        if (! empty($span['parent_span_id'])) {
            $otlpSpan['parentSpanId'] = $span['parent_span_id'];
        }

        $body = [
            'resourceSpans' => [[
                'resource' => [
                    'attributes' => [[
                        'key' => 'service.name',
                        'value' => ['stringValue' => (string) config('openobserve.service_name')],
                    ]],
                ],
                'scopeSpans' => [[
                    'spans' => [$otlpSpan],
                ]],
            ]],
        ];

        $this->postJson($this->otlpUrl('traces'), $body, 'application/json');
    }

    private function shouldSample(): bool
    {
        $rate = (float) config('openobserve.trace_sample_rate', 1.0);
        if ($rate >= 1.0) {
            return true;
        }
        if ($rate <= 0.0) {
            return false;
        }

        return (mt_rand() / mt_getrandmax()) <= $rate;
    }

    private function jsonIngestUrl(string $stream): string
    {
        $org = rawurlencode((string) config('openobserve.org'));
        $stream = rawurlencode($stream);

        return (string) config('openobserve.url') . "/api/{$org}/{$stream}/_json";
    }

    private function otlpUrl(string $signal): string
    {
        $org = rawurlencode((string) config('openobserve.org'));

        return (string) config('openobserve.url') . "/api/{$org}/v1/{$signal}";
    }

    /**
     * @param  array<mixed>|list<array<string, mixed>>  $body
     */
    private function postJson(string $url, array $body, string $contentType = 'application/json'): bool
    {
        if (! $this->canAttemptRequest()) {
            return false;
        }

        $timeout = $this->shippingTimeoutSeconds();
        if ($timeout <= 0) {
            return false;
        }

        try {
            $pending = Http::timeout($timeout)
                ->connectTimeout($timeout)
                ->acceptJson()
                ->withHeaders(['Content-Type' => $contentType]);

            $email = (string) config('openobserve.email');
            $password = (string) config('openobserve.password');
            if ($email !== '' && $password !== '') {
                $pending = $pending->withBasicAuth($email, $password);
            }

            $response = $pending->post($url, $body);
            if ($response->failed()) {
                $this->recordRequestFailure();
                Log::channel('single')->warning('OpenObserve ingest failed.', [
                    'status' => $response->status(),
                    'url' => $this->redactUrl($url),
                ]);

                return false;
            }

            $this->recordRequestSuccess();

            return true;
        } catch (Throwable $exception) {
            $this->recordRequestFailure();
            Log::channel('single')->warning('OpenObserve ingest error.', [
                'error' => $exception->getMessage(),
                'url' => $this->redactUrl($url),
            ]);

            return false;
        }
    }

    private function flushLogBuffer(string $stream): void
    {
        if ($this->logBuffer === []) {
            return;
        }

        $batch = array_slice($this->logBuffer, 0, self::LOG_BATCH_SIZE);
        if (! $this->postJson($this->jsonIngestUrl($stream), $batch)) {
            return;
        }

        $this->logBuffer = array_slice($this->logBuffer, count($batch));
    }

    private function canAttemptRequest(): bool
    {
        if ($this->retryAt === null) {
            return true;
        }

        if (now()->getTimestamp() < $this->retryAt) {
            return false;
        }

        $this->retryAt = null;
        $this->halfOpen = true;

        return true;
    }

    private function recordRequestSuccess(): void
    {
        $this->consecutiveFailures = 0;
        $this->retryAt = null;
        $this->halfOpen = false;
    }

    private function recordRequestFailure(): void
    {
        $this->consecutiveFailures++;
        if (! $this->halfOpen && $this->consecutiveFailures < self::FAILURE_THRESHOLD) {
            return;
        }

        $this->consecutiveFailures = self::FAILURE_THRESHOLD;
        $this->retryAt = now()->getTimestamp() + self::CIRCUIT_COOLDOWN_SECONDS;
        $this->halfOpen = false;
    }

    private function shippingTimeoutSeconds(): float
    {
        $timeout = min(0.2, max(0.001, (float) config('openobserve.timeout', 3)));
        if (app()->runningInConsole() || ! app()->bound('request')) {
            return $timeout;
        }

        $request = app('request');
        $requestObjectId = spl_object_id($request);
        if ($this->requestObjectId !== $requestObjectId) {
            $this->requestObjectId = $requestObjectId;
            $this->requestDeadlineNanoseconds = hrtime(true) + self::MAX_SHIPPING_WAIT_NANOSECONDS;
        }

        $remainingNanoseconds = ($this->requestDeadlineNanoseconds ?? hrtime(true)) - hrtime(true);

        return min($timeout, max(0, $remainingNanoseconds) / 1_000_000_000);
    }

    private function redactUrl(string $url): string
    {
        return preg_replace('#://([^@/]+)@#', '://***@', $url) ?? $url;
    }
}
