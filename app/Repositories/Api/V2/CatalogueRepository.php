<?php

declare(strict_types=1);

namespace App\Repositories\Api\V2;

use App\Services\Api\V2\Catalogue\CatalogueFilters;
use App\Services\Api\V2\Catalogue\SqlConditions;
use Illuminate\Support\Facades\DB;

/** Reads the search tables (rm_movies, rm_performers, rm_perf_attr). PostgreSQL only. */
final class CatalogueRepository
{
    public const MOVIE_FIELDS = 'm.id, m.code, m.title_jp, m.title_en, m.release_date, m.runtime, m.censored, m.maker, m.label, m.series, m.score, m.tier, m.cover_url, m.cover_thumb_url, m.crawled_at, m.genres::text AS genres, m.performer_ids::text AS performer_ids, m.dl_count, m.hls_count, m.gallery_count, m.sources::text AS sources, m.needs_review, m.delisted';

    public const PERFORMER_FIELDS = 'p.id, p.name_romaji, p.name_kanji, p.name_kana, p.birth_date, p.height_cm, p.bust, p.waist, p.hip, p.cup, p.blood_type, p.blood_rh, p.location, p.movie_count, p.photo_url, p.photo_count, p.retired_date, p.debut_date, p.aliases::text AS aliases, p.crawled_at, p.first_seen_at, p.source_count';

    private const MOVIE_ORDER = [
        'release_date' => 'm.release_date DESC NULLS LAST',
        'update_date' => 'm.crawled_at DESC NULLS LAST',
        'rating' => 'm.score DESC NULLS LAST',
        'runtime' => 'm.runtime DESC NULLS LAST',
        'code' => 'm.code ASC',
    ];

    private const PERFORMER_ORDER = [
        'featured' => '(p.photo_url IS NOT NULL) DESC, p.movie_count DESC',
        'movies' => 'p.movie_count DESC',
        'name' => 'p.name_romaji ASC',
        'crawled' => 'p.crawled_at DESC NULLS LAST',
        'age' => 'p.birth_date DESC NULLS LAST, p.movie_count DESC',
        'debut' => 'p.first_seen_at DESC NULLS LAST',
    ];

    public function __construct(private readonly CatalogueFilters $filters) {}

    /**
     * @param array<string, mixed> $query
     * @return array{rows: list<object>, total: int}
     */
    public function movies(array $query, string $sort, int $limit, int $offset): array
    {
        $where = $this->filters->movies($query);
        $relevance = $sort === 'relevance' ? "(m.code_norm = upper(regexp_replace(?::text, '[^A-Za-z0-9]', '', 'g'))) DESC, m.release_date DESC NULLS LAST" : null;
        $order = $relevance ?? (self::MOVIE_ORDER[$sort] ?? self::MOVIE_ORDER['release_date']);
        $bindings = [...$where->bindings(), ...($relevance === null ? [] : [(string) ($query['q'] ?? '')])];

        return [
            'rows' => DB::select('SELECT ' . self::MOVIE_FIELDS . ' FROM rm_movies m WHERE ' . $where->sql() . " ORDER BY {$order}, m.id DESC LIMIT {$limit} OFFSET {$offset}", $bindings),
            'total' => (int) DB::selectOne('SELECT count(*) AS n FROM rm_movies m WHERE ' . $where->sql(), $where->bindings())->n,
        ];
    }

    /**
     * @param list<int> $ids
     * @return list<object>
     */
    public function performersByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::select('SELECT ' . self::PERFORMER_FIELDS . ' FROM rm_performers p WHERE p.id = ANY(?::int[])', [str_replace(['[', ']'], ['{', '}'], json_encode($ids, JSON_THROW_ON_ERROR))]);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{rows: list<object>, total: int}
     */
    public function performers(array $query, string $sort, int $limit, int $offset): array
    {
        if (($query['in_movies'] ?? null) === '1') {
            return $this->performersOfMovies($query, $sort, $limit, $offset);
        }

        $where = $this->filters->performers($query);
        $order = self::PERFORMER_ORDER[$sort] ?? self::PERFORMER_ORDER['featured'];

        return [
            'rows' => DB::select('SELECT ' . self::PERFORMER_FIELDS . ' FROM rm_performers p WHERE ' . $where->sql() . " ORDER BY {$order}, p.id LIMIT {$limit} OFFSET {$offset}", $where->bindings()),
            'total' => (int) DB::selectOne('SELECT count(*) AS n FROM rm_performers p WHERE ' . $where->sql(), $where->bindings())->n,
        ];
    }

    /**
     * Performers of the movies that match the movie filters, ranked by how many of those movies they are in.
     *
     * @param array<string, mixed> $query
     * @return array{rows: list<object>, total: int}
     */
    private function performersOfMovies(array $query, string $sort, int $limit, int $offset): array
    {
        $movies = $this->filters->movies(array_intersect_key($query, array_flip(CatalogueFilters::MOVIE_KEYS)));
        $performers = $this->filters->performers(array_diff_key(array_intersect_key($query, array_flip(CatalogueFilters::PERFORMER_KEYS)), array_flip(['q'])));
        $from = 'FROM (SELECT x AS pid, count(*)::int AS together FROM rm_movies m, unnest(m.performer_ids) x WHERE ' . $movies->sql() . ' GROUP BY x) c JOIN rm_performers p ON p.id = c.pid WHERE ' . $performers->sql();
        $bindings = [...$movies->bindings(), ...$performers->bindings()];
        $order = $sort === 'movies' ? 'c.together DESC, p.movie_count DESC' : (self::PERFORMER_ORDER[$sort] ?? 'c.together DESC, p.movie_count DESC');

        return [
            'rows' => DB::select('SELECT ' . self::PERFORMER_FIELDS . ", c.together {$from} ORDER BY {$order}, p.id LIMIT {$limit} OFFSET {$offset}", $bindings),
            'total' => (int) DB::selectOne("SELECT count(*) AS n {$from}", $bindings)->n,
        ];
    }

    private const RUNTIME_PRESETS = ['lt60' => ['runtime_min' => '', 'runtime_max' => '59'], 'mid' => ['runtime_min' => '60', 'runtime_max' => '120'], 'gt120' => ['runtime_min' => '121', 'runtime_max' => '']];

    /**
     * Every option counted with all OTHER filters applied (an option that would give nothing is simply absent).
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function movieFacets(array $query): array
    {
        $total = fn(array $over): int => $this->movieTotal(array_merge($query, $over));
        $enum = fn(string $key, array $values): array => array_combine($values, array_map(fn(string $v): int => $total([$key => $v]), $values));

        return [
            'genres' => $this->tally('rm_movies m, unnest(m.genres) g', 'g', $this->filters->movies($query, ['genre']), "AND g <> ''", 400),
            'genresNot' => $this->tally('rm_movies m, unnest(m.genres) g', 'g', $this->filters->movies($query, ['genre_not']), "AND g <> ''", 400),
            'makers' => $this->tally('rm_movies m', 'm.maker', $this->filters->movies($query, ['maker']), 'AND m.maker IS NOT NULL', 5000),
            'series' => $this->tally('rm_movies m', 'm.series', $this->filters->movies($query, ['series']), 'AND m.series IS NOT NULL', 5000),
            'labels' => $this->tally('rm_movies m', 'm.label', $this->filters->movies($query, ['label']), 'AND m.label IS NOT NULL', 3000),
            'sources' => $this->tally('rm_movies m, unnest(m.sources) s', 's', $this->filters->movies($query, ['source']), '', 40),
            'people' => $this->people($this->filters->movies($query, ['actress_id'])),
            'years' => $this->years($this->filters->movies($query, ['released_from', 'released_to'])),
            'enums' => [
                'censored' => $enum('censored', ['censored', 'uncensored']),
                'has_magnet' => $enum('has_magnet', ['1', '0']),
                'has_performers' => $enum('has_performers', ['1']),
                'has_watch' => $enum('has_watch', ['1']),
                'tier_min' => $enum('tier_min', ['2', '3']),
                'crawled_days' => $enum('crawled_days', ['1', '7', '30']),
            ],
            'runtime' => array_map(fn(array $over): int => $total($over), self::RUNTIME_PRESETS),
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function performerFacets(array $query): array
    {
        $total = fn(array $over): int => (int) $this->performerTotal(array_merge($query, $over));
        $pair = fn(string $key, array $values): array => array_combine($values, array_map(fn(string $v): int => $total([$key => $v]), $values));

        return [
            'cups' => $this->tally('rm_performers p', 'p.cup', $this->filters->performers($query, ['cup']), 'AND p.cup IS NOT NULL', 24),
            'blood' => $this->tally('rm_performers p', 'p.blood_type', $this->filters->performers($query, ['blood_type']), 'AND p.blood_type IS NOT NULL', 24),
            'locations' => $this->tally('rm_performers p', 'p.location', $this->filters->performers($query, ['location']), 'AND p.location IS NOT NULL', 80),
            'ages' => $this->ages($this->filters->performers($query, ['age_min', 'age_max'])),
            'enums' => ['status' => $pair('status', ['active', 'retired']), 'has_image' => $pair('has_image', ['1', '0']), 'has_bio' => $pair('has_bio', ['1', '0']), 'has_profile' => $pair('has_profile', ['1', '0'])],
        ];
    }

    /** @param array<string, mixed> $query */
    private function movieTotal(array $query): int
    {
        $where = $this->filters->movies($query);

        return (int) DB::selectOne('SELECT count(*) AS n FROM rm_movies m WHERE ' . $where->sql(), $where->bindings())->n;
    }

    /** @param array<string, mixed> $query */
    private function performerTotal(array $query): int
    {
        $where = $this->filters->performers($query);

        return (int) DB::selectOne('SELECT count(*) AS n FROM rm_performers p WHERE ' . $where->sql(), $where->bindings())->n;
    }

    /** @return list<array{name: string, n: int}> */
    private function tally(string $from, string $column, SqlConditions $where, string $extra, int $limit): array
    {
        return array_map(static fn(object $r): array => ['name' => (string) $r->name, 'n' => (int) $r->n], DB::select("SELECT {$column} AS name, count(*)::int AS n FROM {$from} WHERE " . $where->sql() . " {$extra} GROUP BY 1 ORDER BY n DESC, 1 LIMIT {$limit}", $where->bindings()));
    }

    /** @return list<array{id: int, name: string, n: int}> */
    private function people(SqlConditions $where): array
    {
        return array_map(static fn(object $r): array => ['id' => (int) $r->id, 'name' => (string) $r->name, 'n' => (int) $r->n], DB::select('SELECT c.pid AS id, p.name_romaji AS name, c.n FROM (SELECT x AS pid, count(*)::int AS n FROM rm_movies m, unnest(m.performer_ids) x WHERE ' . $where->sql() . ' GROUP BY x ORDER BY n DESC LIMIT 120) c JOIN rm_performers p ON p.id = c.pid ORDER BY c.n DESC', $where->bindings()));
    }

    /** @return array{0: int, 1: int}|null */
    private function years(SqlConditions $where): ?array
    {
        $r = DB::selectOne('SELECT min(m.year)::int AS lo, max(m.year)::int AS hi FROM rm_movies m WHERE ' . $where->sql() . ' AND m.year BETWEEN 1980 AND 2035', $where->bindings());

        return $r->lo === null ? null : [(int) $r->lo, (int) $r->hi];
    }

    /** @return array{0: int, 1: int}|null */
    private function ages(SqlConditions $where): ?array
    {
        $r = DB::selectOne("SELECT min(date_part('year', age(p.birth_date)))::int AS lo, max(date_part('year', age(p.birth_date)))::int AS hi FROM rm_performers p WHERE " . $where->sql() . " AND p.birth_date IS NOT NULL AND p.birth_date > current_date - interval '80 years'", $where->bindings());

        return $r->lo === null ? null : [(int) $r->lo, (int) $r->hi];
    }
}
