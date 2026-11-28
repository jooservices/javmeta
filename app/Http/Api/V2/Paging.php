<?php

declare(strict_types=1);

namespace App\Http\Api\V2;

/** Reads page and per_page from a query string, clamped to the range the contract allows. */
final class Paging
{
    /**
     * @param array<string, mixed> $query
     * @return array{0: int, 1: int} page, per page
     */
    public static function read(array $query, int $default, int $max): array
    {
        $perPage = min($max, max(1, (int) ($query['per_page'] ?? $default) ?: $default));

        return [max(1, (int) ($query['page'] ?? 1) ?: 1), $perPage];
    }
}
