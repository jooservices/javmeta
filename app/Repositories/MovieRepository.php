<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Data\Facets\FacetListQuery;
use App\Models\Movie;
use App\Models\MovieCode;
use App\Models\MovieMedia;
use App\Models\MovieSource;
use App\Support\Catalog\NameScript;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use App\Support\Code\NormalizedCode;
use InvalidArgumentException;

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
final class MovieRepository extends BusinessQueryRepository
{
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
     * @return array{items: list<array{name: string, movie_count: int}>, total: int, hidden: int}
     *
     * Entries below $minCount movies are left out and counted in `hidden`; $script keeps one writing system (see NameScript).
     */
    public function movieCatalog(string $kind, string $query, string $sort, int $perPage, int $page, int $minCount = 0, string $script = 'all'): array
    {
        if ($script !== 'all') {
            $whole = $this->movieCatalogWhole($kind, $query, $sort, $minCount, $script);

            return [
                'items' => array_slice($whole['items'], ($page - 1) * $perPage, $perPage),
                'total' => count($whole['items']),
                'hidden' => $whole['hidden'],
            ];
        }

        [$base, $order] = $this->movieCatalogBase($kind, $query, $sort);
        $all = (int) DB::query()->fromSub(clone $base, 'c')->count();
        $visible = DB::query()->fromSub(clone $base, 'c')->when($minCount > 0, static fn($rows) => $rows->where('movie_count', '>=', $minCount));
        $total = $minCount > 0 ? (int) (clone $visible)->count() : $all;
        $rows = $visible->orderByRaw($order)->forPage($page, $perPage)->get(['name', 'movie_count']);

        /** @var list<array{name: string, movie_count: int}> $items */
        $items = $rows->map(static fn(object $row): array => ['name' => (string) $row->name, 'movie_count' => (int) $row->movie_count])->values()->all();

        return ['items' => $items, 'total' => $total, 'hidden' => $all - $total];
    }

    /**
     * The whole catalog of one writing system, ordered and without the entries below $minCount (counted in `hidden`).
     * The script is decided in PHP (there is no portable SQL for it), so every entry of the kind is read; the caller caches
     * the result independently of the page. Bounded by the number of distinct values of the kind (genres: about 13,400).
     *
     * @return array{items: list<array{name: string, movie_count: int}>, hidden: int}
     */
    public function movieCatalogWhole(string $kind, string $query, string $sort, int $minCount, string $script): array
    {
        [$base, $order] = $this->movieCatalogBase($kind, $query, $sort);
        $named = DB::query()->fromSub($base, 'c')->orderByRaw($order)->get(['name', 'movie_count'])
            ->filter(static fn(object $row): bool => NameScript::matches((string) $row->name, $script))->values();
        $kept = $named->filter(static fn(object $row): bool => (int) $row->movie_count >= $minCount)->values();

        /** @var list<array{name: string, movie_count: int}> $items */
        $items = $kept->map(static fn(object $row): array => ['name' => (string) $row->name, 'movie_count' => (int) $row->movie_count])->values()->all();

        return ['items' => $items, 'hidden' => $named->count() - $kept->count()];
    }

    /** @return array{0: \Illuminate\Database\Query\Builder, 1: string} the grouped query and its ORDER BY */
    private function movieCatalogBase(string $kind, string $query, string $sort): array
    {
        $nameColumn = match ($kind) {
            'genres' => 'COALESCE(genres.label_raw, genres.label_normalized)',
            'years' => $this->yearExpression(),
            default => $this->catalogColumn($kind),
        };
        $base = match ($kind) {
            'genres' => DB::table('genres')->join('movie_genres', 'movie_genres.genre_id', '=', 'genres.id')
                ->selectRaw($nameColumn . ' AS name, COUNT(DISTINCT movie_genres.movie_id) AS movie_count')
                ->groupByRaw($nameColumn),
            'years' => DB::table('movies')->whereNotNull('release_date')
                ->selectRaw($nameColumn . ' AS name, COUNT(*) AS movie_count')
                ->groupByRaw($nameColumn),
            default => DB::table('movies')->whereNotNull($nameColumn)->where($nameColumn, '<>', '')
                ->selectRaw($nameColumn . ' AS name, COUNT(*) AS movie_count')
                ->groupBy($nameColumn),
        };

        if ($query !== '') {
            $pattern = '%' . mb_strtolower(str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query)) . '%';
            $kind === 'years'
                ? $base->whereRaw('CAST(' . $nameColumn . ' AS TEXT) LIKE ?', [$pattern])
                : $base->whereRaw('LOWER(' . $nameColumn . ') LIKE ?', [$pattern]);
        }

        return [$base, $sort === 'name' ? ($kind === 'years' ? 'name DESC' : 'name ASC') : 'movie_count DESC, name ASC'];
    }

    public function countAll(): int
    {
        return $this->newQuery()->count();
    }

    public function downloadSearchIdByCode(string $rawCode): ?int
    {
        try {
            $code = NormalizedCode::from($rawCode);
        } catch (InvalidArgumentException) {
            return null;
        }

        $movieId = $this->modelQuery(MovieCode::class)
            ->where('code_normalized', $code->value())
            ->value('movie_id');

        return is_numeric($movieId) ? (int) $movieId : null;
    }

    public function downloadSearchDisplayCode(int $movieId): ?string
    {
        $code = $this->newQuery()->whereKey($movieId)->value('display_code');

        return is_string($code) && trim($code) !== '' ? trim($code) : null;
    }

    public function downloadSearchSourceUrl(int $movieId, string $sourceSlug): ?string
    {
        $url = $this->modelQuery(MovieSource::class)
            ->where('movie_id', $movieId)
            ->where('site_slug', $sourceSlug)
            ->value('source_url');

        return is_string($url) && trim($url) !== '' ? trim($url) : null;
    }

    public function downloadSearchMagnetCount(int $movieId): int
    {
        return $this->modelQuery(MovieMedia::class)
            ->where('movie_id', $movieId)
            ->where('kind', MovieMedia::KIND_MAGNET)
            ->count();
    }

    public function latestReleaseDate(): ?string
    {
        $latest = $this->tableQuery('movies')->whereNull('delisted_at')->whereNotNull('release_date')->max('release_date');

        return is_string($latest) ? $latest : null;
    }

    /**
     * @return list<array{
     *     release_date: string,
     *     display_code: ?string,
     *     title_en: ?string,
     *     title_jp: ?string,
     *     cover_thumb_url: ?string,
     *     cover_url: ?string,
     *     maker: ?string
     * }>
     */
    public function releaseCalendarRows(string $month): array
    {
        $from = new DateTimeImmutable($month . '-01');
        $until = $from->modify('+1 month')->format('Y-m-d');
        $rows = $this->tableQuery('movies')
            ->where('release_date', '>=', $from->format('Y-m-d'))
            ->where('release_date', '<', $until)
            ->whereNull('delisted_at')
            ->orderBy('release_date')
            ->orderByDesc('community_score')
            ->orderBy('id')
            ->get(['release_date', 'display_code', 'title_en', 'title_jp', 'cover_thumb_url', 'cover_url', 'maker']);

        return $rows->map(static fn(object $row): array => [
            'release_date' => (string) $row->release_date,
            'display_code' => $row->display_code === null ? null : (string) $row->display_code,
            'title_en' => $row->title_en === null ? null : (string) $row->title_en,
            'title_jp' => $row->title_jp === null ? null : (string) $row->title_jp,
            'cover_thumb_url' => $row->cover_thumb_url === null ? null : (string) $row->cover_thumb_url,
            'cover_url' => $row->cover_url === null ? null : (string) $row->cover_url,
            'maker' => $row->maker === null ? null : (string) $row->maker,
        ])->all();
    }

    public function countFirstSeenSince(DateTimeInterface $since): int
    {
        return $this->newQuery()->where('first_seen_at', '>=', $since)->count();
    }

    public function libraryMovieByCode(string $normalizedCode): ?object
    {
        return DB::table('movie_codes')
            ->join('movies', 'movies.id', '=', 'movie_codes.movie_id')
            ->where('movie_codes.code_normalized', $normalizedCode)
            ->first(['movies.id', 'movies.display_code']);
    }

    private function catalogColumn(string $kind): string
    {
        return match ($kind) {
            'makers' => 'maker',
            'labels' => 'label',
            default => 'series',
        };
    }

    private function yearExpression(): string
    {
        return DB::getDriverName() === 'sqlite' ? "CAST(strftime('%Y', release_date) AS INTEGER)" : 'EXTRACT(YEAR FROM release_date)::int';
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
                [$exact, $text] = self::exactTerm($genre);
                $query->whereHas('genres', static function (Builder $g) use ($like, $exact, $text): void {
                    if ($exact) {
                        $g->whereRaw('LOWER(label_normalized) = ?', [mb_strtolower($text)])->orWhereRaw('LOWER(label_raw) = ?', [mb_strtolower($text)]);

                        return;
                    }
                    $pattern = '%' . self::escapeLike($text) . '%';
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
                [$exact, $text] = self::exactTerm($value);
                $exact
                    ? $query->whereRaw('LOWER(' . $column . ') = ?', [mb_strtolower($text)])
                    : $query->where($column, $like, '%' . $value . '%');
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

    /**
     * A value wrapped in double quotes is an exact match (what a facet tick sends, so the movies found equal the count
     * shown next to the value); any other value keeps the substring search every filter has always had.
     *
     * @return array{0: bool, 1: string}
     */
    private static function exactTerm(string $term): array
    {
        if (strlen($term) >= 2 && $term[0] === '"' && str_ends_with($term, '"')) {
            return [true, substr($term, 1, -1)];
        }

        return [false, $term];
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

    /**
     * One paginated facet value list scoped to the current movie filters.
     *
     * @return array{items: list<array<string, mixed>>, total: int, hidden_below_min: int, facet: string}
     */
    public function facetValues(FacetListQuery $query): array
    {
        $filters = $query->filters;
        $movieQuery = $this->newQuery();
        $q = isset($filters['q']) && is_string($filters['q']) ? trim($filters['q']) : null;
        $this->applyFilters($movieQuery, $filters, $q === '' ? null : $q);
        $ids = $movieQuery->toBase()->select('movies.id');
        $scoped = $filters !== [];

        $rows = match ($query->facet) {
            'genres' => $this->genreFacetPairs($scoped ? $ids : null),
            'makers' => $this->columnFacetPairs('maker', $scoped ? $ids : null),
            'series' => $this->columnFacetPairs('series', $scoped ? $ids : null),
            'labels' => $this->columnFacetPairs('label', $scoped ? $ids : null),
            'years' => $this->yearFacetPairs($scoped ? $ids : null),
            'sources' => $this->sourceFacetPairs($scoped ? $ids : null),
            'performers' => $this->performerFacetPairs($scoped ? $ids : null),
            default => throw new InvalidArgumentException('Unknown movie facet: ' . $query->facet),
        };

        $needle = mb_strtolower($query->query);
        $matched = [];
        $hidden = 0;
        foreach ($rows as $row) {
            $name = (string) $row['name'];
            $count = (int) $row['count'];
            if ($needle !== '' && ! str_contains(mb_strtolower($name), $needle)) {
                continue;
            }
            if ($count < $query->minCount) {
                $hidden++;
                continue;
            }
            $matched[] = $row;
        }

        usort($matched, static function (array $left, array $right) use ($query): int {
            if ($query->sort === 'name') {
                return strcmp((string) $left['name'], (string) $right['name'])
                    ?: ((int) $right['count'] <=> (int) $left['count']);
            }

            return ((int) $right['count'] <=> (int) $left['count'])
                ?: strcmp((string) $left['name'], (string) $right['name']);
        });

        $total = count($matched);
        $page = max(1, $query->page);
        $items = array_slice($matched, ($page - 1) * $query->perPage, $query->perPage);

        return [
            'items' => $items,
            'total' => $total,
            'hidden_below_min' => $hidden,
            'facet' => $query->facet,
        ];
    }

    /** @return list<array{name: string, count: int}> */
    private function genreFacetPairs(?\Illuminate\Database\Query\Builder $ids): array
    {
        $pairs = DB::table('movie_genres')->join('genres', 'genres.id', '=', 'movie_genres.genre_id')
            ->when($ids !== null, static fn($rows) => $rows->whereIn('movie_genres.movie_id', clone $ids))
            ->selectRaw('COALESCE(genres.label_raw, genres.label_normalized) AS name, movie_genres.movie_id')->distinct();

        return DB::query()->fromSub($pairs, 'genre_pairs')->selectRaw('name, COUNT(*) AS c')->groupBy('name')->get()
            ->map(static fn($r): array => ['name' => (string) $r->name, 'count' => (int) $r->c])->all();
    }

    /** @return list<array{name: string, count: int}> */
    private function columnFacetPairs(string $column, ?\Illuminate\Database\Query\Builder $ids): array
    {
        $base = $ids === null
            ? DB::table('movies')
            : DB::table('movies')->whereIn('id', clone $ids);

        return $base->whereNotNull($column)->where($column, '<>', '')
            ->selectRaw($column . ' AS name, COUNT(*) AS c')->groupBy($column)->get()
            ->map(static fn($r): array => ['name' => (string) $r->name, 'count' => (int) $r->c])->all();
    }

    /** @return list<array{name: string, count: int}> */
    private function yearFacetPairs(?\Illuminate\Database\Query\Builder $ids): array
    {
        $year = $this->yearExpression();
        $base = $ids === null
            ? DB::table('movies')
            : DB::table('movies')->whereIn('id', clone $ids);

        return $base->whereNotNull('release_date')
            ->selectRaw($year . ' AS name, COUNT(*) AS c')->groupByRaw($year)->get()
            ->map(static fn($r): array => ['name' => (string) $r->name, 'count' => (int) $r->c])->all();
    }

    /** @return list<array{name: string, count: int}> */
    private function sourceFacetPairs(?\Illuminate\Database\Query\Builder $ids): array
    {
        return DB::table('movie_codes')
            ->when($ids !== null, static fn($rows) => $rows->whereIn('movie_id', clone $ids))
            ->selectRaw('source_slug AS name, COUNT(DISTINCT movie_id) AS c')->groupBy('source_slug')->get()
            ->map(static fn($r): array => ['name' => (string) $r->name, 'count' => (int) $r->c])->all();
    }

    /** @return list<array{id: int, name: string, count: int}> */
    private function performerFacetPairs(?\Illuminate\Database\Query\Builder $ids): array
    {
        return DB::table('movie_performers')->join('performers', 'performers.id', '=', 'movie_performers.performer_id')
            ->when($ids !== null, static fn($rows) => $rows->whereIn('movie_performers.movie_id', clone $ids))
            ->whereNull('performers.canonical_performer_id')
            ->selectRaw("performers.id, COALESCE(performers.name_romaji, performers.name_kanji, performers.name_kana) AS name, COUNT(DISTINCT movie_performers.movie_id) AS c")
            ->groupBy('performers.id', 'performers.name_romaji', 'performers.name_kanji', 'performers.name_kana')
            ->get()
            ->map(static fn($r): array => ['id' => (int) $r->id, 'name' => (string) $r->name, 'count' => (int) $r->c])->all();
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

        if ($sort === 'release_date' && DB::getDriverName() === 'pgsql') {
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
