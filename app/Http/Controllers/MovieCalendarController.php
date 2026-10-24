<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\InvalidFilterException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use JOOservices\LaravelController\Http\Controllers\BaseApiController;

/** Release calendar: the movies released in a month, grouped by day (at most eight per day, plus the day total). */
final class MovieCalendarController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $month = (string) $request->query('month', now()->format('Y-m'));
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw new InvalidFilterException('Invalid month.', ['month' => ['YYYY-MM']]);
        }

        $days = Cache::remember('javmeta:calendar:' . $month, 120, fn(): array => $this->load($month));

        return $this->respondWithData(
            data: $days,
            message: 'Release calendar retrieved successfully.',
            meta: ['month' => $month, 'total' => array_sum(array_column($days, 'count'))],
        );
    }

    /** @return list<array{date: string, count: int, movies: list<array<string, mixed>>}> */
    private function load(string $month): array
    {
        $from = $month . '-01';
        $next = date('Y-m-d', (int) strtotime($from . ' +1 month'));
        $rows = DB::table('movies')->where('release_date', '>=', $from)->where('release_date', '<', $next)->whereNull('delisted_at')
            ->orderBy('release_date')->orderByDesc('community_score')->orderBy('id')
            ->get(['release_date', 'display_code', 'title_en', 'title_jp', 'cover_thumb_url', 'cover_url', 'maker']);

        $byDay = [];
        foreach ($rows as $r) {
            $date = substr((string) $r->release_date, 0, 10);
            $byDay[$date] ??= ['date' => $date, 'count' => 0, 'movies' => []];
            $byDay[$date]['count']++;
            if (count($byDay[$date]['movies']) < 8) {
                $byDay[$date]['movies'][] = ['code' => $r->display_code, 'title' => $r->title_en ?? $r->title_jp, 'cover_url' => $r->cover_thumb_url ?? $r->cover_url, 'maker' => $r->maker];
            }
        }

        return array_values($byDay);
    }
}
