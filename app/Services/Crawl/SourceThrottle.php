<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Models\Source;
use InvalidArgumentException;

final class SourceThrottle
{
    public function currentGap(Source|string $source): float
    {
        $model = $this->source($source);

        return (float) ($model->gap_seconds_current ?? $model->gap_seconds_default);
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
