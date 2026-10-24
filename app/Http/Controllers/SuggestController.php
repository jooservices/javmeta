<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\InvalidFilterException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use JOOservices\LaravelController\Http\Controllers\BaseApiController;

/**
 * Quick suggestions while typing: a few movies (by code or title), performers (by name or alias) and the genres,
 * makers and series whose name contains the text.
 */
final class SuggestController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $limit = (int) $request->query('limit', 5);
        if (mb_strlen($q) < 2 || mb_strlen($q) > 100 || $limit < 1 || $limit > 20) {
            throw new InvalidFilterException('Invalid q or limit.', ['q' => ['2 to 100 characters'], 'limit' => ['1 to 20']]);
        }

        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $op = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $movies = DB::table('movies')
            ->where(fn($w) => $w->where('display_code', $op, $like)->orWhere('title_en', $op, $like)->orWhere('title_jp', $op, $like))
            ->orderByRaw('CASE WHEN display_code ' . $op . ' ? THEN 0 ELSE 1 END', [mb_strtolower($q) . '%'])
            ->orderByDesc('release_date')
            ->limit($limit)
            ->get(['display_code', 'title_en', 'title_jp', 'maker']);

        $performers = DB::table('performers')
            ->where(fn($w) => $w->where('name_romaji', $op, $like)->orWhere('name_kanji', $op, $like)->orWhere('name_kana', $op, $like)
                ->orWhereIn('id', DB::table('performer_aliases')->where('alias', $op, $like)->select('performer_id')))
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'name_romaji', 'name_kanji']);

        $facet = static fn(string $column) => DB::table('movies')->whereNotNull($column)->where($column, $op, $like)
            ->selectRaw($column . ' AS name, COUNT(*) AS movie_count')->groupBy($column)->orderByDesc('movie_count')->limit($limit)
            ->get()->map(static fn($r): array => ['name' => (string) $r->name, 'movie_count' => (int) $r->movie_count])->all();

        return $this->respondWithData(
            data: [
                'movies' => $movies->map(static fn($m): array => ['code' => $m->display_code, 'title' => $m->title_en ?? $m->title_jp, 'maker' => $m->maker])->all(),
                'performers' => $performers->map(static fn($p): array => ['id' => (int) $p->id, 'name' => $p->name_romaji ?? $p->name_kanji])->all(),
                'makers' => $facet('maker'),
                'series' => $facet('series'),
            ],
            message: 'Suggestions retrieved successfully.',
        );
    }
}
