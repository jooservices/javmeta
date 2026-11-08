<?php

declare(strict_types=1);

namespace App\Services\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class ElasticsearchRequestHealth
{
    private const FAILURE_COUNT_KEY = 'javmeta:elasticsearch:request_failures';

    private const LAST_FAILURE_KEY = 'javmeta:elasticsearch:last_request_failure';

    private const STATUS_KEY = 'javmeta:elasticsearch:request_status';

    /**
     * @return array{operation: string, status_code: int|null, failed_at: string}
     */
    public function recordFailure(string $operation, ?int $statusCode): array
    {
        $cache = Cache::store();
        $cache->add(self::FAILURE_COUNT_KEY, 0);
        $cache->increment(self::FAILURE_COUNT_KEY);

        $failure = [
            'operation' => $operation,
            'status_code' => $statusCode,
            'failed_at' => now()->toIso8601String(),
        ];
        $cache->put(self::LAST_FAILURE_KEY, $failure);
        $cache->put(self::STATUS_KEY, 'down');

        Log::warning('Elasticsearch request failed.', [
            'operation' => $operation,
            'status_code' => $statusCode,
        ]);

        return $failure;
    }

    public function recordSuccess(string $operation): void
    {
        $cache = Cache::store();
        $lastFailure = $cache->get(self::LAST_FAILURE_KEY);

        if (! is_array($lastFailure)) {
            return;
        }

        $monitoringOperations = ['health', 'metrics_probe', 'probe'];
        $previousOperation = $lastFailure['operation'] ?? null;
        if ($previousOperation === $operation
            || (in_array($previousOperation, $monitoringOperations, true)
                && in_array($operation, $monitoringOperations, true))
            || in_array($operation, ['index', 'search', 'setup', 'backfill'], true)) {
            $cache->put(self::STATUS_KEY, 'up');
        }
    }

    /**
     * @return array{
     *     status: string,
     *     failed_requests: int,
     *     last_failure: array{operation: string, status_code: int|null, failed_at: string}|null
     * }
     */
    public function status(): array
    {
        $cache = Cache::store();
        $lastFailure = $cache->get(self::LAST_FAILURE_KEY);

        return [
            'status' => (string) $cache->get(self::STATUS_KEY, 'unknown'),
            'failed_requests' => (int) $cache->get(self::FAILURE_COUNT_KEY, 0),
            'last_failure' => is_array($lastFailure) ? $lastFailure : null,
        ];
    }

    public function failureCount(): int
    {
        return (int) Cache::store()->get(self::FAILURE_COUNT_KEY, 0);
    }
}
