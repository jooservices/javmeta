<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Movie;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use JOOservices\LaravelRepository\Contracts\RepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasCrud;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;

/**
 * Movie search (REQ-4, AD-11): filtered, sorted, keyset-paginated reads.
 *
 * - Text search uses the generated `search_vector` tsvector with
 *   `plainto_tsquery('simple', ?)` on PostgreSQL (chosen over
 *   `websearch_to_tsquery` because plainto never raises on arbitrary user
 *   input, so a hostile q cannot 500 the endpoint). The SQLite test driver
 *   has no tsvector, so it falls back to substring LIKE over the same
 *   columns that feed the generated vector (title/code) — equivalent token
 *   coverage, documented tradeoff.
 * - Keyset pagination: WHERE (sort_key, id) < (:last_sort, :last_id) with an
 *   id tie-break, no OFFSET (AD-11). Nullable sort keys follow the driver's
 *   NULL ordering (PostgreSQL orders NULLs first on plain DESC; SQLite orders
 *   them last; rating uses an explicit NULLS LAST to match its index).
 * - Sorts map to the composite indexes on PostgreSQL:
 *   (release_date DESC, id DESC), (updated_at DESC, id DESC) and
 *   (community_score DESC NULLS LAST, id DESC).
 */
final class MovieRepository extends EloquentRepository implements RepositoryInterface
{
    use HasCrud;
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;

    /** @var array<string, string> cursor sort => movies column */
    private const SORT_COLUMNS = [
        'release_date' => 'release_date',
        'update_date' => 'updated_at',
        'rating' => 'community_score',
    ];

    public function __construct(Movie $model)
    {
        parent::__construct($model);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array{sort: string, last_sort: int|float|string|null, last_id: int}|null  $cursor
     * @return array{
     *     items: Collection<int, Movie>,
     *     total: int,
     *     sort: string,
     *     last_sort: int|float|string|null,
     *     last_id: int|null,
     *     has_more: bool
     * }
     */
    public function search(array $filters, string $sort, ?array $cursor, int $perPage): array
    {
        $q = isset($filters['q']) && is_string($filters['q']) ? trim($filters['q']) : null;
        $q = $q === '' ? null : $q;

        // Relevance has nothing to rank without a query term; release_date is
        // the deterministic fallback so a bare /movies call still sorts well.
        $effectiveSort = $sort === 'relevance' && $q === null ? 'release_date' : $sort;

        $query = $this->newQuery()->with(['codes', 'performers.aliases', 'performers.sources', 'genres', 'media']);
        $this->applyFilters($query, $filters, $q);
        $total = $query->count();

        $this->applySort($query, $effectiveSort, $q);

        if ($cursor !== null) {
            $this->applyCursor($query, $effectiveSort, $cursor['last_sort'], $cursor['last_id'], $q);
        }

        /** @var Collection<int, Movie> $page */
        $page = $query->limit($perPage + 1)->get();

        $hasMore = $page->count() > $perPage;
        $items = $hasMore ? $page->slice(0, $perPage)->values() : $page;
        $last = $items->last();

        return [
            'items' => $items,
            'total' => $total,
            'sort' => $effectiveSort,
            'last_sort' => $last instanceof Movie ? $this->sortValue($last, $effectiveSort) : null,
            'last_id' => $last instanceof Movie ? (int) $last->id : null,
            'has_more' => $hasMore,
        ];
    }

    /**
     * Accurate total for a filter set without hydrating items (Postgres path).
     *
     * @param  array<string, mixed>  $filters
     */
    public function countFiltered(array $filters): int
    {
        $q = isset($filters['q']) && is_string($filters['q']) ? trim($filters['q']) : null;
        $q = $q === '' ? null : $q;

        $query = $this->newQuery();
        $this->applyFilters($query, $filters, $q);

        return $query->count();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters, ?string $q): void
    {
        if ($q !== null) {
            $this->applyTextSearch($query, $q);
        }

        $like = $this->likeOperator();

        $genre = $filters['genre'] ?? null;
        if (is_string($genre) && $genre !== '') {
            $pattern = '%' . $genre . '%';
            $query->whereHas('genres', static function (Builder $genres) use ($like, $pattern): void {
                $genres->where('label_normalized', $like, $pattern)
                    ->orWhere('label_raw', $like, $pattern);
            });
        }

        $code = $filters['code'] ?? null;
        if (is_string($code) && $code !== '') {
            $pattern = '%' . $code . '%';
            $query->where(static function (Builder $movies) use ($like, $pattern): void {
                $movies->where('display_code', $like, $pattern)
                    ->orWhere('code_normalized', $like, $pattern)
                    ->orWhereHas('codes', static function (Builder $codes) use ($like, $pattern): void {
                        $codes->where('code', $like, $pattern)
                            ->orWhere('code_normalized', $like, $pattern);
                    });
            });
        }

        $actress = $filters['actress'] ?? null;
        if (is_string($actress) && $actress !== '') {
            $pattern = '%' . $actress . '%';
            $query->whereHas('performers', static function (Builder $performers) use ($like, $pattern): void {
                $performers->where(static function (Builder $names) use ($like, $pattern): void {
                    $names->where('name_romaji', $like, $pattern)
                        ->orWhere('name_kanji', $like, $pattern)
                        ->orWhere('name_kana', $like, $pattern)
                        ->orWhereHas('aliases', static function (Builder $aliases) use ($like, $pattern): void {
                            $aliases->where('alias', $like, $pattern);
                        });
                });
            });
        }

        foreach (['maker', 'series', 'label'] as $column) {
            $value = $filters[$column] ?? null;
            if (is_string($value) && $value !== '') {
                $query->where($column, $like, '%' . $value . '%');
            }
        }

        $releasedFrom = $filters['released_from'] ?? null;
        if (is_string($releasedFrom) && $releasedFrom !== '') {
            $query->where('release_date', '>=', $releasedFrom);
        }

        $releasedTo = $filters['released_to'] ?? null;
        if (is_string($releasedTo) && $releasedTo !== '') {
            $query->where('release_date', '<=', $releasedTo);
        }

        $runtimeMin = $filters['runtime_min'] ?? null;
        if (is_numeric($runtimeMin)) {
            $query->where('runtime_minutes', '>=', (int) $runtimeMin);
        }

        $runtimeMax = $filters['runtime_max'] ?? null;
        if (is_numeric($runtimeMax)) {
            $query->where('runtime_minutes', '<=', (int) $runtimeMax);
        }

        $censored = $filters['censored'] ?? null;
        if ($censored !== null) {
            $query->where('censored', (int) $censored);
        }
    }

    private function applyTextSearch(Builder $query, string $q): void
    {
        $like = $this->likeOperator();
        $pattern = '%' . $q . '%';

        $query->where(static function (Builder $text) use ($q, $like, $pattern): void {
            if (DB::getDriverName() === 'pgsql') {
                // Title/code via generated tsvector; performers are not in that
                // column, so OR the same name/alias match used by `actress=`.
                $text->whereRaw("search_vector @@ plainto_tsquery('simple', ?)", [$q]);
            } else {
                $text->where('title_jp', 'like', $pattern)
                    ->orWhere('title_en', 'like', $pattern)
                    ->orWhere('display_code', 'like', $pattern)
                    ->orWhere('code_normalized', 'like', $pattern);
            }

            $text->orWhereHas('performers', static function (Builder $performers) use ($like, $pattern): void {
                $performers->where(static function (Builder $names) use ($like, $pattern): void {
                    $names->where('name_romaji', $like, $pattern)
                        ->orWhere('name_kanji', $like, $pattern)
                        ->orWhere('name_kana', $like, $pattern)
                        ->orWhereHas('aliases', static function (Builder $aliases) use ($like, $pattern): void {
                            $aliases->where('alias', $like, $pattern);
                        });
                });
            });
        });
    }

    private function applySort(Builder $query, string $sort, ?string $q): void
    {
        if ($sort === 'relevance') {
            if (DB::getDriverName() === 'pgsql' && $q !== null) {
                $query->select('movies.*')->selectRaw(
                    "ts_rank(search_vector, plainto_tsquery('simple', ?)) AS relevance_rank",
                    [$q],
                );
                $query->orderByRaw("ts_rank(search_vector, plainto_tsquery('simple', ?)) DESC, id DESC", [$q]);
            } else {
                // No ts_rank on the test driver: deterministic id order.
                $query->orderByDesc('id');
            }

            return;
        }

        $column = self::SORT_COLUMNS[$sort];

        if ($sort === 'rating') {
            // NULLS LAST matches the composite index and keeps unknown scores last.
            $query->orderByRaw("{$column} DESC NULLS LAST, id DESC");

            return;
        }

        $query->orderByDesc($column)->orderByDesc('id');
    }

    private function applyCursor(Builder $query, string $sort, mixed $lastSort, int $lastId, ?string $q): void
    {
        if ($sort === 'relevance') {
            if (DB::getDriverName() === 'pgsql' && $q !== null) {
                $this->applyRankCursor($query, $q, $lastSort, $lastId);
            } else {
                $query->where('id', '<', $lastId);
            }

            return;
        }

        $column = self::SORT_COLUMNS[$sort];

        if ($sort === 'update_date') {
            // updated_at is NOT NULL in the schema: no NULL handling needed.
            $query->where(static function (Builder $next) use ($column, $lastSort, $lastId): void {
                $next->where($column, '<', $lastSort)
                    ->orWhere(static function (Builder $tie) use ($column, $lastSort, $lastId): void {
                        $tie->where($column, '=', $lastSort)->where('id', '<', $lastId);
                    });
            });

            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            // PostgreSQL orders NULLs first on plain DESC (matches its index).
            $this->applyNullsFirstCursor($query, $column, $lastSort, $lastId);

            return;
        }

        // SQLite orders NULLs last on DESC; rating uses NULLS LAST on both.
        $this->applyNullsLastCursor($query, $column, $lastSort, $lastId);
    }

    private function applyRankCursor(Builder $query, string $q, mixed $lastSort, int $lastId): void
    {
        $query->where(static function (Builder $next) use ($q, $lastSort, $lastId): void {
            $next->whereRaw("ts_rank(search_vector, plainto_tsquery('simple', ?)) < ?", [$q, $lastSort])
                ->orWhere(static function (Builder $tie) use ($q, $lastSort, $lastId): void {
                    $tie->whereRaw("ts_rank(search_vector, plainto_tsquery('simple', ?)) = ?", [$q, $lastSort])
                        ->where('id', '<', $lastId);
                });
        });
    }

    private function applyNullsFirstCursor(Builder $query, string $column, mixed $lastSort, int $lastId): void
    {
        if ($lastSort === null) {
            // Cursor sat on a NULL row: the remaining NULLs (smaller id), then
            // every non-NULL row.
            $query->where(static function (Builder $next) use ($column, $lastId): void {
                $next->whereNull($column)->where('id', '<', $lastId)
                    ->orWhereNotNull($column);
            });

            return;
        }

        $query->where(static function (Builder $next) use ($column, $lastSort, $lastId): void {
            $next->where($column, '<', $lastSort)
                ->orWhere(static function (Builder $tie) use ($column, $lastSort, $lastId): void {
                    $tie->where($column, '=', $lastSort)->where('id', '<', $lastId);
                });
        });
    }

    private function applyNullsLastCursor(Builder $query, string $column, mixed $lastSort, int $lastId): void
    {
        if ($lastSort === null) {
            $query->whereNull($column)->where('id', '<', $lastId);

            return;
        }

        $query->where(static function (Builder $next) use ($column, $lastSort, $lastId): void {
            $next->whereNull($column)
                ->orWhere($column, '<', $lastSort)
                ->orWhere(static function (Builder $tie) use ($column, $lastSort, $lastId): void {
                    $tie->where($column, '=', $lastSort)->where('id', '<', $lastId);
                });
        });
    }

    private function sortValue(Movie $movie, string $sort): int|float|string|null
    {
        return match ($sort) {
            'relevance' => $this->scalarOrNull($movie->getAttribute('relevance_rank')),
            'release_date' => $this->formatDate($movie->getAttribute('release_date')),
            'update_date' => $this->formatDateTime($movie->getAttribute('updated_at')),
            'rating' => $this->scalarOrNull($movie->getAttribute('community_score')),
            default => null,
        };
    }

    private function scalarOrNull(mixed $value): int|float|string|null
    {
        if (is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }

        return null;
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return $this->scalarOrNull($value);
    }

    /**
     * The cursor stores the exact text the driver persisted, so the equality
     * branch of the keyset WHERE matches: PostgreSQL keeps microseconds,
     * SQLite stores second precision.
     */
    private function formatDateTime(mixed $value): ?string
    {
        if (! $value instanceof DateTimeInterface) {
            return $this->scalarOrNull($value);
        }

        return DB::getDriverName() === 'pgsql'
            ? $value->format('Y-m-d H:i:s.u')
            : $value->format('Y-m-d H:i:s');
    }

    private function likeOperator(): string
    {
        return DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }
}
