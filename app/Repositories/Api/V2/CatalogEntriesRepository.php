<?php

declare(strict_types=1);

namespace App\Repositories\Api\V2;

use Illuminate\Support\Facades\DB;

/** Every distinct value of a catalogue kind with how many movies (or performers) carry it and a cover. PostgreSQL only. */
final class CatalogEntriesRepository
{
    private const NEWEST_COVER = '(array_agg(m.cover_url ORDER BY m.release_date DESC NULLS LAST) FILTER (WHERE m.cover_url IS NOT NULL))[1]';

    private const BUSIEST_PHOTO = '(array_agg(p.photo_url ORDER BY p.movie_count DESC) FILTER (WHERE p.photo_url IS NOT NULL))[1]';

    /** @return array<string, string> the movie kinds and the SELECT of each */
    private static function movieKinds(): array
    {
        $cover = self::NEWEST_COVER;

        return [
            'genres' => "SELECT g AS name, count(*)::int AS count, {$cover} AS cover FROM rm_movies m, unnest(m.genres) g WHERE NOT m.delisted AND g <> '' GROUP BY g",
            'makers' => "SELECT m.maker AS name, count(*)::int AS count, {$cover} AS cover FROM rm_movies m WHERE m.maker IS NOT NULL AND NOT m.delisted GROUP BY m.maker",
            'series' => "SELECT m.series AS name, count(*)::int AS count, {$cover} AS cover FROM rm_movies m WHERE m.series IS NOT NULL AND NOT m.delisted GROUP BY m.series",
            'labels' => "SELECT m.label AS name, count(*)::int AS count, {$cover} AS cover FROM rm_movies m WHERE m.label IS NOT NULL AND NOT m.delisted GROUP BY m.label",
            'years' => 'SELECT m.year::text AS name, count(*)::int AS count, (array_agg(m.cover_url ORDER BY m.score DESC NULLS LAST) FILTER (WHERE m.cover_url IS NOT NULL))[1] AS cover FROM rm_movies m WHERE m.year IS NOT NULL AND NOT m.delisted GROUP BY m.year',
        ];
    }

    /** @return array<string, string> the performer kinds and the SELECT of each */
    private static function performerKinds(): array
    {
        $photo = self::BUSIEST_PHOTO;

        return [
            'cups' => "SELECT p.cup AS name, count(*)::int AS count, {$photo} AS cover FROM rm_performers p WHERE p.cup IS NOT NULL GROUP BY p.cup",
            'blood-types' => "SELECT p.blood_type AS name, count(*)::int AS count, {$photo} AS cover FROM rm_performers p WHERE p.blood_type IS NOT NULL GROUP BY p.blood_type",
            'locations' => "SELECT p.location AS name, count(*)::int AS count, {$photo} AS cover FROM rm_performers p WHERE p.location IS NOT NULL GROUP BY p.location",
        ];
    }

    /** @return list<string> */
    public function kinds(): array
    {
        return [...array_keys(self::movieKinds()), ...array_keys(self::performerKinds())];
    }

    public function isPerformerKind(string $kind): bool
    {
        return array_key_exists($kind, self::performerKinds());
    }

    /** @return list<array{name: string, count: int, cover: ?string}> */
    public function entries(string $kind): array
    {
        $rows = DB::select((self::movieKinds() + self::performerKinds())[$kind]);

        return array_map(static fn(object $row): array => ['name' => (string) $row->name, 'count' => (int) $row->count, 'cover' => $row->cover], $rows);
    }
}
