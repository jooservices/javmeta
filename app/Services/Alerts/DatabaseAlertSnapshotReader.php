<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Repositories\AlertSnapshotServiceRepository;
use App\Services\Health\CrawlDispatchHeartbeat;
use Carbon\CarbonImmutable;

/** @phpstan-import-type AlertSnapshot from AlertSnapshotReader */
final class DatabaseAlertSnapshotReader implements AlertSnapshotReader
{
    public function __construct(
        private readonly CrawlDispatchHeartbeat $dispatchHeartbeat,
        private readonly AlertSnapshotServiceRepository $repository,
        private readonly BacklogGrowthSampler $growth,
    ) {}

    /** @return AlertSnapshot */
    public function snapshot(CarbonImmutable $cutoff): array
    {
        $now = CarbonImmutable::now();
        $attempts = $this->repository->attemptsBySource($cutoff, $now);
        $dueBySource = $this->repository->dueBySource();
        $enabledSources = $this->repository->enabledSourceSlugs();

        return [
            'attempts_by_source' => $attempts,
            'enabled_sources' => $enabledSources,
            'due_by_source' => $dueBySource,
            'oldest_due_age_by_source' => $this->repository->oldestDueAgeBySource($now),
            'due_growth_per_hour_by_source' => $this->growth->growthPerHour($dueBySource, $now),
            'postgres_connections' => $this->repository->postgresConnections(),
            'dispatch_age_seconds' => $this->dispatchHeartbeat->ageSeconds(),
            'has_due_work' => array_sum($dueBySource) > 0,
        ];
    }
}
