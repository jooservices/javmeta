<?php

declare(strict_types=1);

namespace App\Services\Embedding;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A small client for a local Ollama: list the models, embed texts, ask a model for JSON. The address, models and timeout are
 * settings in the database. Fail-open: any error returns null so the keyword search keeps working.
 */
class OllamaClient
{
    private const NL_TIMEOUT_MAX_SECONDS = 2;

    public function baseUrl(): string
    {
        return rtrim((string) config('javmeta_ai.ollama_url', 'http://127.0.0.1:11434'), '/');
    }

    private function keepAlive(): string
    {
        return (string) config('javmeta_ai.ollama_keep_alive', '30m');
    }

    /** Load a model and keep it in memory (an empty generate request). */
    public function warm(string $model): bool
    {
        try {
            return Http::timeout($this->timeout())->post($this->baseUrl() . '/api/generate', ['model' => $model, 'keep_alive' => $this->keepAlive()])->successful();
        } catch (Throwable) {
            return false;
        }
    }

    private function timeout(): int
    {
        return max(1, (int) config('javmeta_ai.ollama_timeout', 30));
    }

    /** @return list<string>|null the names of the installed models, null when Ollama cannot be reached */
    public function models(): ?array
    {
        try {
            $response = Http::timeout(min(10, $this->timeout()))->get($this->baseUrl() . '/api/tags');

            return $response->successful() ? array_values(array_map(static fn($m): string => (string) ($m['name'] ?? ''), (array) $response->json('models', []))) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>|null
     */
    public function embed(array $texts): ?array
    {
        try {
            $seconds = app(\App\Services\Search\SearchBudget::class)->seconds($this->timeout());
            if ($seconds <= 0) {
                return null;
            }
            $response = Http::timeout($seconds)->connectTimeout($seconds)->post($this->baseUrl() . '/api/embed', ['model' => (string) config('javmeta_ai.ollama_embed_model'), 'input' => $texts, 'keep_alive' => $this->keepAlive()]);
            $embeddings = $response->successful() ? $response->json('embeddings') : null;
            if (! is_array($embeddings) || count($embeddings) !== count($texts)) {
                return null;
            }

            return array_values(array_map(static fn($v): array => array_values(array_map('floatval', (array) $v)), $embeddings));
        } catch (Throwable) {
            return null;
        }
    }

    /** Ask the chat model for one JSON object (Ollama's structured output). @return array<string, mixed>|null */
    public function json(string $system, string $user, ?array $schema = null): ?array
    {
        return $this->jsonWithTelemetry($system, $user, $schema)['data'];
    }

    /**
     * Ask Ollama for structured output and return safe diagnostics without including raw content in those diagnostics.
     *
     * @return array{data: array<string, mixed>|null, outcome: string, duration_ms: int, http_status: int|null}
     */
    public function jsonWithTelemetry(string $system, string $user, ?array $schema = null): array
    {
        $startedAt = hrtime(true);

        try {
            // A reading of a typed question must not hold the search: a cold model load takes seconds, so a short timeout lets
            // the rules answer this time while the model loads for the next question.
            $configuredSeconds = max(1, (int) config('javmeta_ai.nl_timeout_seconds', self::NL_TIMEOUT_MAX_SECONDS));
            $seconds = app(\App\Services\Search\SearchBudget::class)->seconds(min(self::NL_TIMEOUT_MAX_SECONDS, $configuredSeconds));
            if ($seconds <= 0) {
                return $this->jsonTelemetryResult(null, 'budget_exhausted', $startedAt);
            }

            $response = Http::timeout($seconds)->connectTimeout($seconds)->withoutRedirecting()->post($this->baseUrl() . '/api/chat', [
                'model' => (string) config('javmeta_ai.ollama_chat_model'),
                'keep_alive' => $this->keepAlive(),
                'stream' => false,
                'format' => $schema ?? 'json',
                'options' => ['temperature' => 0],
                'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
            ]);
            $status = $response->status();
            if (! $response->successful()) {
                return $this->jsonTelemetryResult(null, 'http_error', $startedAt, $status);
            }

            $content = $response->json('message.content');
            $decoded = is_string($content) ? json_decode($content, true) : null;

            return is_array($decoded)
                ? $this->jsonTelemetryResult($decoded, 'success', $startedAt, $status)
                : $this->jsonTelemetryResult(null, 'invalid_json', $startedAt, $status);
        } catch (ConnectionException $exception) {
            $message = strtolower($exception->getMessage());
            $outcome = str_contains($message, 'timed out')
                || str_contains($message, 'timeout')
                || str_contains($message, 'curl error 28')
                ? 'timeout'
                : 'transport_error';

            return $this->jsonTelemetryResult(null, $outcome, $startedAt);
        } catch (Throwable) {
            return $this->jsonTelemetryResult(null, 'client_error', $startedAt);
        }
    }

    /** @return array{data: array<string, mixed>|null, outcome: string, duration_ms: int, http_status: int|null} */
    private function jsonTelemetryResult(?array $data, string $outcome, int $startedAt, ?int $httpStatus = null): array
    {
        return [
            'data' => $data,
            'outcome' => $outcome,
            'duration_ms' => max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000)),
            'http_status' => $httpStatus,
        ];
    }
}
