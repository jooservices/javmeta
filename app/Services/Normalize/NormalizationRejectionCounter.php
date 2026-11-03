<?php

declare(strict_types=1);

namespace App\Services\Normalize;

use Illuminate\Support\Facades\DB;
use Throwable;

/** Stores bounded cumulative rejection counters for the normalization metric. */
final class NormalizationRejectionCounter
{
    public function increment(string $entity, string $field, string $source): void
    {
        $source = preg_match('/^[a-z0-9_-]{1,100}$/i', $source) === 1 ? $source : 'unknown';
        $identity = ['entity' => $entity, 'field' => $field, 'source' => $source];

        try {
            $updated = DB::table('normalization_rejections')->where($identity)->increment(
                'rejected_total',
                1,
                ['updated_at' => now()],
            );
            if ($updated > 0) {
                return;
            }

            $inserted = DB::table('normalization_rejections')->insertOrIgnore([
                ...$identity,
                'rejected_total' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($inserted === 0) {
                DB::table('normalization_rejections')->where($identity)->increment(
                    'rejected_total',
                    1,
                    ['updated_at' => now()],
                );
            }
        } catch (Throwable) {
            // Crawling remains available if metrics cannot be recorded.
        }
    }
}
