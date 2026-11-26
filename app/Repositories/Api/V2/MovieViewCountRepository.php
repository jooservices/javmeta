<?php

declare(strict_types=1);

namespace App\Repositories\Api\V2;

use Illuminate\Support\Facades\DB;

/** One counter per movie code: how often the UI opened it. */
final class MovieViewCountRepository
{
    /** Adds one view and returns the new total. */
    public function increment(string $code): int
    {
        $now = now();
        DB::table('movie_view_counts')->upsert([['code' => $code, 'views' => 1, 'updated_at' => $now]], ['code'], ['views' => DB::raw('movie_view_counts.views + 1'), 'updated_at' => $now]);

        return (int) DB::table('movie_view_counts')->where('code', $code)->value('views');
    }

    /**
     * @param list<string> $codes
     * @return array<string, int>
     */
    public function counts(array $codes): array
    {
        return $codes === [] ? [] : array_map('intval', DB::table('movie_view_counts')->whereIn('code', $codes)->pluck('views', 'code')->all());
    }
}
