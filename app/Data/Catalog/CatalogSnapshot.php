<?php

declare(strict_types=1);

namespace App\Data\Catalog;

/** A page of catalog options and its pagination metadata. */
final readonly class CatalogSnapshot
{
    /**
     * @param list<array<string, mixed>> $items
     * @param array{total: int, per_page: int, page: int, last_page: int, sort: string, min_count: int, hidden_below_min: int, script: string} $pagination
     */
    public function __construct(public array $items, public array $pagination) {}
}
