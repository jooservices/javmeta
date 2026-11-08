<?php

declare(strict_types=1);

namespace App\Services\Merge;

use App\Repositories\MovieRepository;
use App\Data\Crawl\MovieDraft;
use App\Models\Movie;
use App\Models\MovieCode;
use App\Support\Code\NormalizedCode;
use Carbon\CarbonImmutable;

/**
 * Resolves a MovieDraft to exactly one movie row (BR-1/D-23, AC-1.1).
 *
 * Merge ONLY on an exact code_normalized match (R-5, AD-10): the code is
 * normalized with the NormalizedCode VO so prefix collisions stay distinct
 * (FC2PPV1234567 != FC21234567). There is no fuzzy title matching; a movie
 * that is not referenced by any movie_codes row gets a fresh movies row, and
 * variants that share a code_normalized resolve to the same movie.
 *
 * Source-declared linkage is honoured when a draft carries it; none of the
 * Wave 1 normalizers declare extra codes, so the exact-code path is the only
 * active merge rule. False splits are accepted in Wave 1 (recoverable later),
 * false merges are not (owner-approved at the architecture gate).
 */
final class MovieMerger
{
    public function resolveMovieId(MovieDraft $draft): int
    {
        $code = NormalizedCode::from($draft->code);
        $existing = app(MovieRepository::class)->modelQuery(MovieCode::class)->where('code_normalized', $code->value())->first();

        if ($existing instanceof MovieCode) {
            return (int) $existing->movie_id;
        }

        // movies.code_normalized is unique and is the same exact key; the
        // fallback keeps resolution correct when the movie row exists but its
        // movie_codes row has not been written yet (retry/race).
        $movie = app(MovieRepository::class)->modelQuery(Movie::class)->where('code_normalized', $code->value())->first();
        if ($movie instanceof Movie) {
            return (int) $movie->id;
        }

        $crawledAt = $draft->crawledAt ?? CarbonImmutable::now();

        $movie = app(MovieRepository::class)->modelQuery(Movie::class)->create([
            'display_code' => $code->display(),
            'code_normalized' => $code->value(),
            'completeness_tier' => 0,
            'needs_review' => false,
            'attrs' => [],
            'first_seen_at' => $crawledAt,
            'updated_at' => $crawledAt,
            'crawled_at' => $crawledAt,
        ]);

        return (int) $movie->id;
    }
}
