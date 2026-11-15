<?php

declare(strict_types=1);

namespace App\Services\Facets;

use App\Data\Facets\FacetListQuery;
use App\Repositories\MovieRepository;
use App\Repositories\PerformerRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Paginated facet value lists scoped to the current result set.
 *
 * Cache keys are bounded: facet + hashed (filters, q, sort, page, per_page, min_count).
 */
final class FacetListService
{
    private const TTL = 60;

    public function __construct(
        private readonly MovieRepository $movies,
        private readonly PerformerRepository $performers,
    ) {}

    /**
     * @return array{items: list<array<string, mixed>>, total: int, hidden_below_min: int, facet: string}
     */
    public function movieValues(FacetListQuery $query): array
    {
        return $this->remember('movie', $query, fn(): array => $this->movies->facetValues($query));
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, hidden_below_min: int, facet: string}
     */
    public function performerValues(FacetListQuery $query): array
    {
        return $this->remember('performer', $query, fn(): array => $this->performers->facetValues($query));
    }

    /**
     * @param  callable(): array{items: list<array<string, mixed>>, total: int, hidden_below_min: int, facet: string}  $load
     * @return array{items: list<array<string, mixed>>, total: int, hidden_below_min: int, facet: string}
     */
    private function remember(string $scope, FacetListQuery $query, callable $load): array
    {
        $payload = [
            $query->facet,
            $query->query,
            $query->sort,
            $query->perPage,
            $query->page,
            $query->minCount,
            $query->filters,
        ];
        $key = 'javmeta:facetlist:' . $scope . ':' . hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        /** @var array{items: list<array<string, mixed>>, total: int, hidden_below_min: int, facet: string} */
        return Cache::remember($key, self::TTL, $load);
    }
}
