<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Me;

/** The page and page size of a list request. */
final readonly class Page
{
    public function __construct(public int $number, public int $size) {}

    /** @param array<string, mixed> $query */
    public static function from(array $query, int $default = 48, int $max = 100): self
    {
        $size = (int) ($query['per_page'] ?? $default);

        return new self(max(1, (int) ($query['page'] ?? 1)), min($max, max(1, $size)));
    }

    public function offset(): int
    {
        return ($this->number - 1) * $this->size;
    }
}
