<?php

declare(strict_types=1);

namespace App\Services\Movies;

use App\Data\Api\MovieMediaDto;
use App\Repositories\MovieRepository;
use App\Models\Movie;
use App\Models\MovieCode;
use App\Models\MovieMedia;
use App\Support\Code\NormalizedCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * Reads movies by code for the lookup and bulk endpoints (REQ-3/REQ-6).
 *
 * - Codes are normalized with the NormalizedCode VO (BR-9) before matching
 *   against movie_codes.code_normalized, so ssis001 / SSIS-1 / " ssis-001 "
 *   all resolve to the same movie (AC-3.2) while prefix collisions stay
 *   distinct (FC2PPV1234567 != FC21234567).
 * - Bulk lookup issues one batch query (WHERE code_normalized IN (...)) and
 *   loads only summary relations, keeping large media collections out of memory.
 * - Source provenance stays in observations (ops); end-user API does not expose it.
 */
final class MovieLookupService
{
    public function __construct(private readonly MovieRepository $movies) {}

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

        $movieId = $this->movies->modelQuery(MovieCode::class)
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

        $movieIds = $this->movies->modelQuery(MovieCode::class)
            ->whereIn('code_normalized', array_values($normalized))
            ->pluck('movie_id')
            ->unique()
            ->values()
            ->all();

        if ($movieIds === []) {
            return [];
        }

        $movies = $this->queryWithSummaryRelations()
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

    /** @return array{items: list<MovieMediaDto>, total: int, page: int, per_page: int, last_page: int, has_more: bool}|null */
    public function mediaPageByCode(string $rawCode, string $kind, int $perPage, int $page): ?array
    {
        $normalized = $this->normalize($rawCode);
        if ($normalized === null) {
            return null;
        }

        $movieId = $this->movies->movieIdByNormalizedCode($normalized->value());
        if ($movieId === null) {
            return null;
        }

        return $this->movies->mediaPage($movieId, $kind, $perPage, $page);
    }

    /** @return Builder<Movie> */
    private function queryWithDisplayRelations(): Builder
    {
        return $this->movies->modelQuery(Movie::class)
            ->with([
                'codes',
                'performers.aliases',
                'performers.sources',
                'genres',
                'magnetMedia' => static fn(HasMany $query) => $query->orderBy('id')->limit(100),
                'hlsMedia' => static fn(HasMany $query) => $query->orderBy('id')->limit(100),
                'sampleMedia' => static fn(HasMany $query) => $query->orderBy('id')->limit(100),
                'galleryPreview' => static fn(HasMany $query) => $query->orderBy('id')->limit(100),
                'coverMedia',
                'credits',
            ])
            ->withCount([
                'media as gallery_total' => static fn(Builder $query) => $query->where('kind', MovieMedia::KIND_GALLERY),
                'media as magnet_total' => static fn(Builder $query) => $query->where('kind', MovieMedia::KIND_MAGNET),
                'media as hls_total' => static fn(Builder $query) => $query->where('kind', MovieMedia::KIND_HLS),
                'media as sample_total' => static fn(Builder $query) => $query->where('kind', MovieMedia::KIND_SAMPLE),
            ]);
    }

    /** @return Builder<Movie> */
    private function queryWithSummaryRelations(): Builder
    {
        return $this->movies->modelQuery(Movie::class)
            ->with(['codes', 'performers.aliases', 'performers.sources', 'genres', 'coverMedia']);
    }
}
