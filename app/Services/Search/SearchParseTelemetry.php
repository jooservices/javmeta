<?php

declare(strict_types=1);

namespace App\Services\Search;

/** Collects what happened while a question was parsed, so the request log can report it even when parsing fails. */
final class SearchParseTelemetry
{
    /** @var array<string, mixed> */
    private array $fields = [
        'parse_outcome' => 'failed',
        'parse_cache_status' => 'miss',
        'parse_backend' => 'none',
        'parse_fallback_reason' => 'operation_failed',
        'parse_duration_ms' => 0,
        'ollama_chat_outcome' => 'not_attempted',
        'ollama_chat_duration_ms' => null,
        'ollama_chat_http_status' => null,
    ];

    /** @param array<string, mixed> $chat what the interpreter measured about the Ollama call on a cache miss */
    public function succeeded(string $backend, bool $cacheMiss, array $chat): void
    {
        $this->fields = [
            'parse_outcome' => 'success',
            'parse_cache_status' => $cacheMiss ? 'miss' : 'hit',
            'parse_backend' => in_array($backend, ['ollama', 'rules'], true) ? $backend : 'rules',
            'parse_fallback_reason' => is_string($chat['fallback_reason'] ?? null) ? $chat['fallback_reason'] : null,
            'parse_duration_ms' => 0,
            'ollama_chat_outcome' => $cacheMiss ? (string) ($chat['ollama_chat_outcome'] ?? 'not_attempted') : 'cache_hit',
            'ollama_chat_duration_ms' => $cacheMiss && is_int($chat['ollama_chat_duration_ms'] ?? null) ? $chat['ollama_chat_duration_ms'] : null,
            'ollama_chat_http_status' => $cacheMiss && is_int($chat['ollama_chat_http_status'] ?? null) ? $chat['ollama_chat_http_status'] : null,
        ];
    }

    public function dependencyUnavailable(): void
    {
        $this->fields['parse_fallback_reason'] = 'dependency_unavailable';
    }

    public function finished(int $startedAtNanoseconds): void
    {
        $this->fields['parse_duration_ms'] = max(0, (int) round((hrtime(true) - $startedAtNanoseconds) / 1_000_000));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->fields;
    }
}
