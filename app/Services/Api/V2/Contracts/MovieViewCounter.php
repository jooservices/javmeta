<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Contracts;

use App\Services\Api\V2\Catalogue\StoredMovieViewCounter;
use Illuminate\Container\Attributes\Bind;

/**
 * The movie view counter of the catalogue: how often the UI opened a movie. The catalogue talks only to this interface.
 */
#[Bind(StoredMovieViewCounter::class)]
interface MovieViewCounter
{
    /** Records one view of a movie by an optional signed-in user and returns the movie's total view count. */
    public function record(string $code, ?int $userId): int;

    /**
     * View counts of many movies.
     *
     * @param list<string> $codes
     * @return array<string, int> count per requested code (0 when never viewed)
     */
    public function counts(array $codes): array;
}
