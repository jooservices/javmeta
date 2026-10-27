<?php

declare(strict_types=1);

namespace App\Observability;

use App\Jobs\EmitObservabilityLog;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fail-open telemetry emission to OpenObserve.
 */
final class ObservabilityEmitter
{
    public function __construct(
        private readonly TelemetrySanitizer $sanitizer,
        private readonly OpenObserveClient $client,
    ) {}

    /**
     * @param  array<string, mixed>  $detail
     */
    public function emitCrawlEvent(string $sourceSlug, string $kind, array $detail = []): void
    {
        $this->emit([
            'event' => 'crawl_event',
            'source_slug' => $sourceSlug,
            'kind' => $kind,
            'detail' => $this->sanitizer->sanitizeCrawlDetail($this->cleanPageUrl($detail)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function emitApiUsage(array $fields): void
    {
        if (! $this->client->enabled()) {
            return;
        }

        try {
            EmitObservabilityLog::dispatch($this->timestampedRecord([
                'event' => 'api_usage',
                ...$fields,
                'role' => (string) config('dependencies.role', 'api'),
            ]));
        } catch (Throwable $exception) {
            try {
                Log::channel('single')->warning('API usage telemetry queue dispatch failed.', [
                    'exception' => $exception::class,
                ]);
            } catch (Throwable) {
                // API termination must remain fail-open even if logging is unavailable.
            }
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function emitPersist(string $entity, array $fields): void
    {
        $this->emit([
            'event' => 'persist',
            'entity' => $entity,
            ...$this->sanitizer->sanitizeContext($fields),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $attempts  crawlerx fetch attempts (method, elapsed_ms, status, challenge, ok)
     * @param  array{url?: ?string, error_message?: ?string, retryable?: bool, retry_after_seconds?: ?int, queue_row_id?: ?int, crawlerx_version?: ?string}  $context
     */
    public function emitFetch(string $sourceSlug, string $crawlType, bool $ok, int $durationMs, ?string $errorCode = null, array $attempts = [], array $context = []): void
    {
        $last = $attempts === [] ? [] : $attempts[array_key_last($attempts)];
        $winning = null;
        foreach ($attempts as $attempt) {
            if ((bool) ($attempt['ok'] ?? false)) {
                $winning = $attempt;
            }
        }
        $used = $winning ?? $last;

        $this->emit([
            'event' => 'crawl_fetch',
            'source_slug' => $sourceSlug,
            'crawl_type' => $crawlType,
            'ok' => $ok,
            'duration_ms' => $durationMs,
            'error_code' => $errorCode,
            'page_url' => $this->sanitizer->sanitizeUrl($context['url'] ?? null),
            'error_message' => $this->sanitizer->sanitizeText($context['error_message'] ?? null),
            'retryable' => (bool) ($context['retryable'] ?? false),
            'retry_after_seconds' => $context['retry_after_seconds'] ?? null,
            'http_status' => is_int($used['status'] ?? null) ? $used['status'] : null,
            'method' => is_string($used['method'] ?? null) ? $used['method'] : null,
            'queue_row_id' => $context['queue_row_id'] ?? null,
            'crawlerx_version' => $context['crawlerx_version'] ?? null,
            'attempt_count' => count($attempts),
            'attempts' => array_map(static fn(array $attempt): array => [
                'method' => is_string($attempt['method'] ?? null) ? $attempt['method'] : null,
                'elapsed_ms' => is_int($attempt['elapsed_ms'] ?? null) ? $attempt['elapsed_ms'] : null,
                'status' => is_int($attempt['status'] ?? null) ? $attempt['status'] : null,
                'challenge' => (bool) ($attempt['challenge'] ?? false),
                'ok' => (bool) ($attempt['ok'] ?? false),
            ], $attempts),
        ]);
    }

    public function emitDependency(string $dependency, string $operation, bool $ok, int $durationMs = 0): void
    {
        $this->emit([
            'event' => 'dependency',
            'dependency' => $dependency,
            'operation' => $operation,
            'ok' => $ok,
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function emitOps(string $kind, array $fields = []): void
    {
        $this->emit([
            'event' => 'ops',
            'kind' => $kind,
            ...$this->sanitizer->sanitizeContext($fields),
        ]);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function emit(array $record): void
    {
        $this->client->ingestLogs([$this->timestampedRecord($record)]);
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function timestampedRecord(array $record): array
    {
        return [
            '_timestamp' => (int) (microtime(true) * 1_000_000),
            ...$this->sanitizer->sanitizeContext($record),
        ];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function cleanPageUrl(array $detail): array
    {
        if (array_key_exists('page_url', $detail)) {
            $detail['page_url'] = is_string($detail['page_url']) ? $this->sanitizer->sanitizeUrl($detail['page_url']) : null;
        }
        if (isset($detail['error_message']) && is_string($detail['error_message'])) {
            $detail['error_message'] = $this->sanitizer->sanitizeText($detail['error_message']);
        }

        return $detail;
    }
}
