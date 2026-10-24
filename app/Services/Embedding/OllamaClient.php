<?php

declare(strict_types=1);

namespace App\Services\Embedding;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A small client for a local Ollama: list the models, embed texts, ask a model for JSON. The address, models and timeout are
 * settings in the database. Fail-open: any error returns null so the keyword search keeps working.
 */
class OllamaClient
{
    public function baseUrl(): string
    {
        return rtrim((string) config('javmeta_ai.ollama_url', 'http://127.0.0.1:11434'), '/');
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
            $response = Http::timeout($this->timeout())->post($this->baseUrl() . '/api/embed', ['model' => (string) config('javmeta_ai.ollama_embed_model'), 'input' => $texts]);
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
    public function json(string $system, string $user): ?array
    {
        try {
            $response = Http::timeout($this->timeout())->post($this->baseUrl() . '/api/chat', [
                'model' => (string) config('javmeta_ai.ollama_chat_model'),
                'stream' => false,
                'format' => 'json',
                'options' => ['temperature' => 0],
                'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
            ]);
            $content = $response->successful() ? $response->json('message.content') : null;
            $decoded = is_string($content) ? json_decode($content, true) : null;

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }
}
