<?php

declare(strict_types=1);

namespace App\Observability;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class OllamaChatMetrics
{
    private const FAILURE_COUNT_KEY = 'javmeta:ollama:chat_failures';

    private const CIRCUIT_UNTIL_KEY = 'javmeta:ollama:chat_circuit_until';

    public function circuitOpen(): bool
    {
        return (int) Cache::get(self::CIRCUIT_UNTIL_KEY, 0) > now()->getTimestamp();
    }

    public function recordFailure(string $outcome, ?int $httpStatus): void
    {
        Cache::add(self::FAILURE_COUNT_KEY, 0);
        Cache::increment(self::FAILURE_COUNT_KEY);
        Cache::put(self::CIRCUIT_UNTIL_KEY, now()->getTimestamp() + 60, 60);

        Log::warning('javmeta.ollama.chat_fallback', [
            'outcome' => $outcome,
            'http_status' => $httpStatus,
            'circuit_seconds' => 60,
        ]);
    }

    public function recordSuccess(): void
    {
        Cache::forget(self::CIRCUIT_UNTIL_KEY);
    }

    public function failureCount(): int
    {
        return (int) Cache::get(self::FAILURE_COUNT_KEY, 0);
    }
}
