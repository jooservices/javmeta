<?php

declare(strict_types=1);

namespace App\Repositories\Api\V2;

use App\Services\Api\V2\Catalogue\SqlConditions;
use Illuminate\Support\Facades\DB;

/** The word lists the question reader matches a typed text against. PostgreSQL only. */
final class InterpretationRepository
{
    private const CATALOGUES = [
        'genres' => 'SELECT g AS name, count(*)::int AS n FROM rm_movies m, unnest(m.genres) g WHERE NOT m.delisted GROUP BY g',
        'makers' => 'SELECT maker AS name, count(*)::int AS n FROM rm_movies WHERE maker IS NOT NULL AND NOT delisted GROUP BY maker',
        'series' => 'SELECT series AS name, count(*)::int AS n FROM rm_movies WHERE series IS NOT NULL AND NOT delisted GROUP BY series',
        'locations' => 'SELECT location AS name, count(*)::int AS n FROM rm_performers WHERE location IS NOT NULL GROUP BY location',
    ];

    /**
     * Every name of a catalogue ("genres", "makers", "series" or "locations") with its number of rows.
     *
     * @return list<array{name: string, n: int}>
     */
    public function catalogue(string $kind): array
    {
        return array_map(static fn(object $row): array => ['name' => (string) $row->name, 'n' => (int) $row->n], DB::select(self::CATALOGUES[$kind]));
    }

    /**
     * The best known performer whose full romaji name is one of the candidates.
     *
     * @param list<string> $names lower-case candidates
     * @return array{id: int, name: string}|null
     */
    public function performerNamed(array $names): ?array
    {
        $row = DB::selectOne('SELECT id, name_romaji AS name FROM rm_performers WHERE lower(name_romaji) = ANY(?::text[]) ORDER BY movie_count DESC, id LIMIT 1', [SqlConditions::pgArray($names)]);

        return $row === null ? null : ['id' => (int) $row->id, 'name' => (string) $row->name];
    }
}
