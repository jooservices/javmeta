<?php

declare(strict_types=1);

namespace App\Services\Health;

use Illuminate\Support\Facades\Cache;

final class SchedulerHeartbeat
{
    public const CACHE_KEY = 'javmeta:scheduler:heartbeat';

    public const STALE_AFTER_SECONDS = 180;

    public function beat(): void
    {
        Cache::put(self::CACHE_KEY, now()->getTimestamp(), now()->addSeconds(self::STALE_AFTER_SECONDS * 2));
    }

    public function ageSeconds(): ?int
    {
        $timestamp = Cache::get(self::CACHE_KEY);
        if (! is_numeric($timestamp)) {
            return null;
        }

        return now()->getTimestamp() - (int) $timestamp;
    }

    public function isFresh(): bool
    {
        $age = $this->ageSeconds();

        return $age !== null && $age >= 0 && $age <= self::STALE_AFTER_SECONDS;
    }
}
