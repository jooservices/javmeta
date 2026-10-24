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
     *
     * @SuppressWarnings("PHPMD.ExcessiveMethodLength")
     */
    private function applyFilters(Builder $query, array $filters, ?string $q): void
    {
        if ($q !== null) {
            $this->applyTextSearch($query, $q);
        }

        $like = $this->likeOperator();

        $genres = self::list($filters['genre'] ?? null);
        if ($genres !== []) {
            $all = (string) ($filters['genre_all'] ?? '0') === '1';
            $one = static function (Builder $query, string $genre) use ($like): void {
                $pattern = '%' . self::escapeLike($genre) . '%';
                $query->whereHas('genres', static function (Builder $g) use ($like, $pattern): void {
                    $g->where('label_normalized', $like, $pattern)->orWhere('label_raw', $like, $pattern);
                });
            };
            if ($all) {
                foreach ($genres as $genre) {
                    $one($query, $genre);
                }
            } else {
                $query->where(static function (Builder $any) use ($genres, $one): void {
                    foreach ($genres as $genre) {
                        $any->orWhere(static function (Builder $w) use ($one, $genre): void {
                            $one($w, $genre);
                        });
                    }
                });
            }
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
            $query->whereIn('movies.id', self::movieIdsOfPerformers(self::performerIdsMatching($like, '%' . self::escapeLike($actress) . '%')));
        }

        // Exact performers (the picker): several ids mean movies they share.
        foreach (self::ids($filters['actress_id'] ?? null) as $performerId) {
            $query->whereIn('movies.id', DB::table('movie_performers')->select('movie_id')->where('performer_id', $performerId));
        }

        $source = $filters['source'] ?? null;
        if (is_string($source) && $source !== '') {
            $query->whereIn('movies.id', DB::table('movie_codes')->select('movie_id')->whereIn('source_slug', self::list($source)));
        }
        if (is_numeric($filters['score_min'] ?? null)) {
            $query->where('community_score', '>=', (float) $filters['score_min']);
        }
        if (is_numeric($filters['tier_min'] ?? null)) {
            $query->where('completeness_tier', '>=', (int) $filters['tier_min']);
        }
        if (isset($filters['has_cover'])) {
            (string) $filters['has_cover'] === '1'
                ? $query->whereNotNull('cover_url')->where('cover_url', '<>', '')
                : $query->where(static fn(Builder $w) => $w->whereNull('cover_url')->orWhere('cover_url', ''));
        }
        foreach (['has_magnet' => DB::table('movie_media')->select('movie_id')->where('kind', 'magnet'), 'has_performers' => DB::table('movie_performers')->select('movie_id')] as $flag => $sub) {
            if (isset($filters[$flag])) {
                (string) $filters[$flag] === '1' ? $query->whereIn('movies.id', $sub) : $query->whereNotIn('movies.id', $sub);
            }
        }

        foreach (['maker', 'series', 'label'] as $column) {
            $value = $filters[$column] ?? null;
            if (is_string($value) && $value !== '') {
                $query->where($column, $like, '%' . $value . '%');
            }
        }

        if (isset($filters['on_disk'])) {
            // A movie is on disk when a library file that is not missing was matched to it.
            $present = static fn($q) => $q->select('library_files.movie_id')->from('library_files')->whereNull('missing_at')->whereNotNull('movie_id');
            ((string) $filters['on_disk'] === '1') ? $query->whereIn('movies.id', $present) : $query->whereNotIn('movies.id', $present);
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

    /** @return list<string> comma separated values, trimmed and without blanks */
    private static function list(mixed $value): array
    {
        $parts = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_unique(array_filter(array_map(static fn($p): string => trim((string) $p), $parts), static fn(string $p): bool => $p !== '')));
    }

    /** @return list<int> */
    private static function ids(mixed $value): array
    {
        return array_values(array_unique(array_map('intval', array_filter(self::list($value), 'is_numeric'))));
    }

    private static function escapeLike(string $text): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $text);
    }

    /** The performers whose name, kana, kanji, alias or source name contains the text: one indexed branch per column. */
    private static function performerIdsMatching(string $like, string $pattern): \Illuminate\Database\Query\Builder
    {
        return DB::table('performers')->select('id')->where('name_romaji', $like, $pattern)
            ->union(DB::table('performers')->select('id')->where('name_kanji', $like, $pattern))
            ->union(DB::table('performers')->select('id')->where('name_kana', $like, $pattern))
            ->union(DB::table('performer_aliases')->select('performer_id')->where('alias', $like, $pattern))
            ->union(DB::table('performer_sources')->select('performer_id')->where('name_romaji', $like, $pattern)->orWhere('name_kanji', $like, $pattern)->orWhere('name_kana', $like, $pattern));
    }

    private static function movieIdsOfPerformers(\Illuminate\Database\Query\Builder $performerIds): \Illuminate\Database\Query\Builder
    {
        return DB::table('movie_performers')->select('movie_id')->whereIn('performer_id', $performerIds);
    }

    /**
     * Counts inside the current result: what the filtered movies have in common, to narrow the search one click at a time.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function facets(array $filters): array
    {
        $q = isset($filters['q']) && is_string($filters['q']) ? trim($filters['q']) : null;
        $query = $this->newQuery();
        $this->applyFilters($query, $filters, $q === '' ? null : $q);
        $ids = $query->toBase()->select('movies.id');
        $movieRows = static fn() => $filters === [] ? DB::table('movies') : DB::table('movies')->whereIn('id', clone $ids);

        $count = static fn($rows): array => $rows->map(static fn($r): array => ['name' => (string) $r->name, 'count' => (int) $r->c])->all();
        // Hash distinct movie/label pairs before counting: COUNT(DISTINCT ...) sorts the full joined catalog.
        $genrePairs = DB::table('movie_genres')->join('genres', 'genres.id', '=', 'movie_genres.genre_id')
            ->when($filters !== [], static fn($rows) => $rows->whereIn('movie_genres.movie_id', clone $ids))
            ->selectRaw('COALESCE(genres.label_raw, genres.label_normalized) AS name, movie_genres.movie_id')->distinct();
        $byColumn = static fn(string $column) => $count($movieRows()->whereNotNull($column)->where($column, '<>', '')->selectRaw($column . ' AS name, COUNT(*) AS c')->groupBy($column)->orderByDesc('c')->orderBy($column)->limit(15)->get());
        $year = DB::getDriverName() === 'sqlite' ? "CAST(strftime('%Y', release_date) AS INTEGER)" : 'EXTRACT(YEAR FROM release_date)::int';

        return [
            'total' => (int) $movieRows()->count(),
            'genres' => $count(DB::query()->fromSub($genrePairs, 'genre_pairs')->selectRaw('name, COUNT(*) AS c')->groupBy('name')->orderByDesc('c')->limit(20)->get()),
            'makers' => $byColumn('maker'),
            'series' => $byColumn('series'),
            'labels' => $byColumn('label'),
            'years' => $count($movieRows()->whereNotNull('release_date')->selectRaw($year . ' AS name, COUNT(*) AS c')->groupByRaw($year)->orderByDesc('name')->limit(15)->get()),
            'sources' => $count(DB::table('movie_codes')->when($filters !== [], static fn($rows) => $rows->whereIn('movie_id', clone $ids))->selectRaw('source_slug AS name, COUNT(DISTINCT movie_id) AS c')->groupBy('source_slug')->orderByDesc('c')->limit(15)->get()),
            'performers' => DB::table('movie_performers')->join('performers', 'performers.id', '=', 'movie_performers.performer_id')->when($filters !== [], static fn($rows) => $rows->whereIn('movie_performers.movie_id', clone $ids))->whereNull('performers.canonical_performer_id')
                ->selectRaw("performers.id, COALESCE(performers.name_romaji, performers.name_kanji, performers.name_kana) AS name, COUNT(DISTINCT movie_performers.movie_id) AS c")->groupBy('performers.id', 'performers.name_romaji', 'performers.name_kanji', 'performers.name_kana')->orderByDesc('c')->limit(12)->get()
                ->map(static fn($r): array => ['id' => (int) $r->id, 'name' => (string) $r->name, 'count' => (int) $r->c])->all(),
        ];
    }

    private function applyTextSearch(Builder $query, string $q): void
    {
        $like = $this->likeOperator();
        $pattern = '%' . $q . '%';

        $matching = DB::table('movies')->select('id')->where(static function ($text) use ($q, $pattern): void {
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

        });
        // A UNION lets the full-text and performer branches use their indexes independently.
        $matching->union(self::movieIdsOfPerformers(self::performerIdsMatching($like, $pattern)));
        $query->whereIn('movies.id', $matching);
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
