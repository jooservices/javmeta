<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Movie;
use App\Models\Performer;
use App\Observability\OpenObserveClient;
use App\Services\Search\BackfillEmbeddingMetrics;
use App\Services\Search\ElasticsearchHttpClient;
use App\Services\Search\ElasticsearchIndexer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Rebuild missing movie and performer vectors from the source models. */
final class BackfillEmbeddingsCommand extends Command
{
    protected $signature = 'search:backfill-embeddings {--entity=all : movies, performers, or all} {--batch=100 : Documents to read per batch} {--limit=0 : Maximum database documents to inspect; 0 means unlimited} {--dry-run : Count eligible documents without indexing}';

    protected $description = 'Backfill Elasticsearch documents that are missing embedding vectors.';

    public function handle(
        ElasticsearchIndexer $indexer,
        ElasticsearchHttpClient $elasticsearch,
        OpenObserveClient $metrics,
    ): int {
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

        $entities = $entity === 'all' ? ['movies', 'performers'] : [$entity];
        $counts = [
            'scanned' => 0,
            'missing' => array_fill_keys($entities, 0),
            'fixed' => array_fill_keys($entities, 0),
            'complete' => 0,
            'still_missing' => 0,
        ];
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

        $success = true;
        foreach ($entities as $currentEntity) {
            $entityLimit = $limit > 0 ? max(0, $limit - $counts['scanned']) : 0;
            if ($limit > 0 && $entityLimit === 0) {
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
                $entityLimit,
                (bool) $this->option('dry-run'),
                $counts,
            );

            if (! $success) {
                break;
            }
        }

        $metrics->ingestMetrics(BackfillEmbeddingMetrics::points($counts['missing'], $counts['fixed']));

        $this->line("Scanned: {$counts['scanned']}");
        $this->line('Missing: ' . array_sum($counts['missing']));
        $this->line('Fixed: ' . array_sum($counts['fixed']));
        $this->line("Complete: {$counts['complete']}");
        $this->line("Still missing: {$counts['still_missing']}");

        if ((bool) $this->option('dry-run')) {
            $this->comment('Dry run complete; no documents were indexed.');
        }

        return $success ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param array{scanned: int, missing: array<string, int>, fixed: array<string, int>, complete: int, still_missing: int} $counts
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
        $lastId = null;
        $entityScanned = 0;

        while (true) {
            $remaining = $limit > 0 ? $limit - $entityScanned : $batchSize;
            if ($remaining <= 0) {
                return true;
            }

            $pageSize = min($batchSize, $remaining);
            $models = $this->loadBatchModels($entity, $pageSize, $lastId);
            if ($models->isEmpty()) {
                return true;
            }

            $lastId = $models->last()->getKey();
            $entityScanned += $models->count();
            $counts['scanned'] += $models->count();

            $completeUuids = $this->findCompleteVectorUuids(
                $models,
                $index,
                $vectorField,
                $elasticsearch,
            );
            if ($completeUuids === null) {
                return false;
            }

            $missingModels = [];
            foreach ($models as $model) {
                if (isset($completeUuids[(string) $model->uuid])) {
                    $counts['complete']++;

                    continue;
                }

                $missingModels[] = $model;
            }

            $counts['missing'][$entity] += count($missingModels);
            if ($missingModels === []) {
                continue;
            }

            if ($dryRun) {
                $counts['still_missing'] += count($missingModels);

                continue;
            }

            if (! $this->embedderIsHealthy()) {
                $counts['still_missing'] += count($missingModels);
                $this->error("Embedder health check failed before the {$entity} batch; stopping before indexing.");

                return false;
            }

            $this->reindexModels($entity, $missingModels, $indexer, $counts);
        }
    }

    /**
     * @return Collection<int, Movie>|Collection<int, Performer>
     */
    private function loadBatchModels(string $entity, int $batchSize, int|string|null $lastId): Collection
    {
        if ($entity === 'movies') {
            $query = Movie::query()->with(['genres', 'performers']);

            if ($lastId !== null) {
                $query->where('id', '>', $lastId);
            }

            return $query->orderBy('id')->limit($batchSize)->get();
        }

        $query = Performer::query()->with('aliases');
        if ($lastId !== null) {
            $query->where('id', '>', $lastId);
        }

        return $query->orderBy('id')->limit($batchSize)->get();
    }

    /**
     * @param Collection<int, Movie>|Collection<int, Performer> $models
     * @return array<string, true>|null Null indicates a request or response error.
     */
    private function findCompleteVectorUuids(
        Collection $models,
        string $index,
        string $vectorField,
        ElasticsearchHttpClient $elasticsearch,
    ): ?array {
        $uuids = [];
        foreach ($models as $model) {
            $uuid = (string) $model->uuid;
            if ($uuid === '') {
                $this->error('A database document has no UUID; stopping the backfill.');

                return null;
            }

            $uuids[] = $uuid;
        }

        try {
            $response = $elasticsearch->post('backfill', "/{$index}/_search", [
                'size' => count($uuids),
                'track_total_hits' => false,
                '_source' => false,
                'query' => [
                    'bool' => [
                        'filter' => [
                            ['ids' => ['values' => $uuids]],
                            ['exists' => ['field' => $vectorField]],
                        ],
                    ],
                ],
            ], 10);

            if ($response->status() === 404
                && $response->json('error.type') === 'index_not_found_exception') {
                return [];
            }

            if (! $response->successful()) {
                $this->error("Elasticsearch vector lookup failed for {$index}.");

                return null;
            }

            $hits = $response->json('hits.hits');
            if (! is_array($hits)) {
                $this->error("Elasticsearch returned an invalid vector lookup response for {$index}.");

                return null;
            }

            $expectedUuids = array_fill_keys($uuids, true);
            $completeUuids = [];
            foreach ($hits as $hit) {
                $uuid = is_array($hit) ? ($hit['_id'] ?? null) : null;
                if (! is_string($uuid) || ! isset($expectedUuids[$uuid]) || isset($completeUuids[$uuid])) {
                    $this->error("Elasticsearch returned an invalid vector lookup document for {$index}.");

                    return null;
                }

                $completeUuids[$uuid] = true;
            }

            return $completeUuids;
        } catch (Throwable) {
            $this->error("Elasticsearch vector lookup failed for {$index}.");

            return null;
        }
    }

    /**
     * @param list<Movie|Performer> $models
     * @param array{scanned: int, missing: array<string, int>, fixed: array<string, int>, complete: int, still_missing: int} $counts
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
                $counts['fixed'][$entity]++;
            } else {
                $counts['still_missing']++;
            }
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
