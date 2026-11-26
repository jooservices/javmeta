<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Catalogue;

use App\Http\Api\V2\Envelope;
use App\Repositories\Api\V2\CatalogueRepository;

/** Movie and performer lists and their facets, in the shapes of the v2 contract. */
final class CatalogueService
{
    private const MAX_PER_PAGE = 96;

    public function __construct(private readonly CatalogueRepository $repository, private readonly Formatter $formatter) {}

    /**
     * @param array<string, mixed> $query
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function movies(array $query): array
    {
        [$page, $perPage] = $this->paging($query, 24);
        $sort = (string) ($query['sort'] ?? (($query['q'] ?? '') !== '' ? 'relevance' : 'release_date'));
        $result = $this->repository->movies($query, $sort, $perPage, ($page - 1) * $perPage);

        return ['data' => $this->movieSummaries($result['rows']), 'meta' => Envelope::pagination($page, $perPage, $result['total'])];
    }

    /**
     * Rows of rm_movies shaped as movie summaries with their first cast members.
     *
     * @param list<object> $rows
     * @return list<array<string, mixed>>
     */
    public function movieSummaries(array $rows): array
    {
        $castIds = [];
        foreach ($rows as $row) {
            $castIds[] = array_slice(array_map('intval', array_filter(explode(',', trim((string) $row->performer_ids, '{}')))), 0, 6);
        }

        $cast = [];
        foreach ($this->repository->performersByIds(array_values(array_unique(array_merge(...$castIds ?: [[]])))) as $performer) {
            $cast[(int) $performer->id] = $this->formatter->performer($performer);
        }

        $data = [];
        foreach ($rows as $i => $row) {
            $summary = $this->formatter->movie($row);
            $summary['performers'] = array_values(array_filter(array_map(static fn(int $id): ?array => $cast[$id] ?? null, $castIds[$i])));
            $data[] = $summary;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $query
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function performers(array $query): array
    {
        [$page, $perPage] = $this->paging($query, 24);
        $sort = (string) ($query['sort'] ?? (($query['in_movies'] ?? null) === '1' ? 'movies' : 'featured'));
        $result = $this->repository->performers($query, $sort, $perPage, ($page - 1) * $perPage);

        return ['data' => array_map($this->formatter->performer(...), $result['rows']), 'meta' => Envelope::pagination($page, $perPage, $result['total'])];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function movieFacets(array $query): array
    {
        return $this->repository->movieFacets($query);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function performerFacets(array $query): array
    {
        return $this->repository->performerFacets($query);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{0: int, 1: int}
     */
    private function paging(array $query, int $default): array
    {
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['per_page'] ?? $default) ?: $default));

        return [max(1, (int) ($query['page'] ?? 1) ?: 1), $perPage];
    }
}
