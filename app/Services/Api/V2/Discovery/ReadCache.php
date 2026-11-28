<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Discovery;

use Closure;
use Illuminate\Support\Facades\Cache;

/** Short-lived cache of expensive, shared, read-only answers (statistics, highlights, catalogue word lists). */
final class ReadCache
{
    /**
     * @template T
     * @param Closure(): T $compute
     * @return T
     */
    public function remember(string $key, int $seconds, Closure $compute): mixed
    {
        return Cache::remember('api-v2:' . $key, $seconds, $compute);
    }
}
