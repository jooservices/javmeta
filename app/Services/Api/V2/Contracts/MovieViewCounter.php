<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Contracts;

use App\Services\Api\V2\Catalogue\NullMovieViewCounter;
use Illuminate\Container\Attributes\Bind;

/**
 * The movie view counter of the catalogue. The store behind it (the view history) belongs to the "me" area, so the catalogue
 * only talks to this interface; until that area binds a real implementation the null one answers (no views are kept).
 */
#[Bind(NullMovieViewCounter::class)]
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
