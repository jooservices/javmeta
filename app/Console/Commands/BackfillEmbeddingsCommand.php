<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Movie;
use App\Models\Performer;
use App\Services\Search\ElasticsearchHttpClient;
use App\Services\Search\ElasticsearchIndexer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Rebuild missing movie and performer vectors from the source models. */
final class BackfillEmbeddingsCommand extends Command
{
    protected $signature = 'search:backfill-embeddings {--entity=all : movies, performers, or all} {--batch=100 : Documents to read per Elasticsearch request} {--limit=0 : Maximum documents to inspect; 0 means unlimited} {--dry-run : Count eligible documents without indexing}';

    protected $description = 'Backfill Elasticsearch documents that are missing embedding vectors.';

    public function handle(ElasticsearchIndexer $indexer, ElasticsearchHttpClient $elasticsearch): int
    {
        $entity = (string) $this->option('entity');
        $batchSize = (int) $this->option('batch');
        $limit = (int) $this->option('limit');

        if (! in_array($entity, ['movies', 'performers', 'all'], true)) {
            $this->error('The --entity option must be movies, performers, or all.');

            return self::FAILURE;
        }

        if ($batchSize < 1) {
            $this->error('The --batch option must be greater than zero.');

            return self::FAILURE;
        }

        if ($limit < 0) {
            $this->error('The --limit option cannot be negative.');

            return self::FAILURE;
        }

        $counts = ['found' => 0, 'reindexed' => 0, 'still_missing' => 0, 'skipped' => 0];
        $definitions = [
            'movies' => [
                'index' => (string) config('elasticsearch.movies_index'),
                'vector' => 'embedding_movie',
            ],
            'performers' => [
                'index' => (string) config('elasticsearch.performers_index'),
                'vector' => 'embedding_performer',
            ],
        ];
        $entities = $entity === 'all' ? ['movies', 'performers'] : [$entity];
        $success = true;

        foreach ($entities as $currentEntity) {
            if ($limit > 0 && $counts['found'] >= $limit) {
                break;
            }

            $definition = $definitions[$currentEntity];
            $success = $this->backfillEntity(
                $currentEntity,
                $definition['index'],
                $definition['vector'],
                $indexer,
                $elasticsearch,
                $batchSize,
                $limit,
                (bool) $this->option('dry-run'),
                $counts,
            );

            if (! $success) {
                break;
            }
        }

        $this->line("Found: {$counts['found']}");
        $this->line("Re-indexed: {$counts['reindexed']}");
        $this->line("Still missing: {$counts['still_missing']}");
        $this->line("Skipped: {$counts['skipped']}");

        if ((bool) $this->option('dry-run')) {
            $this->comment('Dry run complete; no documents were indexed.');
        }

        return $success ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param array{found: int, reindexed: int, still_missing: int, skipped: int} $counts
     */
    private function backfillEntity(
        string $entity,
        string $index,
        string $vectorField,
        ElasticsearchIndexer $indexer,
        ElasticsearchHttpClient $elasticsearch,
        int $batchSize,
        int $limit,
        bool $dryRun,
        array &$counts,
    ): bool {
        $searchAfter = null;

        while (true) {
            $remaining = $limit > 0 ? $limit - $counts['found'] : $batchSize;
            if ($remaining <= 0) {
                return true;
            }

            $pageSize = min($batchSize, $remaining);
            $hits = $this->searchMissing($elasticsearch, $index, $vectorField, $pageSize, $searchAfter);
            if ($hits === null) {
                return false;
            }

            if ($hits === []) {
                return true;
            }

            $counts['found'] += count($hits);

            $modelsToIndex = $this->loadBatchModels($entity, $hits, $counts);

            if ($dryRun) {
                $counts['still_missing'] += count($modelsToIndex);
            } else {
                if ($modelsToIndex !== [] && ! $this->embedderIsHealthy()) {
                    $counts['still_missing'] += count($modelsToIndex);
                    $this->error("Embedder health check failed before the {$entity} batch; stopping before indexing.");

                    return false;
                }

                $this->reindexModels($entity, $modelsToIndex, $indexer, $counts);
            }

            if (count($hits) < $pageSize) {
                return true;
            }

            $sortValues = $hits[array_key_last($hits)]['sort'] ?? null;
            if (! is_array($sortValues)) {
                $this->error("Elasticsearch did not return sort values for the {$entity} page.");

                return false;
            }

            $searchAfter = array_values($sortValues);
        }
    }

    /**
     * @param list<array<string, mixed>> $hits
     * @param array{found: int, reindexed: int, still_missing: int, skipped: int} $counts
     * @return list<Movie|Performer>
     */
    private function loadBatchModels(string $entity, array $hits, array &$counts): array
    {
        $uuids = [];
        foreach ($hits as $hit) {
            $uuid = $this->hitUuid($hit);
            if ($uuid !== null) {
                $uuids[] = $uuid;
            }
        }

        if ($entity === 'movies') {
            $models = Movie::query()->whereIn('uuid', $uuids)->get()->keyBy('uuid');
        } else {
            $models = Performer::query()->whereNull('canonical_performer_id')->whereIn('uuid', $uuids)->get()->keyBy('uuid');
        }

        $modelsToIndex = [];
        foreach ($hits as $hit) {
            $uuid = $this->hitUuid($hit);
            $model = $uuid === null ? null : $models->get($uuid);

            if (($entity === 'movies' && ! ($model instanceof Movie))
                || ($entity === 'performers' && ! ($model instanceof Performer))) {
                $counts['skipped']++;

                continue;
            }

            $modelsToIndex[] = $model;
        }

        return $modelsToIndex;
    }

    /**
     * @param list<Movie|Performer> $models
     * @param array{found: int, reindexed: int, still_missing: int, skipped: int} $counts
     */
    private function reindexModels(string $entity, array $models, ElasticsearchIndexer $indexer, array &$counts): void
    {
        foreach ($models as $model) {
            $indexed = match (true) {
                $entity === 'movies' && $model instanceof Movie => $indexer->indexMovie($model, requireVector: true),
                $entity === 'performers' && $model instanceof Performer => $indexer->indexPerformer($model, requireVector: true),
                default => false,
            };

            if ($indexed) {
                $counts['reindexed']++;
            } else {
                $counts['still_missing']++;
            }
        }
    }

    /**
     * @param array<string, mixed> $hit
     */
    private function hitUuid(array $hit): ?string
    {
        $uuid = $hit['_source']['uuid'] ?? $hit['_id'] ?? null;

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    /**
     * @param list<mixed>|null $searchAfter
     * @return list<array<string, mixed>>|null Null indicates a request or response error.
     */
    private function searchMissing(
        ElasticsearchHttpClient $elasticsearch,
        string $index,
        string $vectorField,
        int $size,
        ?array $searchAfter,
    ): ?array {
        $body = [
            'size' => $size,
            '_source' => ['uuid'],
            'query' => [
                'bool' => [
                    'must_not' => [
                        ['exists' => ['field' => $vectorField]],
                    ],
                ],
            ],
            'sort' => [['uuid' => ['order' => 'asc']]],
        ];

        if ($searchAfter !== null) {
            $body['search_after'] = $searchAfter;
        }

        try {
            $response = $elasticsearch->post('backfill', "/{$index}/_search", $body, 10);
            if (! $response->successful()) {
                $this->error("Elasticsearch search failed for {$index}.");

                return null;
            }

            $hits = $response->json('hits.hits');
            if (! is_array($hits)) {
                $this->error("Elasticsearch returned an invalid search response for {$index}.");

                return null;
            }

            foreach ($hits as $hit) {
                if (! is_array($hit)) {
                    $this->error("Elasticsearch returned an invalid hit for {$index}.");

                    return null;
                }
            }

            return array_values($hits);
        } catch (Throwable) {
            $this->error("Elasticsearch search failed for {$index}.");

            return null;
        }
    }

    private function embedderIsHealthy(): bool
    {
        $url = rtrim((string) config('elasticsearch.embedder_url'), '/');

        try {
            return Http::timeout(3)->get("{$url}/healthz")->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
