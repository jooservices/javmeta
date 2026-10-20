<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Movie;
use App\Models\Performer;
use App\Services\Search\ElasticsearchHttpClient;
use App\Services\Search\ElasticsearchIndexer;
use Illuminate\Console\Command;
use Throwable;

/**
 * Create/update the Elasticsearch index mappings (dense_vector embedding fields)
 * and optionally re-embed + re-index all existing movies and performers.
 */
final class EsSetupCommand extends Command
{
    protected $signature = 'es:setup {--reindex : Re-embed and re-index all movies and performers}';

    protected $description = 'Create/update ES mappings for dense_vector embeddings and (optionally) re-index all data.';

    public function handle(ElasticsearchHttpClient $elasticsearch): int
    {
        $dim = (int) config('elasticsearch.embed_dim', 384);
        $vectorProp = ['type' => 'dense_vector', 'dims' => $dim, 'index' => true, 'similarity' => 'cosine'];

        if (! $this->ensureIndex($elasticsearch, (string) config('elasticsearch.movies_index'), 'embedding_movie', $vectorProp)
            || ! $this->ensureIndex($elasticsearch, (string) config('elasticsearch.performers_index'), 'embedding_performer', $vectorProp)) {
            return self::FAILURE;
        }

        if ($this->option('reindex')) {
            $this->reindex();
        }

        $this->info('ES setup done.');

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $vectorProp */
    private function ensureIndex(ElasticsearchHttpClient $elasticsearch, string $index, string $embeddingField, array $vectorProp): bool
    {
        try {
            $head = $elasticsearch->head('setup', "/{$index}", acceptedStatuses: [404]);
            if ($head->ok()) {
                $response = $elasticsearch->put('setup', "/{$index}/_mapping", [
                    'properties' => [$embeddingField => $vectorProp],
                ]);
                if (! $response->successful()) {
                    $this->error("Elasticsearch mapping failed for {$index}.");

                    return false;
                }

                $this->info("Updated mapping: {$index}");
            } elseif ($head->status() === 404) {
                $response = $elasticsearch->put('setup', "/{$index}", [
                    'settings' => ['number_of_shards' => 1, 'number_of_replicas' => 0],
                    'mappings' => [
                        'properties' => [
                            'uuid' => ['type' => 'keyword'],
                            $embeddingField => $vectorProp,
                        ],
                    ],
                ]);
                if (! $response->successful()) {
                    $this->error("Elasticsearch index creation failed for {$index}.");

                    return false;
                }

                $this->info("Created index: {$index}");
            } else {
                $this->error("Elasticsearch index check failed for {$index}.");

                return false;
            }

            return true;
        } catch (Throwable) {
            $this->error("Elasticsearch mapping failed for {$index}.");

            return false;
        }
    }

    private function reindex(): void
    {
        $indexer = app(ElasticsearchIndexer::class);

        foreach (Movie::cursor() as $movie) {
            $indexer->indexMovie($movie);
            $this->line("  movie #{$movie->id} indexed");
        }

        foreach (Performer::cursor() as $performer) {
            $indexer->indexPerformer($performer);
            $this->line("  performer #{$performer->id} indexed");
        }
    }
}
