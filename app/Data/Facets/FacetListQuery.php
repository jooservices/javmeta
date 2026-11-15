<?php

declare(strict_types=1);

namespace App\Data\Facets;

/** Validated paging + search options for one facet value list. */
final readonly class FacetListQuery
{
    /** @var list<string> */
    public const MOVIE_FACETS = ['genres', 'makers', 'series', 'labels', 'years', 'sources', 'performers'];

    /** @var list<string> */
    public const PERFORMER_FACETS = ['cups', 'blood_types', 'locations', 'sources', 'status'];

    /** @var list<string> */
    public const SORTS = ['count', 'name'];

    public const MAX_PER_PAGE = 200;

    public const DEFAULT_PER_PAGE = 20;

    public const DEFAULT_MIN_COUNT = 3;

    /**
     * @param  array<string, mixed>  $filters  Result-set scope (movie or performer search filters)
     */
    public function __construct(
        public string $facet,
        public string $query,
        public string $sort,
        public int $perPage,
        public int $page,
        public int $minCount,
        public array $filters,
    ) {}
}
