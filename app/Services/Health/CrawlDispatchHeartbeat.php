<?php

declare(strict_types=1);

namespace App\Services\Health;

use Illuminate\Support\Facades\Cache;

final class CrawlDispatchHeartbeat
{
    public const CACHE_KEY = 'javmeta:crawl:last-dispatched-at';

    public function record(): void
    {
        Cache::forever(self::CACHE_KEY, now()->getTimestamp());
    }

    public function ageSeconds(): ?int
    {
        $timestamp = Cache::get(self::CACHE_KEY);
        if (! is_numeric($timestamp)) {
            return null;
        }

        return max(0, now()->getTimestamp() - (int) $timestamp);
    }
}
