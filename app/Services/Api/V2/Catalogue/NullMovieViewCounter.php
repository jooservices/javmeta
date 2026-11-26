<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Catalogue;

use App\Services\Api\V2\Contracts\MovieViewCounter;

/** Keeps no views: the default until the view history store is bound to the counter contract. */
final class NullMovieViewCounter implements MovieViewCounter
{
    public function record(string $code, ?int $userId): int
    {
        return 0;
    }

    public function counts(array $codes): array
    {
        return array_fill_keys($codes, 0);
    }
}
