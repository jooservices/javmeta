<?php

declare(strict_types=1);

namespace App\Services\Embedding;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Client for the optional embedding service (multilingual E5, OpenAI-compatible
 * /v1/embeddings). Fail-open: any error returns null so keyword search still works.
 */
final class EmbedderClient
{
    /**
     * @param  list<string>  $texts
     * @return list<list<float>>|null
     */
    public function embedTexts(array $texts, bool $isQuery = false): ?array
    {
        if (! (bool) config('elasticsearch.enabled', true) || ! (bool) config('elasticsearch.embedder_enabled', true)) {
            return null;
        }

        $texts = array_values(array_filter($texts, static fn(string $t): bool => trim($t) !== ''));
        if ($texts === []) {
            return [];
        }

        if ((string) config('javmeta_ai.embedding_provider', 'embedder') === 'ollama') {
            return app(OllamaClient::class)->embed($texts);
        }

        $budget = app(\App\Services\Search\SearchBudget::class);
        try {
            $seconds = $budget->seconds(2.0);
            if ($seconds <= 0) {
                $budget->degrade('semantic_timeout');

                return null;
            }
            $response = Http::timeout($seconds)->connectTimeout(min(2.0, $seconds))->post(
                rtrim((string) config('elasticsearch.embedder_url', 'http://embedder:8000'), '/') . '/v1/embeddings',
                [
                    'model' => $isQuery ? 'javmeta-query' : 'javmeta-passage',
                    'input' => $texts,
                ],
            );

            if (! $response->successful()) {
                $budget->degrade('semantic_unavailable');

                return null;
            }

            $data = $response->json('data');
            if (! is_array($data)) {
                $budget->degrade('semantic_unavailable');

                return null;
            }

            $vectors = [];
            foreach ($data as $item) {
                $embedding = $item['embedding'] ?? null;
                if (! is_array($embedding)) {
                    $budget->degrade('semantic_unavailable');

                    return null;
                }
                $vectors[] = array_values(array_map(static fn(mixed $v): float => (float) $v, $embedding));
            }

            return $vectors;
        } catch (ConnectionException) {
            $budget->degrade('semantic_timeout');

            return null;
        } catch (Throwable) {
            $budget->degrade('semantic_unavailable');

            return null;
        }
    }

    /** @return list<float>|null */
    public function embedOne(string $text, bool $isQuery = false): ?array
    {
        $vectors = $this->embedTexts([$text], $isQuery);

        return $vectors[0] ?? null;
    }
}
