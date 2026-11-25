<?php

declare(strict_types=1);

namespace App\Services\Api;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ResponseCacheEpoch
{
    public const KEY = 'javmeta:api-response:epoch:v2';

    public function current(): string
    {
        $store = Cache::store('api-response-epoch');
        $store->add(self::KEY, (string) Str::uuid(), (int) config('javmeta_response_cache.epoch_ttl_seconds', 31_536_000));
        $value = $store->get(self::KEY);

        if (! is_string($value) || $value === '') {
            throw new RuntimeException('The API response cache epoch is unavailable.');
        }

        return $value;
    }

    /**
     * Changes the epoch once the data transaction has committed (at once outside a transaction).
     *
     * The epoch is one row of the database cache. Written inside a data transaction, its row lock would
     * be held until that transaction ends and every concurrent persister would queue on it, which ends in
     * a deadlock (JOOXCR-719). After the commit the lock lasts one statement. A rolled back transaction
     * never changes the epoch, and a failed write fails the job, which is retried and bumps again.
     */
    public function bump(): void
    {
        DB::afterCommit(static function (): void {
            Cache::store('api-response-epoch')->put(
                self::KEY,
                (string) Str::uuid(),
                (int) config('javmeta_response_cache.epoch_ttl_seconds', 31_536_000),
            );
        });
    }
}
