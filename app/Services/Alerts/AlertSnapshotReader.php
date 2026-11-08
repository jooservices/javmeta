<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use Carbon\CarbonImmutable;

interface AlertSnapshotReader
{
    /**
     * @return array{
     *     attempts_by_source: array<string, array{attempts: int, successes: int, blocked: int}>,
     *     enabled_sources: list<string>,
     *     hot_sources: list<string>,
     *     due_by_source: array<string, int>,
     *     postgres_connections: int|null,
     *     dispatch_age_seconds: int|null,
     *     has_due_work: bool
     * }
     */
    public function snapshot(CarbonImmutable $cutoff): array;
}
