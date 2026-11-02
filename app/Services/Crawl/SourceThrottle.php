<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Models\Source;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

final class SourceThrottle
{
    public function currentGap(Source|string $source): float
    {
        $model = $this->source($source);

        return (float) ($model->gap_seconds_current ?? $model->gap_seconds_default);
    }

    public function effectiveGap(Source|string $source): float
    {
        $minimum = max(0.25, (float) config('javmeta_queues.min_effective_gap_seconds', 1.0));

        return max($minimum, $this->currentGap($source));
    }

    public function waitForRequestStart(Source|string $source): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        $slug = $source instanceof Source ? (string) $source->slug : $source;
        while (($delay = $this->requestStartDelayMicroseconds($slug, (int) floor(microtime(true) * 1_000_000))) > 0) {
            usleep($delay);
        }
    }

    public function requestStartDelayMicroseconds(Source|string $source, int $nowMicroseconds): int
    {
        $model = $this->source($source);
        $gapMicroseconds = (int) ceil($this->effectiveGap($model) * 1_000_000);
        $key = 'javmeta:crawl:source-last-start:' . $model->slug;
        $lock = Cache::lock($key . ':lock', 5);
        $lock->block(5);

        try {
            $lastStart = (int) Cache::get($key, 0);
            $nextStart = $lastStart > 0 ? $lastStart + $gapMicroseconds : $nowMicroseconds;
            if ($nowMicroseconds < $nextStart) {
                return $nextStart - $nowMicroseconds;
            }

            $retentionSeconds = max(
                120,
                (int) ceil((float) ($model->gap_seconds_max ?? 0)) + 2,
                (int) ceil((float) config('javmeta_queues.gap_blocked_max_seconds', 600)) + 2,
            );
            Cache::put($key, $nowMicroseconds, $retentionSeconds);

            return 0;
        } finally {
            $lock->release();
        }
    }

    public function onSuccess(Source|string $source): Source
    {
        $model = $this->source($source);
        $minimum = (float) ($model->gap_seconds_min ?? $model->gap_seconds_default);
        $current = $this->currentGap($model);
        // Recover quickly from a big gap: 10 percent of the distance to the minimum (at least the old fixed step).
        $step = max(0.5, $minimum * 0.10, ($current - $minimum) * 0.10);

        $model->forceFill([
            'gap_seconds_current' => max($minimum, $current - $step),
        ])->save();

        return $model->refresh();
    }

    /**
     * The gap adapts to what the site answers: rate limits, blocks and challenges double it up to a high ceiling,
     * timeouts and network errors raise it by half, errors that say nothing about politeness (parse, not found) leave it alone.
     */
    public function onFailure(Source|string $source, ?string $errorCode = null): Source
    {
        $model = $this->source($source);
        $maximum = (float) ($model->gap_seconds_max ?? $model->gap_seconds_default);
        $current = max($this->currentGap($model), 1.0);

        $next = match (true) {
            in_array($errorCode, ['rate_limited', 'blocked', 'challenge'], true) => min(
                max($maximum, (float) config('javmeta_queues.gap_blocked_max_seconds', 600)),
                $current * 2,
            ),
            in_array($errorCode, ['timeout', 'network'], true) => min($maximum, $current * 1.5),
            in_array($errorCode, ['parse_failed', 'normalization_failed', 'not_found', 'soft404', 'gone', 'unsupported_url', 'ambiguous_url', 'adapter_not_found'], true) => $current,
            default => min($maximum, $current * 2),
        };

        $model->forceFill(['gap_seconds_current' => $next])->save();

        return $model->refresh();
    }

    private function source(Source|string $source): Source
    {
        if ($source instanceof Source) {
            return $source;
        }

        $model = Source::query()->find($source);
        if (! $model instanceof Source) {
            throw new InvalidArgumentException("Unknown source [{$source}].");
        }

        return $model;
    }
}
