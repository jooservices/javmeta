<?php

declare(strict_types=1);

namespace App\Services\Movies;

use App\Models\Movie;
use App\Models\MovieCode;
use App\Support\Code\NormalizedCode;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Reads movies by code for the lookup and bulk endpoints (REQ-3/REQ-6).
 *
 * - Codes are normalized with the NormalizedCode VO (BR-9) before matching
 *   against movie_codes.code_normalized, so ssis001 / SSIS-1 / " ssis-001 "
 *   all resolve to the same movie (AC-3.2) while prefix collisions stay
 *   distinct (FC2PPV1234567 != FC21234567).
 * - Bulk lookup issues one batch query (WHERE code_normalized IN (...)) and
 *   eager-loads every display relation, so the response is built without N+1.
 * - Source provenance stays in observations (ops); end-user API does not expose it.
 */
final class MovieLookupService
{
    public function normalize(string $rawCode): ?NormalizedCode
    {
        try {
            return NormalizedCode::from($rawCode);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function findByCode(string $rawCode): ?Movie
    {
        $normalized = $this->normalize($rawCode);

        if ($normalized === null) {
            return null;
        }

        $movieId = MovieCode::query()
            ->where('code_normalized', $normalized->value())
            ->value('movie_id');

        if (! is_numeric($movieId)) {
            return null;
        }

        $movie = $this->queryWithDisplayRelations()->whereKey((int) $movieId)->first();

        return $movie instanceof Movie ? $movie : null;
    }

    /**
     * Resolve every requested code to its movie in one batch.
     *
     * @param  list<string>  $rawCodes
     * @return array<string, Movie> keyed by normalized code value
     */
    public function findByCodes(array $rawCodes): array
    {
        $normalized = [];
        foreach ($rawCodes as $rawCode) {
            $code = $this->normalize($rawCode);
            if ($code !== null) {
                $normalized[$code->value()] = $code->value();
            }
        }

        if ($normalized === []) {
            return [];
        }

        $movieIds = MovieCode::query()
            ->whereIn('code_normalized', array_values($normalized))
            ->pluck('movie_id')
            ->unique()
            ->values()
            ->all();

        if ($movieIds === []) {
            return [];
        }

        $movies = $this->queryWithDisplayRelations()
            ->whereKey($movieIds)
            ->get()
            ->keyBy(static fn(Movie $movie): int => (int) $movie->id);

        $byCode = [];
        foreach ($movies as $movie) {
            foreach ($movie->codes as $code) {
                $byCode[(string) $code->code_normalized] = $movie;
            }
        }

        return $byCode;
    }

    /** @return Builder<Movie> */
    private function queryWithDisplayRelations(): Builder
    {
        return Movie::query()->with(['codes', 'performers.aliases', 'performers.sources', 'genres', 'media', 'credits']);
    }
}
