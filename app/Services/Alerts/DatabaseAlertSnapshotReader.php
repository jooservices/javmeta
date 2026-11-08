<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Repositories\AlertSnapshotServiceRepository;
use App\Services\Health\CrawlDispatchHeartbeat;
use Carbon\CarbonImmutable;

final class DatabaseAlertSnapshotReader implements AlertSnapshotReader
{
    public function __construct(
        private readonly CrawlDispatchHeartbeat $dispatchHeartbeat,
        private readonly AlertSnapshotServiceRepository $repository,
    ) {}

    /** @return array{attempts_by_source: array<string, array{attempts: int, successes: int, blocked: int}>, enabled_sources: list<string>, hot_sources: list<string>, due_by_source: array<string, int>, postgres_connections: int|null, dispatch_age_seconds: int|null, has_due_work: bool} */
    public function snapshot(CarbonImmutable $cutoff): array
    {
        $attempts = $this->repository->attemptsBySource($cutoff, CarbonImmutable::now());
        $dueBySource = $this->repository->dueBySource();
        $enabledSources = $this->repository->enabledSourceSlugs();
        $hotSources = $this->repository->hotSourceSlugs($cutoff, array_keys($attempts));

        return [
            'attempts_by_source' => $attempts,
            'enabled_sources' => $enabledSources,
            'hot_sources' => $hotSources,
            'due_by_source' => $dueBySource,
            'postgres_connections' => $this->repository->postgresConnections(),
            'dispatch_age_seconds' => $this->dispatchHeartbeat->ageSeconds(),
            'has_due_work' => array_sum($dueBySource) > 0,
        ];
    }
}
