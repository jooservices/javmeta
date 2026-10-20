<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Movie;
use App\Repositories\MovieRepository;
use Illuminate\Support\Collection;

/**
 * Search path: Elasticsearch → uuids → hydrate Postgres (normalized SoR).
 * Falls back to Postgres repository search when ES returns nothing / unavailable.
 * Unit/feature tests always use Postgres so results stay deterministic.
 */
final class MovieSearchService
{
    public function __construct(
        private readonly ElasticsearchIndexer $elasticsearch,
        private readonly MovieRepository $movies,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @param  array{sort: string, last_sort: mixed, last_id: int}|null  $cursor
     * @return array{items: Collection<int, Movie>, total: int, has_more: bool, last_id: int|null, last_sort: mixed, sort: string}
     */
    public function search(array $filters, string $sort, ?array $cursor, int $perPage): array
    {
        $q = $filters['q'] ?? null;

        if (
            (bool) config('elasticsearch.enabled', true)
            && $this->canUseElasticsearch($filters, $sort, $cursor)
            && is_string($q)
            && trim($q) !== ''
        ) {
            $uuids = $this->elasticsearch->searchMovieUuids(trim($q), min(100, max($perPage * 5, $perPage)));
            if ($uuids !== []) {
                $pageUuids = array_slice($uuids, 0, $perPage);
                $byUuid = Movie::query()
                    ->whereIn('uuid', $pageUuids)
                    ->with(['genres', 'performers.aliases', 'performers.sources', 'media', 'codes'])
                    ->get()
                    ->keyBy('uuid');

                $ordered = collect($pageUuids)
                    ->map(static fn(string $uuid): ?Movie => $byUuid->get($uuid))
                    ->filter(static fn($movie): bool => $movie instanceof Movie)
                    ->values();

                // Stale ES hits (uuid not in Postgres) → fall through to PG search.
                if ($ordered->isNotEmpty()) {
                    /** @var Movie $last */
                    $last = $ordered->last();

                    return [
                        'items' => $ordered,
                        'total' => count($uuids),
                        'has_more' => count($uuids) > $perPage,
                        'last_id' => $last->id,
                        'last_sort' => null,
                        'sort' => $sort,
                    ];
                }
            }
        }

        return $this->movies->search($filters, $sort, $cursor, $perPage);
    }

    /**
     * Accurate filtered total from the Postgres SoR (no items, no ES cap).
     *
     * @param  array<string, mixed>  $filters
     */
    public function count(array $filters): int
    {
        return $this->movies->countFiltered($filters);
    }

    /**
     * Natural-language semantic search: embed the query, kNN over ES vectors,
     * hydrate uuids from Postgres. Falls back to keyword search when the
     * embedder or ES vectors are unavailable.
     *
     * @return array{items: Collection<int, Movie>, total: int, has_more: bool, last_id: int|null, last_sort: mixed, sort: string}
     */
    public function semanticSearch(string $query, int $perPage): array
    {
        if (! (bool) config('elasticsearch.enabled', true) || app()->environment('testing')) {
            return $this->movies->search(['q' => $query], 'relevance', null, $perPage);
        }

        $uuids = $this->elasticsearch->semanticMovieUuids($query, min(100, max($perPage * 5, $perPage)));
        if ($uuids !== []) {
            $pageUuids = array_slice($uuids, 0, $perPage);
            $byUuid = Movie::query()
                ->whereIn('uuid', $pageUuids)
                    ->with(['genres', 'performers.aliases', 'performers.sources', 'media', 'codes'])
                ->get()
                ->keyBy('uuid');

            $ordered = collect($pageUuids)
                ->map(static fn(string $uuid): ?Movie => $byUuid->get($uuid))
                ->filter(static fn($movie): bool => $movie instanceof Movie)
                ->values();

            if ($ordered->isNotEmpty()) {
                /** @var Movie $last */
                $last = $ordered->last();

                return [
                    'items' => $ordered,
                    'total' => count($uuids),
                    'has_more' => count($uuids) > $perPage,
                    'last_id' => $last->id,
                    'last_sort' => null,
                    'sort' => 'relevance',
                ];
            }
        }

        return $this->movies->search(['q' => $query], 'relevance', null, $perPage);
    }

    /**
     * Elasticsearch currently only supports the free-text relevance path.
     * Filtered, sorted or cursor-paginated lookups must use the Postgres SoR.
     *
     * @param  array<string, mixed>  $filters
     */
    private function canUseElasticsearch(array $filters, string $sort, ?array $cursor): bool
    {
        if (app()->environment('testing') || $sort !== 'relevance' || $cursor !== null) {
            return false;
        }

        return array_diff(array_keys($filters), ['q']) === [];
    }
}
