<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Operations;

/** The page and page size of a list, clamped to the limits of the contract. */
final class PageParams
{
    private function __construct(
        public readonly int $page,
        public readonly int $perPage,
    ) {}

    /** @param array<string, mixed> $query */
    public static function from(array $query, int $default, int $max): self
    {
        $perPage = (int) ($query['per_page'] ?? 0);

        return new self(
            max(1, (int) ($query['page'] ?? 1)),
            $perPage < 1 ? $default : min($max, $perPage),
        );
    }

    /** A filter value of the query string: a non-empty string, or null when it is absent or of another type. */
    public static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
