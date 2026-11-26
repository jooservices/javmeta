<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Catalogue;

use App\Services\Api\V2\Contracts\MovieViewCounter;
use Illuminate\Support\Facades\DB;

/** Keeps one counter per movie code in movie_view_counts; a view by anyone, signed in or not, adds one. */
final class StoredMovieViewCounter implements MovieViewCounter
{
    public function record(string $code, ?int $userId): int
    {
        $now = now();
        DB::table('movie_view_counts')->upsert([['code' => $code, 'views' => 1, 'updated_at' => $now]], ['code'], ['views' => DB::raw('movie_view_counts.views + 1'), 'updated_at' => $now]);

        return (int) DB::table('movie_view_counts')->where('code', $code)->value('views');
    }

    public function counts(array $codes): array
    {
        $found = $codes === [] ? [] : DB::table('movie_view_counts')->whereIn('code', $codes)->pluck('views', 'code')->all();

        return array_map(static fn(string $code): int => (int) ($found[$code] ?? 0), array_combine($codes, $codes));
    }
}
