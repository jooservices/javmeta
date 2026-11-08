<?php

declare(strict_types=1);

namespace App\Data\Library;

/** One page of current library files that have not matched a movie. */
final readonly class UnmatchedLibraryPage
{
    /**
     * @param list<object> $files
     */
    public function __construct(
        public array $files,
        public int $total,
        public int $page,
    ) {}

    /** @return array{data: list<object>, meta: array{total: int, page: int, per_page: int}} */
    public function toArray(): array
    {
        return [
            'data' => $this->files,
            'meta' => ['total' => $this->total, 'page' => $this->page, 'per_page' => 100],
        ];
    }
}
