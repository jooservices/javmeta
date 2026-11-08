<?php

declare(strict_types=1);

namespace App\Services\Merge;

use App\Models\MovieObservation;
use Illuminate\Support\Collection;

/**
 * BR-2 source-priority conflict policy. The observation from the most
 * authoritative source wins: priorities come from
 * config('javmeta_sources.sources.<slug>.priority') where the smallest
 * number is the highest authority (D-7 order, javdb=10). Ties resolve to
 * the most recently crawled observation, then to the newest row id for
 * determinism. A slug missing from the config is treated as the least
 * authoritative source. Null-valued observations only win when no source
 * reported a value (they keep the drift signal for the sharp-drop guard).
 */
final class SourcePriorityConflictPolicy implements ConflictPolicy
{
    private const UNKNOWN_SOURCE_PRIORITY = 1000;

    public function pick(string $field, Collection $observations): ?MovieObservation
    {
        if ($observations->isEmpty()) {
            return null;
        }

        // Only each source's latest observation counts (an older crawl of the
        // same source is history). A source that reported nothing for the field
        // must not hide a value another source did report, so observations that
        // carry a value are preferred; the null ones win only when every source
        // reported nothing, which keeps the drift signal for the sharp-drop guard.
        $latest = $observations
            ->sortBy([['crawled_at', 'desc'], ['id', 'desc']])
            ->groupBy('source_slug')
            ->map(static fn(Collection $bySource): MovieObservation => $bySource->first())
            ->values();
        $withValue = $latest->filter(static fn(MovieObservation $observation): bool => $observation->value !== null);
        $observations = $withValue->isNotEmpty() ? $withValue->values() : $latest;

        // Primary: source priority (ascending number = highest authority).
        // Secondary: most recent crawled_at, then newest id. PHP sort is
        // stable, so the second pass preserves the recency order within a
        // priority group.
        return $observations
            ->sortBy([
                ['crawled_at', 'desc'],
                ['id', 'desc'],
            ])
            ->sortBy(fn(MovieObservation $observation): int => $this->priority($observation->source_slug))
            ->first();
    }

    private function priority(string $sourceSlug): int
    {
        $priority = config("javmeta_sources.sources.{$sourceSlug}.priority");

        return is_numeric($priority) ? (int) $priority : self::UNKNOWN_SOURCE_PRIORITY;
    }
}
