<?php

declare(strict_types=1);

namespace App\Services\Api;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ResponseCacheEpoch
{
    public const KEY = 'javmeta:api-response:epoch:v1';

    public function current(): int
    {
        $store = Cache::store((string) config('javmeta_response_cache.store', 'redis'));
        $store->add(self::KEY, 1, (int) config('javmeta_response_cache.epoch_ttl_seconds', 31_536_000));
        $value = $store->get(self::KEY, 1);

        return is_numeric($value) ? max(1, (int) $value) : 1;
    }

    /** A cache outage must never prevent a committed dataset write. */
    public function bump(): void
    {
        try {
            $store = Cache::store((string) config('javmeta_response_cache.store', 'redis'));
            $store->add(self::KEY, 1, (int) config('javmeta_response_cache.epoch_ttl_seconds', 31_536_000));
            $store->increment(self::KEY);
        } catch (Throwable $exception) {
            try {
                Log::warning('api_response_cache.epoch_bump_failed', ['exception' => $exception::class]);
            } catch (Throwable) {
                // Cache invalidation telemetry is fail-open too.
            }
        }
    }
}
