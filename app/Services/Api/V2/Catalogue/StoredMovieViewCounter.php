<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Catalogue;

use App\Repositories\Api\V2\MovieViewCountRepository;
use App\Services\Api\V2\Contracts\MovieViewCounter;

/** Keeps one counter per movie code; a view by anyone, signed in or not, adds one. */
final class StoredMovieViewCounter implements MovieViewCounter
{
    public function __construct(private readonly MovieViewCountRepository $counts) {}

    public function record(string $code, ?int $userId): int
    {
        return $this->counts->increment($code);
    }

    public function counts(array $codes): array
    {
        $found = $this->counts->counts($codes);

        return array_map(static fn(string $code): int => $found[$code] ?? 0, array_combine($codes, $codes));
    }
}
