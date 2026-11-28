<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Catalogue;

/** Page and page size of a list request, clamped to the limits of the contract. */
final class Paging
{
    /**
     * @param array<string, mixed> $query
     * @return array{0: int, 1: int} page, per page
     */
    public static function from(array $query, int $default, int $max): array
    {
        $perPage = min($max, max(1, (int) ($query['per_page'] ?? $default) ?: $default));

        return [max(1, (int) ($query['page'] ?? 1) ?: 1), $perPage];
    }
}
