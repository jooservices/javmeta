<?php

declare(strict_types=1);

namespace App\Services\Api;

use Illuminate\Support\Facades\Cache;
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

    /** The database cache shares the data transaction, so a failed invalidation rolls the write back. */
    public function bump(): void
    {
        Cache::store('api-response-epoch')->put(
            self::KEY,
            (string) Str::uuid(),
            (int) config('javmeta_response_cache.epoch_ttl_seconds', 31_536_000),
        );
    }
}
