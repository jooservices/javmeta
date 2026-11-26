<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Catalogue;

use Illuminate\Support\Str;

/**
 * The one filter language of the catalogue (same parameter names as the UI URLs). Movie lists, facets, photo search and
 * "performers of the matching movies" all build their WHERE clause here, so a facet count always equals its result.
 */
final class CatalogueFilters
{
    public const MOVIE_KEYS = ['q', 'genre', 'genre_not', 'genre_all', 'maker', 'series', 'label', 'actress_id', 'code', 'released_from', 'released_to', 'runtime_min', 'runtime_max', 'crawled_days', 'score_min', 'tier_min', 'censored', 'has_magnet', 'has_performers', 'has_watch', 'source'];

    public const PERFORMER_KEYS = ['has_profile', 'bio_q', 'age_min', 'age_max', 'debut_from', 'debut_to', 'cup', 'blood_type', 'location', 'height_min', 'height_max', 'born_from', 'born_to', 'bust_min', 'bust_max', 'waist_min', 'waist_max', 'hip_min', 'hip_max', 'status', 'has_image', 'has_bio', 'movies_min'];

    public const PERFORMER_MOVIE_KEYS = ['genre', 'genre_all', 'maker', 'series', 'label', 'released_from', 'released_to'];

    private const PROFILE = '(p.birth_date IS NOT NULL OR p.height_cm IS NOT NULL OR p.bust IS NOT NULL OR p.waist IS NOT NULL OR p.hip IS NOT NULL OR p.cup IS NOT NULL OR p.blood_type IS NOT NULL OR p.location IS NOT NULL OR p.debut_date IS NOT NULL)';

    /**
     * Movies (alias m) matching the query; performer-profile filters keep movies that have a matching performer in the cast.
     *
     * @param array<string, mixed> $query
     * @param list<string> $omit filters left out (a facet is counted without its own filter)
     */
    public function movies(array $query, array $omit = [], string $alias = 'm'): SqlConditions
    {
        $c = new SqlConditions();
        $this->movieConditions($c, $query, $omit, $alias);

        if ($this->hasAny($query, self::PERFORMER_KEYS, $omit)) {
            $inner = new SqlConditions();
            $this->performerConditions($inner, $query, $omit, true);
            $c->add('EXISTS (SELECT 1 FROM rm_performers p WHERE p.id = ANY(' . $alias . '.performer_ids)' . ($inner->isEmpty() ? '' : ' AND ' . $inner->sql()) . ')', ...$inner->bindings());
        }

        return $c;
    }

    /**
     * Performers (alias p): their own profile filters, the name text, and "played in a movie that matches" for the movie-side keys.
     *
     * @param array<string, mixed> $query
     * @param list<string> $omit
     */
    public function performers(array $query, array $omit = []): SqlConditions
    {
        $c = new SqlConditions();
        $c->add('p.name_romaji IS NOT NULL');
        $this->performerConditions($c, $query, $omit, false);
        $movieSide = array_filter(self::PERFORMER_MOVIE_KEYS, fn(string $k): bool => $this->has($query, $k, $omit));

        if ($movieSide !== []) {
            $inner = new SqlConditions();
            $this->movieConditions($inner, array_intersect_key($query, array_flip($movieSide)), $omit, 'm');
            $c->add('p.id IN (SELECT unnest(m.performer_ids) FROM rm_movies m' . ($inner->isEmpty() ? '' : ' WHERE ' . $inner->sql()) . ')', ...$inner->bindings());
        }

        return $c;
    }

    /**
     * @param array<string, mixed> $query
     * @param list<string> $omit
     */
    private function movieConditions(SqlConditions $c, array $query, array $omit, string $m): void
    {
        foreach ($this->words($query, 'q', $omit) as $word) {
            $c->add("{$m}.search_text LIKE ?", SqlConditions::like($word));
        }

        $this->when($c, $query, 'code', $omit, "{$m}.code ILIKE ?", fn(string $v): array => [SqlConditions::like($v)]);
        $this->genres($c, $query, $omit, $m);

        foreach (['maker', 'series', 'label'] as $field) {
            $this->when($c, $query, $field, $omit, "lower({$m}.{$field}) LIKE ?", fn(string $v): array => [SqlConditions::like($v)]);
        }

        $this->when($c, $query, 'actress_id', $omit, "{$m}.performer_ids @> ?::int[]", fn(string $v): array => [SqlConditions::pgArray(array_values(array_filter(array_map('intval', explode(',', $v)))))]);

        foreach ([['released_from', 'year', '>='], ['released_to', 'year', '<='], ['runtime_min', 'runtime', '>='], ['runtime_max', 'runtime', '<='], ['score_min', 'score', '>='], ['tier_min', 'tier', '>=']] as [$key, $column, $op]) {
            $this->number($c, $query, $key, $omit, "{$m}.{$column} {$op} ?");
        }

        if ($this->has($query, 'censored', $omit) && in_array($query['censored'], ['censored', 'uncensored'], true)) {
            $c->add("{$m}.censored = " . ($query['censored'] === 'censored' ? '1' : '0'));
        }

        $this->flag($c, $query, 'has_magnet', $omit, "{$m}.dl_count > 0", "{$m}.dl_count = 0");
        $this->flag($c, $query, 'has_performers', $omit, "cardinality({$m}.performer_ids) > 0", null);
        $this->flag($c, $query, 'has_watch', $omit, "{$m}.hls_count > 0", null);
        $this->number($c, $query, 'crawled_days', $omit, "{$m}.crawled_at > now() - make_interval(days => ?::int)");
        $this->when($c, $query, 'source', $omit, "{$m}.sources && ?::text[]", fn(string $v): array => [SqlConditions::pgArray($this->csv($v))]);
        $c->add("NOT {$m}.delisted");
    }

    /**
     * @param array<string, mixed> $query
     * @param list<string> $omit
     */
    private function genres(SqlConditions $c, array $query, array $omit, string $m): void
    {
        // A genre is found by its label as shown ("Drama") and by its normalized key ("drama"), the way v1 and MCP clients pass it.
        if ($this->has($query, 'genre', $omit)) {
            $operator = ($query['genre_all'] ?? null) === '1' ? '@>' : '&&';
            $labels = $this->csv((string) $query['genre']);
            $c->add("({$m}.genres {$operator} ?::text[] OR {$m}.genre_keys {$operator} ?::text[])", SqlConditions::pgArray($labels), SqlConditions::pgArray($this->keys($labels)));
        }

        $this->when($c, $query, 'genre_not', $omit, "NOT ({$m}.genres && ?::text[] OR {$m}.genre_keys && ?::text[])", fn(string $v): array => [SqlConditions::pgArray($this->csv($v)), SqlConditions::pgArray($this->keys($this->csv($v)))]);
    }

    /**
     * @param list<string> $labels
     * @return list<string>
     */
    private function keys(array $labels): array
    {
        return array_map(static fn(string $label): string => Str::slug($label), $labels);
    }

    /**
     * @param array<string, mixed> $query
     * @param list<string> $omit
     */
    private function performerConditions(SqlConditions $c, array $query, array $omit, bool $skipText): void
    {
        if (! $skipText) {
            $column = ($query['q_bio'] ?? null) === '1' ? "(p.search_text || ' ' || lower(coalesce(p.bio_text, '')))" : 'p.search_text';

            foreach ($this->words($query, 'q', $omit) as $word) {
                $c->add("{$column} LIKE ?", SqlConditions::like($word));
            }
        }

        foreach (['cup', 'blood_type'] as $field) {
            $this->when($c, $query, $field, $omit, "p.{$field} = ANY(?::text[])", fn(string $v): array => [SqlConditions::pgArray($this->csv($v))]);
        }

        $this->when($c, $query, 'location', $omit, 'lower(p.location) LIKE ?', fn(string $v): array => [SqlConditions::like($v)]);

        if ($this->has($query, 'status', $omit)) {
            $c->add($query['status'] === 'retired' ? 'p.retired_date IS NOT NULL' : 'p.retired_date IS NULL');
        }

        $this->flag($c, $query, 'has_image', $omit, 'p.photo_url IS NOT NULL', 'p.photo_url IS NULL');
        $this->flag($c, $query, 'has_bio', $omit, 'p.bio_text IS NOT NULL', 'p.bio_text IS NULL');
        $this->flag($c, $query, 'has_profile', $omit, self::PROFILE, 'NOT ' . self::PROFILE);
        $this->number($c, $query, 'movies_min', $omit, 'p.movie_count >= ?');

        foreach ($this->words($query, 'bio_q', $omit) as $word) {
            $c->add("lower(coalesce(p.bio_text, '')) LIKE ?", SqlConditions::like($word));
        }

        $this->number($c, $query, 'age_min', $omit, "p.birth_date <= (current_date - make_interval(years => ?::int))::date");
        $this->numberPlusOne($c, $query, 'age_max', $omit, "p.birth_date > (current_date - make_interval(years => ?::int))::date");

        foreach ([['debut_from', 'debut_date', '>='], ['debut_to', 'debut_date', '<='], ['born_from', 'birth_date', '>='], ['born_to', 'birth_date', '<=']] as [$key, $column, $op]) {
            $this->number($c, $query, $key, $omit, "extract(year from p.{$column}) {$op} ?");
        }

        foreach ([['height', 'height_cm'], ['bust', 'bust'], ['waist', 'waist'], ['hip', 'hip']] as [$key, $column]) {
            $this->number($c, $query, "{$key}_min", $omit, "p.{$column} >= ?");
            $this->number($c, $query, "{$key}_max", $omit, "p.{$column} <= ?");
        }
    }

    /**
     * @param array<string, mixed> $query
     * @param list<string> $omit
     * @param callable(string): list<mixed> $bind
     */
    private function when(SqlConditions $c, array $query, string $key, array $omit, string $sql, callable $bind): void
    {
        if ($sql !== '' && $this->has($query, $key, $omit)) {
            $c->add($sql, ...$bind((string) $query[$key]));
        }
    }

    /**
     * @param array<string, mixed> $query
     * @param list<string> $omit
     */
    private function number(SqlConditions $c, array $query, string $key, array $omit, string $sql): void
    {
        if ($this->has($query, $key, $omit) && is_numeric($query[$key])) {
            $c->add($sql, $query[$key] + 0);
        }
    }

    /**
     * @param array<string, mixed> $query
     * @param list<string> $omit
     */
    private function numberPlusOne(SqlConditions $c, array $query, string $key, array $omit, string $sql): void
    {
        if ($this->has($query, $key, $omit) && is_numeric($query[$key])) {
            $c->add($sql, (int) $query[$key] + 1);
        }
    }

    /**
     * @param array<string, mixed> $query
     * @param list<string> $omit
     */
    private function flag(SqlConditions $c, array $query, string $key, array $omit, string $whenOne, ?string $whenZero): void
    {
        if (! $this->has($query, $key, $omit)) {
            return;
        }

        if ((string) $query[$key] === '1') {
            $c->add($whenOne);
        } elseif ($whenZero !== null && (string) $query[$key] === '0') {
            $c->add($whenZero);
        }
    }

    /**
     * @param array<string, mixed> $query
     * @param list<string> $omit
     * @return list<string>
     */
    private function words(array $query, string $key, array $omit): array
    {
        return $this->has($query, $key, $omit) ? array_values(array_filter(preg_split('/\s+/', mb_strtolower((string) $query[$key])) ?: [])) : [];
    }

    /** @return list<string> */
    private function csv(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $v): bool => $v !== ''));
    }

    /**
     * @param array<string, mixed> $query
     * @param list<string> $keys
     * @param list<string> $omit
     */
    private function hasAny(array $query, array $keys, array $omit): bool
    {
        foreach ($keys as $key) {
            if ($this->has($query, $key, $omit)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $query
     * @param list<string> $omit
     */
    private function has(array $query, string $key, array $omit): bool
    {
        return ! in_array($key, $omit, true) && isset($query[$key]) && $query[$key] !== '' && ! is_array($query[$key]);
    }
}
