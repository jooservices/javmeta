<?php

declare(strict_types=1);

namespace App\Repositories\Api\V2;

use App\Services\Api\V2\Catalogue\CatalogueFilters;
use App\Services\Api\V2\Catalogue\SqlConditions;
use Illuminate\Support\Facades\DB;

/** Options of the "played in a movie that is ..." filters, each counted in distinct performers. PostgreSQL only. */
final class PerformerMovieFacetsRepository
{
    private const LIMIT = 400;

    public function __construct(private readonly CatalogueFilters $filters) {}

    /**
     * @param array<string, mixed> $query performer filters and movie-side filters
     * @return array{genres: list<array{name: string, n: int}>, makers: list<array{name: string, n: int}>, series: list<array{name: string, n: int}>, labels: list<array{name: string, n: int}>, years: array{0: int, 1: int}|null}
     */
    public function facets(array $query): array
    {
        $performers = $this->filters->performers($query, CatalogueFilters::PERFORMER_MOVIE_KEYS);
        $movieSide = array_filter(array_intersect_key($query, array_flip(CatalogueFilters::PERFORMER_MOVIE_KEYS)), static fn(mixed $v): bool => $v !== null && $v !== '');

        return [
            'genres' => $this->group('genre', 'g', 'rm_movies m, unnest(m.performer_ids) pid, unnest(m.genres) g', $performers, $movieSide, "AND g <> ''"),
            'makers' => $this->group('maker', 'm.maker', 'rm_movies m, unnest(m.performer_ids) pid', $performers, $movieSide, 'AND m.maker IS NOT NULL'),
            'series' => $this->group('series', 'm.series', 'rm_movies m, unnest(m.performer_ids) pid', $performers, $movieSide, 'AND m.series IS NOT NULL'),
            'labels' => $this->group('label', 'm.label', 'rm_movies m, unnest(m.performer_ids) pid', $performers, $movieSide, 'AND m.label IS NOT NULL'),
            'years' => $this->years($movieSide),
        ];
    }

    /**
     * @param array<string, mixed> $movieSide
     * @return list<array{name: string, n: int}>
     */
    private function group(string $key, string $select, string $from, SqlConditions $performers, array $movieSide, string $extra): array
    {
        $others = array_filter(array_keys($movieSide), static fn(string $other): bool => $other !== $key && ! ($key === 'genre' && $other === 'genre_all'));

        if ($others === []) {
            $rows = DB::select(
                'SELECT a.name, count(*)::int AS n FROM rm_perf_attr a WHERE a.kind = ? AND a.pid IN (SELECT p.id FROM rm_performers p WHERE ' . $performers->sql() . ') GROUP BY a.name ORDER BY n DESC, a.name LIMIT ' . self::LIMIT,
                [$key, ...$performers->bindings()],
            );
        } else {
            $movies = $this->filters->movies($movieSide, [$key]);
            $rows = DB::select(
                "SELECT {$select} AS name, count(DISTINCT pid)::int AS n FROM {$from} WHERE " . $movies->sql() . " {$extra} AND pid IN (SELECT p.id FROM rm_performers p WHERE " . $performers->sql() . ') GROUP BY 1 ORDER BY n DESC, 1 LIMIT ' . self::LIMIT,
                [...$movies->bindings(), ...$performers->bindings()],
            );
        }

        return array_map(static fn(object $row): array => ['name' => (string) $row->name, 'n' => (int) $row->n], $rows);
    }

    /**
     * @param array<string, mixed> $movieSide
     * @return array{0: int, 1: int}|null
     */
    private function years(array $movieSide): ?array
    {
        $movies = $this->filters->movies($movieSide, ['released_from', 'released_to']);
        $row = DB::selectOne('SELECT min(m.year)::int AS lo, max(m.year)::int AS hi FROM rm_movies m WHERE ' . $movies->sql() . ' AND m.year BETWEEN 1980 AND 2035', $movies->bindings());

        return $row->lo === null ? null : [(int) $row->lo, (int) $row->hi];
    }
}
