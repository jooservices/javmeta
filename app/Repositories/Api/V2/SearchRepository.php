<?php

declare(strict_types=1);

namespace App\Repositories\Api\V2;

use App\Services\Api\V2\Catalogue\CatalogueFilters;
use App\Services\Api\V2\Catalogue\SqlConditions;
use Illuminate\Support\Facades\DB;

/** Text search over the search tables (rm_movies, rm_performers). PostgreSQL only. */
final class SearchRepository
{
    public function __construct(private readonly CatalogueFilters $filters) {}

    /**
     * @return array{rows: list<object>, total: int}
     */
    public function movies(string $text, int $limit, int $offset): array
    {
        $like = SqlConditions::like($text);
        $norm = $this->codeNorm($text);
        $where = 'NOT m.delisted AND (m.search_text ILIKE ? OR m.code_norm = ?)';

        return [
            'rows' => DB::select('SELECT ' . CatalogueRepository::MOVIE_FIELDS . " FROM rm_movies m WHERE {$where} ORDER BY (m.code_norm = ?) DESC, m.release_date DESC NULLS LAST, m.id DESC LIMIT {$limit} OFFSET {$offset}", [$like, $norm, $norm]),
            'total' => (int) DB::selectOne("SELECT count(*) AS n FROM rm_movies m WHERE {$where}", [$like, $norm])->n,
        ];
    }

    /**
     * @return array{rows: list<object>, total: int}
     */
    public function performers(string $text, int $limit, int $offset): array
    {
        $like = SqlConditions::like($text);

        return [
            'rows' => DB::select('SELECT ' . CatalogueRepository::PERFORMER_FIELDS . " FROM rm_performers p WHERE p.search_text LIKE ? ORDER BY (p.photo_url IS NOT NULL) DESC, p.movie_count DESC, p.id LIMIT {$limit} OFFSET {$offset}", [$like]),
            'total' => (int) DB::selectOne('SELECT count(*) AS n FROM rm_performers p WHERE p.search_text LIKE ?', [$like])->n,
        ];
    }

    /**
     * Performers that have a photo and match the text.
     *
     * @return array{rows: list<object>, total: int}
     */
    public function photos(string $text, int $limit, int $offset): array
    {
        $like = SqlConditions::like($text);
        $where = 'p.search_text LIKE ? AND p.photo_url IS NOT NULL';

        return [
            'rows' => DB::select("SELECT p.id, p.name_romaji, p.photo_url FROM rm_performers p WHERE {$where} ORDER BY p.movie_count DESC, p.id LIMIT {$limit} OFFSET {$offset}", [$like]),
            'total' => (int) DB::selectOne("SELECT count(*) AS n FROM rm_performers p WHERE {$where}", [$like])->n,
        ];
    }

    /**
     * Covers of the movies that match the movie-side filters (performer filters are ignored: they act on people).
     *
     * @param array<string, mixed> $query
     * @return array{rows: list<object>, total: int}
     */
    public function covers(array $query, int $limit, int $offset): array
    {
        $where = $this->filters->movies($query, CatalogueFilters::PERFORMER_KEYS);

        return [
            'rows' => DB::select('SELECT m.code, m.cover_url, m.cover_thumb_url, m.gallery_count FROM rm_movies m WHERE ' . $where->sql() . " AND m.cover_url IS NOT NULL ORDER BY m.release_date DESC NULLS LAST, m.id DESC LIMIT {$limit} OFFSET {$offset}", $where->bindings()),
            'total' => (int) DB::selectOne('SELECT count(*) AS n FROM rm_movies m WHERE ' . $where->sql() . ' AND m.cover_url IS NOT NULL', $where->bindings())->n,
        ];
    }

    /**
     * Photographed performers of the movies that match the movie-side filters, most prolific first.
     *
     * @param array<string, mixed> $query
     * @return list<object>
     */
    public function cast(array $query, int $limit): array
    {
        $where = $this->filters->movies($query, CatalogueFilters::PERFORMER_KEYS);

        return DB::select('SELECT p.id, p.name_romaji, p.photo_url FROM rm_performers p WHERE p.photo_url IS NOT NULL AND p.id IN (SELECT unnest(m.performer_ids) FROM rm_movies m WHERE ' . $where->sql() . " LIMIT 400) ORDER BY p.movie_count DESC, p.id LIMIT {$limit}", $where->bindings());
    }

    /** The closest performer name or movie code to a text that found nothing, or null. */
    public function closest(string $text): ?string
    {
        $lower = mb_strtolower($text);
        $row = DB::selectOne(
            '(SELECT name_romaji AS s, similarity(lower(name_romaji), ?::text) AS sim FROM rm_performers WHERE lower(name_romaji) % ?::text ORDER BY sim DESC LIMIT 1) UNION ALL (SELECT code, similarity(lower(code), ?::text) FROM rm_movies WHERE lower(code) % ?::text ORDER BY 2 DESC LIMIT 1) ORDER BY sim DESC LIMIT 1',
            [$lower, $lower, $lower, $lower],
        );

        return $row === null ? null : (string) $row->s;
    }

    /**
     * Movies, performers, makers, series and genres to offer while a text is typed.
     *
     * @return array{movies: list<object>, performers: list<object>, makers: list<object>, series: list<object>, genres: list<object>}
     */
    public function suggestions(string $text, int $limit): array
    {
        $like = SqlConditions::like($text);
        $norm = $this->codeNorm($text);

        return [
            'movies' => DB::select("SELECT code, title_en, title_jp, cover_thumb_url, cover_url FROM rm_movies WHERE NOT delisted AND (code ILIKE ? OR code_norm = ? OR search_text ILIKE ?) ORDER BY (code_norm = ?) DESC, release_date DESC NULLS LAST, id DESC LIMIT {$limit}", [$like, $norm, $like, $norm]),
            'performers' => DB::select("SELECT id, name_romaji, name_kanji, photo_url, movie_count FROM rm_performers WHERE search_text LIKE ? ORDER BY (photo_url IS NOT NULL) DESC, movie_count DESC, id LIMIT {$limit}", [$like]),
            'makers' => $this->named('SELECT maker AS name, count(*)::int AS count FROM rm_movies WHERE maker ILIKE ? GROUP BY maker ORDER BY count DESC, maker LIMIT 4', $like),
            'series' => $this->named('SELECT series AS name, count(*)::int AS count FROM rm_movies WHERE series ILIKE ? GROUP BY series ORDER BY count DESC, series LIMIT 4', $like),
            'genres' => $this->named('SELECT g AS name, count(*)::int AS count FROM rm_movies, unnest(genres) g WHERE g ILIKE ? GROUP BY g ORDER BY count DESC, g LIMIT 4', $like),
        ];
    }

    /**
     * Genres, makers, series and labels whose name contains one of the patterns.
     *
     * @param list<string> $patterns LIKE patterns (already escaped)
     * @return list<array{kind: string, name: string, n: int}>
     */
    public function catalogue(array $patterns): array
    {
        $array = SqlConditions::pgArray($patterns);
        $sources = [
            'genres' => 'SELECT g AS name, count(*)::int AS n FROM rm_movies, unnest(genres) g WHERE lower(g) LIKE ANY(?::text[]) GROUP BY g ORDER BY n DESC, g LIMIT 8',
            'makers' => 'SELECT maker AS name, count(*)::int AS n FROM rm_movies WHERE lower(maker) LIKE ANY(?::text[]) GROUP BY maker ORDER BY n DESC, maker LIMIT 8',
            'series' => 'SELECT series AS name, count(*)::int AS n FROM rm_movies WHERE lower(series) LIKE ANY(?::text[]) GROUP BY series ORDER BY n DESC, series LIMIT 8',
            'labels' => 'SELECT label AS name, count(*)::int AS n FROM rm_movies WHERE lower(label) LIKE ANY(?::text[]) GROUP BY label ORDER BY n DESC, label LIMIT 8',
        ];
        $out = [];

        foreach ($sources as $kind => $sql) {
            foreach (DB::select($sql, [$array]) as $row) {
                $out[] = ['kind' => $kind, 'name' => (string) $row->name, 'n' => (int) $row->n];
            }
        }

        return $out;
    }

    /** @return list<object> */
    private function named(string $sql, string $like): array
    {
        return DB::select($sql, [$like]);
    }

    private function codeNorm(string $text): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $text));
    }
}
