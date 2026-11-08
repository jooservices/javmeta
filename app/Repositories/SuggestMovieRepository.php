<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Movie;
use Illuminate\Support\Facades\DB;
use JOOservices\LaravelRepository\Contracts\ReadRepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;
use InvalidArgumentException;

final class SuggestMovieRepository extends EloquentRepository implements ReadRepositoryInterface
{
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;

    public function __construct(Movie $model)
    {
        parent::__construct($model);
    }

    public function likeOperator(): string
    {
        return DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    /** @return list<array{code: string, title: ?string, maker: ?string}> */
    public function suggestions(string $pattern, string $prefixPattern, string $operator, int $limit): array
    {
        return DB::table('movies')
            ->where(fn($query) => $query->where('display_code', $operator, $pattern)
                ->orWhere('title_en', $operator, $pattern)
                ->orWhere('title_jp', $operator, $pattern))
            ->orderByRaw('CASE WHEN display_code ' . $operator . ' ? THEN 0 ELSE 1 END', [$prefixPattern])
            ->orderByDesc('release_date')
            ->limit($limit)
            ->get(['display_code', 'title_en', 'title_jp', 'maker'])
            ->map(static fn(object $movie): array => [
                'code' => (string) $movie->display_code,
                'title' => $movie->title_en ?? $movie->title_jp,
                'maker' => $movie->maker,
            ])
            ->all();
    }

    /** @return list<array{name: string, movie_count: int}> */
    public function facet(string $column, string $pattern, string $operator, int $limit): array
    {
        $column = match ($column) {
            'maker' => 'maker',
            'series' => 'series',
            default => throw new InvalidArgumentException('Unsupported suggestion facet.'),
        };

        return DB::table('movies')
            ->whereNotNull($column)
            ->where($column, $operator, $pattern)
            ->selectRaw($column . ' AS name, COUNT(*) AS movie_count')
            ->groupBy($column)
            ->orderByDesc('movie_count')
            ->limit($limit)
            ->get()
            ->map(static fn(object $row): array => ['name' => (string) $row->name, 'movie_count' => (int) $row->movie_count])
            ->all();
    }

    /** @return list<array{name: string, movie_count: int}> */
    public function genres(string $pattern, string $operator, int $limit): array
    {
        return DB::table('genres')->join('movie_genres', 'movie_genres.genre_id', '=', 'genres.id')
            ->whereRaw('COALESCE(genres.label_raw, genres.label_normalized) ' . $operator . ' ?', [$pattern])
            ->selectRaw('COALESCE(genres.label_raw, genres.label_normalized) AS name, COUNT(DISTINCT movie_genres.movie_id) AS movie_count')
            ->groupByRaw('COALESCE(genres.label_raw, genres.label_normalized)')
            ->orderByDesc('movie_count')
            ->limit($limit)
            ->get()
            ->map(static fn(object $row): array => ['name' => (string) $row->name, 'movie_count' => (int) $row->movie_count])
            ->all();
    }
}
