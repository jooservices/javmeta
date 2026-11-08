<?php

declare(strict_types=1);

namespace App\Data\Catalog;

/** The validated filters and paging options for one catalog read. */
final readonly class CatalogQuery
{
    /** @var list<string> */
    public const KINDS = ['genres', 'makers', 'series', 'labels', 'years', 'cups', 'blood_types', 'locations'];

    /** @var list<string> */
    public const SORTS = ['movie_count', 'name'];

    public function __construct(
        public string $kind,
        public string $query,
        public string $sort,
        public int $perPage,
        public int $page,
        public bool $includeUnknown,
    ) {}
}
