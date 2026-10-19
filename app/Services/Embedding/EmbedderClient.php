<?php

declare(strict_types=1);

namespace App\Services\Embedding;

use Illuminate\Support\Facades\Http;
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

        try {
            $response = Http::timeout(15)->post(
                rtrim((string) config('elasticsearch.embedder_url', 'http://embedder:8000'), '/') . '/v1/embeddings',
                [
                    'model' => $isQuery ? 'javmeta-query' : 'javmeta-passage',
                    'input' => $texts,
                ],
            );

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json('data');
            if (! is_array($data)) {
                return null;
            }

            $vectors = [];
            foreach ($data as $item) {
                $embedding = $item['embedding'] ?? null;
                if (! is_array($embedding)) {
                    return null;
                }
                $vectors[] = array_values(array_map(static fn(mixed $v): float => (float) $v, $embedding));
            }

            return $vectors;
        } catch (Throwable) {
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
