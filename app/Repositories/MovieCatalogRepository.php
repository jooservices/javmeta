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

final class MovieCatalogRepository extends EloquentRepository implements ReadRepositoryInterface
{
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;

    public function __construct(Movie $model)
    {
        parent::__construct($model);
    }

    /** @return array{items: list<array{name: string, movie_count: int}>, total: int} */
    public function movieCatalog(string $kind, string $query, string $sort, int $perPage, int $page): array
    {
        $nameColumn = match ($kind) {
            'genres' => 'COALESCE(genres.label_raw, genres.label_normalized)',
            'years' => $this->yearExpression(),
            default => $this->column($kind),
        };
        $base = match ($kind) {
            'genres' => DB::table('genres')->join('movie_genres', 'movie_genres.genre_id', '=', 'genres.id')
                ->selectRaw($nameColumn . ' AS name, COUNT(DISTINCT movie_genres.movie_id) AS movie_count')
                ->groupByRaw($nameColumn),
            'years' => DB::table('movies')->whereNotNull('release_date')
                ->selectRaw($nameColumn . ' AS name, COUNT(*) AS movie_count')
                ->groupByRaw($nameColumn),
            default => DB::table('movies')->whereNotNull($nameColumn)->where($nameColumn, '<>', '')
                ->selectRaw($nameColumn . ' AS name, COUNT(*) AS movie_count')
                ->groupBy($nameColumn),
        };

        if ($query !== '' && $kind !== 'years') {
            $base->whereRaw('LOWER(' . $nameColumn . ') LIKE ?', ['%' . mb_strtolower(str_replace(['%', '_'], ['\\%', '\\_'], $query)) . '%']);
        }

        $total = (int) DB::query()->fromSub(clone $base, 'c')->count();
        $order = $sort === 'name' ? ($kind === 'years' ? 'name DESC' : 'name ASC') : 'movie_count DESC, name ASC';
        $rows = DB::query()->fromSub($base, 'c')->orderByRaw($order)->forPage($page, $perPage)->get(['name', 'movie_count']);

        /** @var list<array{name: string, movie_count: int}> $items */
        $items = $rows->map(static fn(object $row): array => ['name' => (string) $row->name, 'movie_count' => (int) $row->movie_count])->all();

        return ['items' => $items, 'total' => $total];
    }

    private function column(string $kind): string
    {
        return match ($kind) {
            'makers' => 'maker',
            'labels' => 'label',
            default => 'series',
        };
    }

    private function yearExpression(): string
    {
        return DB::getDriverName() === 'sqlite' ? "CAST(strftime('%Y', release_date) AS INTEGER)" : 'EXTRACT(YEAR FROM release_date)::int';
    }
}
