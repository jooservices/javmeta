<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\InvalidFilterException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use JOOservices\LaravelController\Http\Controllers\BaseApiController;

/**
 * Lists of genres, makers, series, labels and release years with the number of movies of each, and of performer cups,
 * blood types and locations with the number of performers (the pages of the UI and the filter suggestions). Counts are
 * cached for two minutes; the count field is movie_count, or performer_count for the performer catalogs.
 */
final class CatalogController extends BaseApiController
{
    public const KINDS = ['genres', 'makers', 'series', 'labels', 'years', 'cups', 'blood_types', 'locations'];

    /** Catalogs of performers (column to count); the others count movies. */
    private const PERFORMER_KINDS = ['cups' => 'cup', 'blood_types' => 'blood_type', 'locations' => 'location'];

    public function index(Request $request, string $kind): JsonResponse
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidFilterException('Unknown catalog.');
        }

        $q = trim((string) $request->query('q', ''));
        $sort = (string) $request->query('sort', $kind === 'years' ? 'name' : 'movie_count');
        $perPage = (int) $request->query('per_page', 50);
        $page = max(1, (int) $request->query('page', 1));
        if (! in_array($sort, ['movie_count', 'name'], true) || $perPage < 1 || $perPage > 200) {
            throw new InvalidFilterException('Invalid sort or per_page.', ['sort' => ['movie_count or name'], 'per_page' => ['1 to 200']]);
        }

        $key = 'javmeta:catalog:' . md5(json_encode([$kind, $q, $sort, $perPage, $page], JSON_THROW_ON_ERROR));
        $result = Cache::remember($key, 120, fn(): array => $this->load($kind, $q, $sort, $perPage, $page));

        return $this->respondWithData(
            data: $result['items'],
            message: 'Catalog retrieved successfully.',
            meta: ['pagination' => ['total' => $result['total'], 'per_page' => $perPage, 'page' => $page, 'last_page' => max(1, (int) ceil($result['total'] / $perPage)), 'sort' => $sort]],
        );
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    private function load(string $kind, string $q, string $sort, int $perPage, int $page): array
    {
        if (isset(self::PERFORMER_KINDS[$kind])) {
            return $this->loadPerformers(self::PERFORMER_KINDS[$kind], $q, $sort, $perPage, $page);
        }

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

        if ($q !== '' && $kind !== 'years') {
            $base->whereRaw('LOWER(' . $nameColumn . ') LIKE ?', ['%' . mb_strtolower(str_replace(['%', '_'], ['\\%', '\\_'], $q)) . '%']);
        }

        $total = (int) DB::query()->fromSub(clone $base, 'c')->count();
        $order = $sort === 'name' ? ($kind === 'years' ? 'name DESC' : 'name ASC') : 'movie_count DESC, name ASC';
        $rows = DB::query()->fromSub($base, 'c')->orderByRaw($order)->forPage($page, $perPage)->get(['name', 'movie_count']);

        return [
            'items' => $rows->map(static fn($r): array => ['name' => (string) $r->name, 'movie_count' => (int) $r->movie_count])->all(),
            'total' => $total,
        ];
    }

    /** @return array{items: list<array{name: string, performer_count: int}>, total: int} */
    private function loadPerformers(string $column, string $q, string $sort, int $perPage, int $page): array
    {
        $base = DB::table('performers')->whereNull('canonical_performer_id')->whereNotNull($column)->where($column, '<>', '')
            ->selectRaw($column . ' AS name, COUNT(*) AS performer_count')->groupBy($column);
        if ($q !== '') {
            $base->whereRaw('LOWER(' . $column . ') LIKE ?', ['%' . mb_strtolower(str_replace(['%', '_'], ['\\%', '\\_'], $q)) . '%']);
        }

        $total = (int) DB::query()->fromSub(clone $base, 'c')->count();
        $order = $sort === 'name' ? 'name ASC' : 'performer_count DESC, name ASC';
        $rows = DB::query()->fromSub($base, 'c')->orderByRaw($order)->forPage($page, $perPage)->get(['name', 'performer_count']);

        return [
            'items' => $rows->map(static fn($r): array => ['name' => (string) $r->name, 'performer_count' => (int) $r->performer_count])->all(),
            'total' => $total,
        ];
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
