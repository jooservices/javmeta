<?php

declare(strict_types=1);

namespace App\Repositories\Api\V2;

use Illuminate\Support\Facades\DB;

/** What was collected recently, highlights and the release calendar, read from the search tables. PostgreSQL only. */
final class UpdatesRepository
{
    /**
     * @return array{rows: list<object>, total: int}
     */
    public function recentMovies(int $limit, int $offset): array
    {
        $where = 'NOT m.delisted AND m.crawled_at IS NOT NULL';

        return [
            'rows' => DB::select('SELECT ' . CatalogueRepository::MOVIE_FIELDS . ", (m.crawled_at AT TIME ZONE 'UTC')::date AS day FROM rm_movies m WHERE {$where} ORDER BY m.crawled_at DESC, m.id DESC LIMIT {$limit} OFFSET {$offset}"),
            'total' => (int) DB::selectOne("SELECT count(*) AS n FROM rm_movies m WHERE {$where}")->n,
        ];
    }

    /**
     * @return array{rows: list<object>, total: int}
     */
    public function recentPerformers(bool $byDebut, bool $withPhoto, int $limit, int $offset): array
    {
        $by = $byDebut ? 'p.first_seen_at' : 'p.crawled_at';
        $where = "{$by} IS NOT NULL" . ($withPhoto ? ' AND p.photo_url IS NOT NULL' : '');

        return [
            'rows' => DB::select('SELECT ' . CatalogueRepository::PERFORMER_FIELDS . ", ({$by} AT TIME ZONE 'UTC')::date AS day FROM rm_performers p WHERE {$where} ORDER BY {$by} DESC, p.id DESC LIMIT {$limit} OFFSET {$offset}"),
            'total' => (int) DB::selectOne("SELECT count(*) AS n FROM rm_performers p WHERE {$where}")->n,
        ];
    }

    /**
     * @return array{rows: list<object>, total: int}
     */
    public function recentPhotos(int $limit, int $offset): array
    {
        return [
            'rows' => DB::select("SELECT id, name_romaji, photo_url, (crawled_at AT TIME ZONE 'UTC')::date AS day FROM rm_performers WHERE photo_url IS NOT NULL AND crawled_at IS NOT NULL ORDER BY crawled_at DESC, id DESC LIMIT {$limit} OFFSET {$offset}"),
            'total' => (int) DB::selectOne('SELECT count(*) AS n FROM rm_performers WHERE photo_url IS NOT NULL AND crawled_at IS NOT NULL')->n,
        ];
    }

    public function summary(): object
    {
        return DB::selectOne(
            "SELECT (SELECT count(*)::int FROM rm_movies WHERE first_seen_at > now() - interval '24 hours') AS movies_24h, "
            . "(SELECT count(*)::int FROM rm_performers WHERE first_seen_at > now() - interval '24 hours') AS performers_24h, "
            . "(SELECT count(*)::int FROM rm_performers WHERE crawled_at > now() - interval '24 hours' AND photo_url IS NOT NULL) AS photos_24h, "
            . "(SELECT count(*)::int FROM rm_movies WHERE first_seen_at > now() - interval '7 days') AS movies_7d, "
            . "(SELECT count(*)::int FROM rm_performers WHERE first_seen_at > now() - interval '7 days') AS performers_7d",
        );
    }

    /** @return list<object> */
    public function picks(): array
    {
        return DB::select('SELECT ' . CatalogueRepository::MOVIE_FIELDS . " FROM rm_movies m WHERE NOT m.delisted AND m.cover_url IS NOT NULL AND m.score IS NOT NULL AND m.release_date > current_date - interval '120 days' ORDER BY m.score DESC NULLS LAST, m.id DESC LIMIT 12");
    }

    /** @return list<object> */
    public function hotPerformers(): array
    {
        return DB::select('SELECT ' . CatalogueRepository::PERFORMER_FIELDS . ", c.n AS recent FROM (SELECT x AS pid, count(*)::int AS n FROM rm_movies m, unnest(m.performer_ids) x WHERE m.release_date > current_date - interval '45 days' GROUP BY x ORDER BY n DESC LIMIT 12) c JOIN rm_performers p ON p.id = c.pid WHERE p.photo_url IS NOT NULL ORDER BY c.n DESC, p.id");
    }

    /** @return list<object> */
    public function newest(): array
    {
        return DB::select('SELECT ' . CatalogueRepository::MOVIE_FIELDS . ' FROM rm_movies m WHERE NOT m.delisted AND m.cover_url IS NOT NULL ORDER BY m.crawled_at DESC NULLS LAST, m.id DESC LIMIT 16');
    }

    /** @return list<object> */
    public function releasedOn(string $day): array
    {
        return DB::select('SELECT ' . CatalogueRepository::MOVIE_FIELDS . ' FROM rm_movies m WHERE m.release_date = ?::date AND NOT m.delisted ORDER BY m.score DESC NULLS LAST, m.code LIMIT 200', [$day]);
    }

    /** @return list<object> */
    public function releasesOfMonth(string $month): array
    {
        return DB::select("SELECT to_char(release_date, 'YYYY-MM-DD') AS day, count(*)::int AS count FROM rm_movies WHERE release_date >= (?::text || '-01')::date AND release_date < ((?::text || '-01')::date + interval '1 month') AND NOT delisted GROUP BY 1 ORDER BY 1", [$month, $month]);
    }
}
