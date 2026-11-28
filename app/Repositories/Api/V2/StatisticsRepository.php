<?php

declare(strict_types=1);

namespace App\Repositories\Api\V2;

use App\Services\Api\V2\Catalogue\SqlConditions;
use Illuminate\Support\Facades\DB;

/** Aggregates over the search tables and the crawler tables. PostgreSQL only. */
final class StatisticsRepository
{
    private const DISTRIBUTIONS = [
        'year' => 'SELECT year::text AS label, count(*)::int AS count FROM rm_movies WHERE year BETWEEN 1990 AND 2030 GROUP BY year ORDER BY year',
        'genre' => 'SELECT g AS label, count(*)::int AS count FROM rm_movies, unnest(genres) g GROUP BY g ORDER BY count DESC, g LIMIT ?',
        'maker' => 'SELECT maker AS label, count(*)::int AS count FROM rm_movies WHERE maker IS NOT NULL GROUP BY maker ORDER BY count DESC, maker LIMIT ?',
        'cup' => 'SELECT cup AS label, count(*)::int AS count FROM rm_performers WHERE cup IS NOT NULL GROUP BY cup ORDER BY cup',
        'blood-type' => 'SELECT blood_type AS label, count(*)::int AS count FROM rm_performers WHERE blood_type IS NOT NULL GROUP BY blood_type ORDER BY count DESC, blood_type',
        'age' => "SELECT a::text AS label, count(*)::int AS count FROM (SELECT date_part('year', age(birth_date))::int AS a FROM rm_performers WHERE birth_date IS NOT NULL) t WHERE a BETWEEN 18 AND 60 GROUP BY a ORDER BY a",
        'height' => 'SELECT height_cm::text AS label, count(*)::int AS count FROM rm_performers WHERE height_cm BETWEEN 130 AND 190 GROUP BY height_cm ORDER BY height_cm',
        'runtime' => 'SELECT (runtime / 30 * 30)::text AS label, count(*)::int AS count FROM rm_movies WHERE runtime BETWEEN 1 AND 600 GROUP BY 1 ORDER BY 1::int',
        'quality' => 'SELECT tier::text AS label, count(*)::int AS count FROM rm_movies WHERE tier IS NOT NULL GROUP BY tier ORDER BY tier',
    ];

    private const CORRELATIONS = [
        'age-movies' => "SELECT a AS x, round(avg(movie_count), 1)::float AS y, count(*)::int AS n FROM (SELECT date_part('year', age(birth_date))::int AS a, movie_count FROM rm_performers WHERE birth_date IS NOT NULL) t WHERE a BETWEEN 18 AND 50 GROUP BY a ORDER BY a",
        'height-movies' => 'SELECT height_cm AS x, round(avg(movie_count), 1)::float AS y, count(*)::int AS n FROM rm_performers WHERE height_cm BETWEEN 140 AND 180 GROUP BY height_cm ORDER BY height_cm',
        'year-score' => 'SELECT year AS x, round(avg(score), 2)::float AS y, count(*)::int AS n FROM rm_movies WHERE score IS NOT NULL AND year BETWEEN 2005 AND 2030 GROUP BY year ORDER BY year',
        'year-runtime' => 'SELECT year AS x, round(avg(runtime), 1)::float AS y, count(*)::int AS n FROM rm_movies WHERE runtime BETWEEN 1 AND 600 AND year BETWEEN 2005 AND 2030 GROUP BY year ORDER BY year',
        'cup-height' => 'SELECT cup AS x, round(avg(height_cm), 1)::float AS y, count(*)::int AS n FROM rm_performers WHERE cup IS NOT NULL AND height_cm BETWEEN 130 AND 190 GROUP BY cup ORDER BY cup',
    ];

    /** @return list<string> */
    public function distributionNames(): array
    {
        return array_keys(self::DISTRIBUTIONS);
    }

    /** @return list<string> */
    public function correlationNames(): array
    {
        return array_keys(self::CORRELATIONS);
    }

    public function overview(): object
    {
        return DB::selectOne(
            'SELECT (SELECT count(*)::int FROM rm_movies WHERE NOT delisted) AS movies, '
            . '(SELECT count(*)::int FROM rm_performers) AS performers, '
            . '(SELECT count(*)::int FROM rm_performers WHERE photo_url IS NOT NULL) AS performers_with_photo, '
            . '(SELECT count(*)::int FROM rm_movies WHERE cover_url IS NOT NULL) AS movies_with_cover, '
            . '(SELECT count(*)::int FROM rm_movies WHERE dl_count > 0) AS movies_with_download, '
            . '(SELECT coalesce(sum(gallery_count), 0)::int FROM rm_movies) AS gallery_photos, '
            . '(SELECT max(refreshed_at) FROM rm_refresh) AS data_as_of, '
            . '(SELECT coalesce(sum(dl_count), 0)::int FROM rm_movies) AS magnets, '
            . "(SELECT count(*)::int FROM rm_movies WHERE first_seen_at > now() - interval '24 hours') AS movies_24h, "
            . "(SELECT count(*)::int FROM rm_movies WHERE first_seen_at > now() - interval '1 hour') AS movies_1h, "
            . "(SELECT count(*)::int FROM rm_performers WHERE first_seen_at > now() - interval '24 hours') AS performers_24h",
        );
    }

    /** @return list<object> */
    public function qualityTiers(): array
    {
        return DB::select('SELECT tier, count(*)::int AS count FROM rm_movies WHERE tier IS NOT NULL GROUP BY tier ORDER BY tier');
    }

    /**
     * New rows per period. $table and $interval come from a fixed list, never from the request.
     *
     * @return list<object>
     */
    public function series(string $table, string $interval, int $days): array
    {
        return DB::select("SELECT to_char(date_trunc('{$interval}', first_seen_at AT TIME ZONE 'UTC'), 'YYYY-MM-DD\"T\"HH24:MI') AS period, count(*)::int AS count FROM {$table} WHERE first_seen_at > now() - make_interval(days => ?::int) GROUP BY 1 ORDER BY 1", [$days]);
    }

    /** @return list<object> */
    public function distribution(string $by, int $limit): array
    {
        $sql = self::DISTRIBUTIONS[$by];

        return DB::select($sql, str_contains($sql, 'LIMIT ?') ? [$limit] : []);
    }

    /** @return list<object> */
    public function correlation(string $pair): array
    {
        return DB::select(self::CORRELATIONS[$pair]);
    }

    public function dataQuality(): object
    {
        return DB::selectOne('SELECT count(*)::int AS total, count(birth_date)::int AS birth, count(blood_type)::int AS blood, count(location)::int AS location, count(bio_text)::int AS bio, count(height_cm)::int AS height, count(bust)::int AS bust, count(cup)::int AS cup, count(debut_date)::int AS debut, count(photo_url)::int AS photo FROM rm_performers');
    }

    /** @return list<object> */
    public function crawlHealth(int $hours): array
    {
        return DB::select("SELECT hour_at, sum(ok)::int AS ok, sum(attempts)::int AS attempts, sum(blocked)::int AS blocked, sum(timeout)::int AS timeout, sum(other_error)::int AS errors FROM crawl_stats_hourly WHERE hour_at > now() - make_interval(hours => ?::int) AND source_slug <> '__global__' GROUP BY hour_at ORDER BY hour_at", [$hours]);
    }

    /** @return list<object> */
    public function contributions(): array
    {
        return DB::select("SELECT t.slug, t.n, coalesce(s.name, t.slug) AS name FROM (SELECT s AS slug, count(*)::int AS n FROM rm_movies m, unnest(m.sources) s WHERE m.first_seen_at > now() - interval '24 hours' GROUP BY s ORDER BY n DESC, s LIMIT 12) t LEFT JOIN sources s ON s.slug = t.slug ORDER BY t.n DESC, t.slug");
    }

    public function sourcesActive(): object
    {
        return DB::selectOne('SELECT count(*) FILTER (WHERE enabled)::int AS active, count(*)::int AS total FROM sources');
    }

    /**
     * The statistics page in one payload.
     *
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        return [
            'totals' => DB::selectOne("SELECT (SELECT count(*)::int FROM rm_movies WHERE NOT delisted) AS movies, (SELECT count(*)::int FROM rm_performers) AS performers, (SELECT coalesce(sum(dl_count), 0)::int FROM rm_movies) AS magnets, (SELECT count(*)::int FROM rm_movies WHERE first_seen_at > now() - interval '24 hours') AS movies_24h, (SELECT count(*)::int FROM rm_performers WHERE first_seen_at > now() - interval '24 hours') AS performers_24h"),
            'movies_series' => DB::select("SELECT to_char(first_seen_at AT TIME ZONE 'UTC', 'YYYY-MM-DD') AS d, count(*)::int AS n FROM rm_movies WHERE first_seen_at > now() - interval '14 days' GROUP BY 1 ORDER BY 1"),
            'performers_series' => DB::select("SELECT to_char(first_seen_at AT TIME ZONE 'UTC', 'YYYY-MM-DD') AS d, count(*)::int AS n FROM rm_performers WHERE first_seen_at > now() - interval '14 days' GROUP BY 1 ORDER BY 1"),
            'years' => DB::select('SELECT year AS y, count(*)::int AS n FROM rm_movies WHERE year BETWEEN 1990 AND 2030 AND NOT delisted GROUP BY year ORDER BY year'),
            'makers' => $this->top('maker'),
            'series' => $this->top('series'),
            'labels' => $this->top('label'),
            'genres' => DB::select("SELECT g AS name, count(*)::int AS n FROM rm_movies m, unnest(m.genres) g WHERE NOT m.delisted AND g <> '' GROUP BY g ORDER BY n DESC, g LIMIT 40"),
            'runtime' => DB::select("SELECT CASE WHEN runtime < 60 THEN '<60' WHEN runtime < 90 THEN '60-89' WHEN runtime < 120 THEN '90-119' WHEN runtime < 180 THEN '120-179' ELSE '180+' END AS k, count(*)::int AS n FROM rm_movies WHERE runtime > 0 AND NOT delisted GROUP BY 1"),
            'score' => DB::select("SELECT CASE WHEN score < 2 THEN '0-2' WHEN score < 4 THEN '2-4' WHEN score < 6 THEN '4-6' WHEN score < 8 THEN '6-8' ELSE '8-10' END AS k, count(*)::int AS n FROM rm_movies WHERE score IS NOT NULL AND NOT delisted GROUP BY 1"),
            'months' => DB::select("SELECT to_char(release_date, 'YYYY-MM') AS k, count(*)::int AS n FROM rm_movies WHERE release_date <= current_date AND release_date > current_date - interval '12 months' AND NOT delisted GROUP BY 1 ORDER BY 1"),
            'cast' => DB::select("SELECT CASE WHEN c = 0 THEN '0' WHEN c = 1 THEN '1' WHEN c = 2 THEN '2' WHEN c <= 4 THEN '3-4' ELSE '5+' END AS k, count(*)::int AS n FROM (SELECT cardinality(performer_ids) AS c FROM rm_movies WHERE NOT delisted) t GROUP BY 1"),
            'tiers' => DB::select('SELECT tier, count(*)::int AS n FROM rm_movies WHERE tier IS NOT NULL AND NOT delisted GROUP BY tier ORDER BY tier'),
            'coverage' => DB::selectOne('SELECT count(*)::int AS total, count(title_en)::int AS title_en, count(cover_url)::int AS cover, count(release_date)::int AS release_date, count(runtime)::int AS runtime, count(maker)::int AS maker, count(label)::int AS label, count(series)::int AS series, count(score)::int AS score, count(*) FILTER (WHERE cardinality(genres) > 0)::int AS genres, count(*) FILTER (WHERE cardinality(performer_ids) > 0)::int AS performers, count(*) FILTER (WHERE dl_count > 0)::int AS magnets, count(*) FILTER (WHERE needs_review)::int AS needs_review FROM rm_movies WHERE NOT delisted'),
            'with_maker' => (int) DB::selectOne('SELECT count(*)::int AS n FROM rm_movies WHERE maker IS NOT NULL AND NOT delisted')->n,
        ];
    }

    /**
     * Averages of 2 or 3 entries of a kind (the radar axes before scaling), the largest entry's size, and the catalogue size.
     * $kind comes from a fixed list, never from the request.
     *
     * @param list<string> $names
     * @return array{rows: list<object>, max: int, total: int}
     */
    public function compare(string $kind, array $names): array
    {
        $from = $kind === 'genre' ? 'rm_movies m, unnest(m.genres) e' : 'rm_movies m';
        $col = $kind === 'genre' ? 'e' : "m.{$kind}";
        $array = SqlConditions::pgArray($names);

        return [
            'rows' => DB::select("SELECT {$col} AS name, count(*)::int AS n, avg(CASE WHEN m.tier = 3 THEN 1 ELSE 0 END)::float AS tier3, avg(m.score)::float AS score, avg(CASE WHEN m.dl_count > 0 THEN 1 ELSE 0 END)::float AS links, avg(CASE WHEN m.hls_count > 0 THEN 1 ELSE 0 END)::float AS watch, avg(CASE WHEN cardinality(m.performer_ids) > 0 THEN 1 ELSE 0 END)::float AS cast_share, avg(CASE WHEN m.release_date > current_date - interval '365 days' THEN 1 ELSE 0 END)::float AS recent FROM {$from} WHERE {$col} = ANY(?::text[]) AND NOT m.delisted GROUP BY {$col} ORDER BY n DESC, name", [$array]),
            'max' => (int) DB::selectOne("SELECT coalesce(max(n), 0)::int AS mx FROM (SELECT count(*) AS n FROM {$from} WHERE {$col} IS NOT NULL AND NOT m.delisted GROUP BY {$col}) t")->mx,
            'total' => (int) DB::selectOne('SELECT count(*)::int AS total FROM rm_movies WHERE NOT delisted')->total,
        ];
    }

    /** @return list<object> */
    private function top(string $column): array
    {
        return DB::select("SELECT {$column} AS name, count(*)::int AS n FROM rm_movies WHERE {$column} IS NOT NULL AND NOT delisted GROUP BY {$column} ORDER BY n DESC, {$column} LIMIT 40");
    }
}
