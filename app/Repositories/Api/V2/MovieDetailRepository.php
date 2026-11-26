<?php

declare(strict_types=1);

namespace App\Repositories\Api\V2;

use App\Services\Api\V2\Catalogue\SqlConditions;
use Illuminate\Support\Facades\DB;

/** Reads one movie (search table row plus the normalised detail tables) and the movie autocomplete and lookups. PostgreSQL only. */
final class MovieDetailRepository
{
    private const FACET_COLUMNS = ['maker' => 'maker', 'series' => 'series', 'label' => 'label'];

    /** A movie by its display code or by any spelling that normalises to it. */
    public function findByCode(string $code): ?object
    {
        $upper = mb_strtoupper($code);

        return DB::selectOne(
            'SELECT ' . CatalogueRepository::MOVIE_FIELDS . " FROM rm_movies m WHERE m.code = ? OR m.code_norm = upper(regexp_replace(?::text, '[^A-Za-z0-9]', '', 'g')) ORDER BY (m.code = ?) DESC LIMIT 1",
            [$upper, $upper, $upper],
        );
    }

    /** Detail-only columns kept in the normalised movies table. */
    public function extras(int $movieId): ?object
    {
        return DB::selectOne('SELECT description, attrs::text AS attrs, uuid::text AS uuid, delisted_at FROM movies WHERE id = ?', [$movieId]);
    }

    /** @return list<object> */
    public function codes(int $movieId): array
    {
        return DB::select('SELECT code, kind, source_slug, source_url, crawled_at FROM movie_codes WHERE movie_id = ? ORDER BY kind, code', [$movieId]);
    }

    /** @return list<object> */
    public function sources(int $movieId): array
    {
        return DB::select('SELECT site_slug AS source, source_url AS url, source_code AS code, crawled_at FROM movie_sources WHERE movie_id = ? ORDER BY site_slug', [$movieId]);
    }

    /** @return list<object> */
    public function media(int $movieId): array
    {
        return DB::select("SELECT kind, url, meta::text AS meta, source_slug AS source FROM movie_media WHERE movie_id = ? AND kind IN ('gallery', 'hls', 'image', 'sample') ORDER BY kind, id", [$movieId]);
    }

    /** @return list<object> */
    public function magnets(int $movieId): array
    {
        return DB::select("SELECT url, meta::text AS meta, source_slug AS source FROM movie_media WHERE movie_id = ? AND kind = 'magnet' ORDER BY id", [$movieId]);
    }

    /**
     * Movies of the same series or with a shared performer, the closest first.
     *
     * @return list<object>
     */
    public function related(int $movieId, int $limit): array
    {
        $score = 'CASE WHEN m.series IS NOT NULL AND m.series = s.series THEN 3 ELSE 0 END + CASE WHEN m.maker = s.maker THEN 1 ELSE 0 END'
            . ' + cardinality(ARRAY(SELECT unnest(m.performer_ids) INTERSECT SELECT unnest(s.performer_ids))) * 2'
            . ' + cardinality(ARRAY(SELECT unnest(m.genres) INTERSECT SELECT unnest(s.genres)))';

        return DB::select(
            'SELECT ' . CatalogueRepository::MOVIE_FIELDS . ", ({$score}) AS rel FROM rm_movies m, rm_movies s WHERE s.id = ? AND m.id <> s.id AND NOT m.delisted"
            . ' AND (m.performer_ids && s.performer_ids OR (m.series IS NOT NULL AND m.series = s.series)) ORDER BY rel DESC, m.release_date DESC NULLS LAST, m.id LIMIT ' . $limit,
            [$movieId],
        );
    }

    /**
     * Movies by code (case-insensitive, exact).
     *
     * @param list<string> $codes
     * @return list<object>
     */
    public function byCodes(array $codes): array
    {
        return DB::select('SELECT ' . CatalogueRepository::MOVIE_FIELDS . ' FROM rm_movies m WHERE m.code = ANY(?::text[])', [SqlConditions::pgArray(array_map(mb_strtoupper(...), $codes))]);
    }

    /**
     * Values of one facet that contain the typed text, the most used first.
     *
     * @return list<array{value: string, count: int}>
     */
    public function facetValues(string $facet, string $term, int $limit): array
    {
        $like = SqlConditions::like($term);

        $rows = $facet === 'genre'
            ? DB::select("SELECT g AS value, count(*)::int AS count FROM rm_movies m, unnest(m.genres) g WHERE g <> '' AND lower(g) LIKE ? GROUP BY g ORDER BY count DESC, g LIMIT {$limit}", [$like])
            : DB::select($this->columnQuery(self::FACET_COLUMNS[$facet], $limit), [$like]);

        return array_map(static fn(object $row): array => ['value' => (string) $row->value, 'count' => (int) $row->count], $rows);
    }

    private function columnQuery(string $column, int $limit): string
    {
        return "SELECT {$column} AS value, count(*)::int AS count FROM rm_movies WHERE {$column} IS NOT NULL AND lower({$column}) LIKE ? GROUP BY {$column} ORDER BY count DESC, {$column} LIMIT {$limit}";
    }

    /** @return list<string> the facets this repository can autocomplete */
    public function facets(): array
    {
        return ['genre', ...array_keys(self::FACET_COLUMNS)];
    }
}
