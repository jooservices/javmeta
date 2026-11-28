<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use Carbon\CarbonImmutable;

/**
 * @phpstan-type AlertSnapshot array{
 *     attempts_by_source: array<string, array{attempts: int, successes: int, blocked: int}>,
 *     enabled_sources: list<string>,
 *     due_by_source: array<string, int>,
 *     oldest_due_age_by_source: array<string, int>,
 *     due_growth_per_hour_by_source: array<string, int>,
 *     postgres_connections: int|null,
 *     dispatch_age_seconds: int|null,
 *     has_due_work: bool
 * }
 */
interface AlertSnapshotReader
{
    /**
     * Seconds are the age of the oldest due item per source; growth is due items per hour, negative when the backlog shrinks.
     *
     * @return AlertSnapshot
     */
    public function snapshot(CarbonImmutable $cutoff): array;
}
