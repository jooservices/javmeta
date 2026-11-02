<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use Illuminate\Support\Facades\Cache;

final class FeedPoolMetrics
{
    public const STOP_REASONS = [
        'exhausted_budget',
        'no_due_rows',
        'depth',
        'global_cap',
        'advisory_lock',
        'per_source_inflight',
    ];

    public function recordDispatched(string $sourceSlug): void
    {
        $this->increment('dispatched', $sourceSlug);
    }

    public function recordStopped(string $sourceSlug, string $reason): void
    {
        if (! in_array($reason, self::STOP_REASONS, true)) {
            return;
        }

        $this->increment('stopped:' . $reason, $sourceSlug);
    }

    public function dispatchedPreviousMinute(string $sourceSlug): int
    {
        return $this->previousMinuteCount('dispatched', $sourceSlug);
    }

    public function stoppedPreviousMinute(string $sourceSlug, string $reason): int
    {
        return $this->previousMinuteCount('stopped:' . $reason, $sourceSlug);
    }

    private function increment(string $metric, string $sourceSlug): void
    {
        $key = $this->key($metric, $sourceSlug, intdiv(now()->timestamp, 60));
        Cache::add($key, 0, 120);
        Cache::increment($key);
    }

    private function previousMinuteCount(string $metric, string $sourceSlug): int
    {
        $bucket = intdiv(now()->timestamp, 60) - 1;

        return (int) Cache::get($this->key($metric, $sourceSlug, $bucket), 0);
    }

    private function key(string $metric, string $sourceSlug, int $minute): string
    {
        return "javmeta:crawl:feed-metric:{$metric}:{$sourceSlug}:{$minute}";
    }
}
