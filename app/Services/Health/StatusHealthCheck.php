<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Models\CrawlQueue;
use App\Models\Source;
use App\Services\Archive\MongoArchiveWriteHealth;
use App\Services\Crawl\WorkerHeartbeat;
use App\Services\Dependencies\Dependency;
use App\Services\Dependencies\DependencyMonitor;

final class StatusHealthCheck
{
    public function __construct(
        private readonly WorkerHeartbeat $heartbeat,
        private readonly DependencyMonitor $dependencies,
        private readonly MongoArchiveWriteHealth $archiveWrites,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function check(): array
    {
        $dependencyStatuses = $this->dependencies->statuses();
        $archiveStatus = $this->archiveWrites->status();
        $database = ($dependencyStatuses[Dependency::Postgres->value]['available'] ?? false) ? 'ok' : 'down';

        if ($database === 'down') {
            return [
                'status' => 'down',
                'checked_at' => now()->toIso8601String(),
                'database' => $database,
                'worker' => ['last_heartbeat_at' => null, 'stale' => true],
                'instances' => [],
                'queue' => ['pending' => 0, 'claimed' => 0, 'failed' => 0],
                'sources' => [],
                'dependencies' => $dependencyStatuses,
                'archive' => $archiveStatus,
            ];
        }

        $sources = Source::query()
            ->orderBy('priority')
            ->orderBy('slug')
            ->get(['slug', 'last_success_at', 'last_error_at']);

        $queue = [
            'pending' => CrawlQueue::query()->where('status', CrawlQueue::STATUS_PENDING)->count(),
            'claimed' => CrawlQueue::query()->where('status', CrawlQueue::STATUS_CLAIMED)->count(),
            'failed' => CrawlQueue::query()->where('status', CrawlQueue::STATUS_FAILED)->count(),
        ];

        $lastHeartbeat = $this->heartbeat->lastBeatAt();
        $staleSeconds = (int) config('javmeta_alerts.watchdog.heartbeat_stale_seconds', 600);
        $workerStale = $lastHeartbeat === null
            || $lastHeartbeat->diffInSeconds(now()) > $staleSeconds;

        $sourceRows = [];
        foreach ($sources as $source) {
            $lastSuccess = $source->getAttribute('last_success_at');
            $lastError = $source->getAttribute('last_error_at');

            $sourceRows[] = [
                'slug' => $source->slug,
                'last_success_at' => $lastSuccess instanceof \DateTimeInterface
                    ? $lastSuccess->format(DATE_ATOM)
                    : null,
                'last_error_at' => $lastError instanceof \DateTimeInterface
                    ? $lastError->format(DATE_ATOM)
                    : null,
            ];
        }

        $hardDependencyDown = false;
        $softDependencyDown = false;
        foreach ($dependencyStatuses as $dependencyStatus) {
            if ($dependencyStatus['available']) {
                continue;
            }

            if ($dependencyStatus['hard']) {
                $hardDependencyDown = true;
            } else {
                $softDependencyDown = true;
            }
        }

        $status = $hardDependencyDown
            ? 'down'
            : (($softDependencyDown || $workerStale || $archiveStatus['status'] === 'degraded') ? 'degraded' : 'ok');

        return [
            'status' => $status,
            'checked_at' => now()->toIso8601String(),
            'database' => $database,
            'worker' => [
                'last_heartbeat_at' => $lastHeartbeat?->toIso8601String(),
                'stale' => $workerStale,
            ],
            'instances' => $this->heartbeat->instances($staleSeconds),
            'queue' => $queue,
            'sources' => $sourceRows,
            'dependencies' => $dependencyStatuses,
            'archive' => $archiveStatus,
        ];
    }
}
