<?php

declare(strict_types=1);

namespace App\Data\Suggest;

use App\Exceptions\InvalidFilterException;

/** The validated text and result limit for quick suggestions. */
final readonly class SuggestQuery
{
    private function __construct(
        public string $query,
        public int $limit,
    ) {}

    public static function from(string $query, int $limit = 5): self
    {
        $query = trim($query);
        if (mb_strlen($query) < 2 || mb_strlen($query) > 100 || $limit < 1 || $limit > 20) {
            throw new InvalidFilterException('Invalid q or limit.', ['q' => ['2 to 100 characters'], 'limit' => ['1 to 20']]);
        }

        return new self($query, $limit);
    }
}
