<?php

declare(strict_types=1);

namespace App\Services\Crawl;

final class FeedPoolStopReason
{
    public const REASONS = [
        'exhausted_budget',
        'no_due_rows',
        'depth',
        'global_cap',
        'advisory_lock',
        'per_source_inflight',
        'interval_not_elapsed',
    ];

    /**
     * @param  list<string>  $excludedSources
     * @param  list<string>  $sourcesAtInflightLimit
     */
    public static function forClaim(array $excludedSources, array $sourcesAtInflightLimit): string
    {
        if ($excludedSources !== []) {
            return 'exhausted_budget';
        }

        if ($sourcesAtInflightLimit !== []) {
            return 'per_source_inflight';
        }

        return 'no_due_rows';
    }

    public static function forQueue(
        bool $intervalElapsed,
        bool $advisoryLockAcquired,
        bool $globalCapAvailable,
        bool $depthAvailable,
        ?string $claimStopReason = null,
    ): ?string {
        if (! $intervalElapsed) {
            return 'interval_not_elapsed';
        }

        if (! $advisoryLockAcquired) {
            return 'advisory_lock';
        }

        if (! $globalCapAvailable) {
            return 'global_cap';
        }

        if (! $depthAvailable) {
            return 'depth';
        }

        return in_array($claimStopReason, ['exhausted_budget', 'no_due_rows', 'per_source_inflight'], true)
            ? $claimStopReason
            : null;
    }
}
