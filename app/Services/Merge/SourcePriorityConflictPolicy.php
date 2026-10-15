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
 * authoritative source. Null-valued observations are ordinary values here
 * (the source reported nothing for the field) and can win the pick.
 */
final class SourcePriorityConflictPolicy implements ConflictPolicy
{
    private const UNKNOWN_SOURCE_PRIORITY = 1000;

    public function pick(string $field, Collection $observations): ?MovieObservation
    {
        if ($observations->isEmpty()) {
            return null;
        }

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
