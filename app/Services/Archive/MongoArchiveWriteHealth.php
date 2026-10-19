<?php

declare(strict_types=1);

namespace App\Services\Archive;

use Illuminate\Support\Facades\Cache;

final class MongoArchiveWriteHealth
{
    private const FAILURE_COUNT_KEY = 'javmeta:mongo_archive:write_failures';

    private const LAST_WRITE_STATUS_KEY = 'javmeta:mongo_archive:last_write_status';

    private const LAST_FAILURE_AT_KEY = 'javmeta:mongo_archive:last_failure_at';

    public function recordFailure(): void
    {
        $cache = Cache::store();
        $cache->add(self::FAILURE_COUNT_KEY, 0);
        $cache->increment(self::FAILURE_COUNT_KEY);
        $cache->put(self::LAST_WRITE_STATUS_KEY, 'degraded');
        $cache->put(self::LAST_FAILURE_AT_KEY, now()->toIso8601String());
    }

    public function recordSuccess(): void
    {
        Cache::store()->put(self::LAST_WRITE_STATUS_KEY, 'ok');
    }

    /**
     * @return array{status: string, failed_writes: int, last_failure_at: string|null}
     */
    public function status(): array
    {
        $cache = Cache::store();

        return [
            'status' => (string) $cache->get(self::LAST_WRITE_STATUS_KEY, 'ok'),
            'failed_writes' => (int) $cache->get(self::FAILURE_COUNT_KEY, 0),
            'last_failure_at' => $cache->get(self::LAST_FAILURE_AT_KEY),
        ];
    }

    public function failureCount(): int
    {
        return (int) Cache::store()->get(self::FAILURE_COUNT_KEY, 0);
    }
}
