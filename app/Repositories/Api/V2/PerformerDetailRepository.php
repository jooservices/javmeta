<?php

declare(strict_types=1);

namespace App\Repositories\Api\V2;

use App\Services\Api\V2\Catalogue\SqlConditions;
use Illuminate\Support\Facades\DB;

/** Reads one performer (search table row plus the normalised source and media tables), the autocomplete and lookups. PostgreSQL only. */
final class PerformerDetailRepository
{
    private const FACET_COLUMNS = ['location' => 'location', 'cup' => 'cup', 'blood_type' => 'blood_type'];

    private const MOVIE_ORDER = [
        'release_date' => 'm.release_date',
        'score' => 'm.score',
        'maker' => 'm.maker',
        'code' => 'm.code',
    ];

    /** A canonical performer with the profile columns of the detail page and the live count of their movies. */
    public function find(int $id): ?object
    {
        return DB::selectOne(
            'SELECT ' . CatalogueRepository::PERFORMER_FIELDS . ', p.bio_text, p.attrs::text AS attrs, (SELECT count(*)::int FROM rm_movies m WHERE m.performer_ids @> ARRAY[p.id]) AS n_movies FROM rm_performers p WHERE p.id = ?',
            [$id],
        );
    }

    public function exists(int $id): bool
    {
        return DB::selectOne('SELECT 1 AS found FROM rm_performers WHERE id = ?', [$id]) !== null;
    }

    /**
     * Source records of a canonical performer: the source rows of the profile plus the per-source performer rows merged into it.
     *
     * @return list<object>
     */
    public function sources(int $id): array
    {
        $records = DB::select(
            'SELECT s.id, s.site_slug AS source, s.external_id, s.source_url AS url, s.name_romaji, s.name_kanji, s.birth_date, s.crawled_at, (s.removed_at IS NULL) AS active, s.removed_at'
            . ' FROM performer_sources s JOIN performers p ON p.id = s.performer_id WHERE s.performer_id = ? OR p.canonical_performer_id = ? ORDER BY s.site_slug, s.id',
            [$id, $id],
        );
        $merged = DB::select(
            'SELECT id, source_slug AS source, external_id, profile_url AS url, name_romaji, name_kanji, birth_date, crawled_at, true AS active, NULL AS removed_at'
            . ' FROM performers WHERE canonical_performer_id = ? OR id = ? ORDER BY source_slug, id',
            [$id, $id],
        );

        $known = [];

        foreach ($records as $record) {
            $known[$record->source . '|' . $record->url] = true;
        }

        return [...$records, ...array_filter($merged, static fn(object $row): bool => ! isset($known[$row->source . '|' . $row->url]))];
    }

    /** @return list<object> */
    public function photos(int $id): array
    {
        return DB::select(
            "SELECT pm.kind, pm.url, pm.source_slug AS source FROM performer_media pm JOIN performers p ON p.id = pm.performer_id WHERE (pm.performer_id = ? OR p.canonical_performer_id = ?) AND pm.kind IN ('image', 'gallery') ORDER BY pm.kind, pm.id LIMIT 200",
            [$id, $id],
        );
    }

    /**
     * Performers who share the most movies with this one.
     *
     * @return list<object>
     */
    public function coPerformers(int $id, int $limit): array
    {
        return DB::select(
            'SELECT ' . CatalogueRepository::PERFORMER_FIELDS . ', c.n AS together FROM (SELECT x AS pid, count(*)::int AS n FROM rm_movies m, unnest(m.performer_ids) x WHERE m.performer_ids @> ARRAY[?]::int[] AND x <> ? GROUP BY x ORDER BY n DESC LIMIT ' . $limit . ') c JOIN rm_performers p ON p.id = c.pid ORDER BY c.n DESC, p.id',
            [$id, $id],
        );
    }

    /** @return array{by_year: list<array{year: int, count: int}>, top_makers: list<array{name: string, count: int}>, top_genres: list<array{name: string, count: int}>, span: array{first: ?string, last: ?string, total: int}} */
    public function statistics(int $id): array
    {
        $years = DB::select('SELECT year, count(*)::int AS count FROM rm_movies WHERE performer_ids @> ARRAY[?]::int[] AND year IS NOT NULL GROUP BY year ORDER BY year', [$id]);
        $makers = DB::select('SELECT maker AS name, count(*)::int AS count FROM rm_movies WHERE performer_ids @> ARRAY[?]::int[] AND maker IS NOT NULL GROUP BY maker ORDER BY count DESC, maker LIMIT 8', [$id]);
        $genres = DB::select("SELECT g AS name, count(*)::int AS count FROM rm_movies m, unnest(m.genres) g WHERE m.performer_ids @> ARRAY[?]::int[] AND g <> '' GROUP BY g ORDER BY count DESC, g LIMIT 12", [$id]);
        $span = DB::selectOne('SELECT min(release_date) AS first, max(release_date) AS last, count(*)::int AS total FROM rm_movies WHERE performer_ids @> ARRAY[?]::int[]', [$id]);

        return [
            'by_year' => array_map(static fn(object $r): array => ['year' => (int) $r->year, 'count' => (int) $r->count], $years),
            'top_makers' => array_map(static fn(object $r): array => ['name' => (string) $r->name, 'count' => (int) $r->count], $makers),
            'top_genres' => array_map(static fn(object $r): array => ['name' => (string) $r->name, 'count' => (int) $r->count], $genres),
            'span' => ['first' => $span->first, 'last' => $span->last, 'total' => (int) $span->total],
        ];
    }

    /**
     * One page of the (not delisted) movies of a performer.
     *
     * @return array{rows: list<object>, total: int}
     */
    public function movies(int $id, string $sort, int $limit, int $offset): array
    {
        $descending = str_starts_with($sort, '-');
        $order = self::MOVIE_ORDER[ltrim($sort, '-')] . ($descending ? ' DESC' : ' ASC') . ' NULLS LAST, m.id DESC';
        $where = 'm.performer_ids @> ARRAY[?]::int[] AND NOT m.delisted';

        return [
            'rows' => DB::select('SELECT ' . CatalogueRepository::MOVIE_FIELDS . " FROM rm_movies m WHERE {$where} ORDER BY {$order} LIMIT {$limit} OFFSET {$offset}", [$id]),
            'total' => (int) DB::selectOne("SELECT count(*) AS n FROM rm_movies m WHERE {$where}", [$id])->n,
        ];
    }

    /**
     * @param list<int> $ids
     * @return list<object>
     */
    public function byIds(array $ids): array
    {
        return DB::select('SELECT ' . CatalogueRepository::PERFORMER_FIELDS . ' FROM rm_performers p WHERE p.id = ANY(?::int[])', ['{' . implode(',', $ids) . '}']);
    }

    /**
     * Values of one facet that contain the typed text, the most used first.
     *
     * @return list<array{value: string, count: int}>
     */
    public function facetValues(string $facet, string $term): array
    {
        $column = self::FACET_COLUMNS[$facet];
        $rows = DB::select(
            "SELECT {$column} AS value, count(*)::int AS count FROM rm_performers WHERE {$column} IS NOT NULL AND lower({$column}) LIKE ? GROUP BY {$column} ORDER BY count DESC, {$column} LIMIT 30",
            [SqlConditions::like($term)],
        );

        return array_map(static fn(object $row): array => ['value' => (string) $row->value, 'count' => (int) $row->count], $rows);
    }

    /** @return list<string> */
    public function facets(): array
    {
        return array_keys(self::FACET_COLUMNS);
    }
}
