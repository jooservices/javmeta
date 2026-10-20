<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Models\CrawlQueue;
use App\Models\Source;
use App\Services\Archive\MongoArchiveWriteHealth;
use App\Services\Crawl\WorkerHeartbeat;
use App\Services\Dependencies\Dependency;
use App\Services\Dependencies\DependencyMonitor;
use App\Services\Search\ElasticsearchRequestHealth;

final class StatusHealthCheck
{
    public function __construct(
        private readonly WorkerHeartbeat $heartbeat,
        private readonly DependencyMonitor $dependencies,
        private readonly MongoArchiveWriteHealth $archiveWrites,
        private readonly ElasticsearchRequestHealth $elasticsearchRequestHealth,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $dependencyStatuses = $this->dependencies->statuses();
        $database = ($dependencyStatuses[Dependency::Postgres->value]['available'] ?? false) ? 'ok' : 'down';

        if ($database === 'down') {
            return $this->databaseDownStatus($dependencyStatuses);
        }

        $dependencyStatuses = $this->withElasticsearchRequestHealth($dependencyStatuses);
        $archiveStatus = $this->archiveWrites->status();
        return $this->healthyStatus($dependencyStatuses, $archiveStatus);
    }

    /**
     * @param array<string, array<string, mixed>> $dependencyStatuses
     * @param array{status: string, failed_writes: int, last_failure_at: string|null} $archiveStatus
     * @return array<string, mixed>
     */
    private function healthyStatus(array $dependencyStatuses, array $archiveStatus): array
    {
        $sourceRows = $this->sourceRows();

        $queue = $this->queueCounts();

        $lastHeartbeat = $this->heartbeat->lastBeatAt();
        $staleSeconds = (int) config('javmeta_alerts.watchdog.heartbeat_stale_seconds', 600);
        $workerStale = $lastHeartbeat === null
            || $lastHeartbeat->diffInSeconds(now()) > $staleSeconds;

        $dependencyOutage = $this->dependencyOutage($dependencyStatuses);

        $status = $dependencyOutage['hard']
            ? 'down'
            : (($dependencyOutage['soft'] || $workerStale || $archiveStatus['status'] === 'degraded') ? 'degraded' : 'ok');

        return [
            'status' => $status,
            'checked_at' => now()->toIso8601String(),
            'database' => 'ok',
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

    /** @return array{pending: int, claimed: int, failed: int} */
    private function queueCounts(): array
    {
        return [
            'pending' => CrawlQueue::query()->where('status', CrawlQueue::STATUS_PENDING)->count(),
            'claimed' => CrawlQueue::query()->where('status', CrawlQueue::STATUS_CLAIMED)->count(),
            'failed' => CrawlQueue::query()->where('status', CrawlQueue::STATUS_FAILED)->count(),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $dependencyStatuses
     * @return array{hard: bool, soft: bool}
     */
    private function dependencyOutage(array $dependencyStatuses): array
    {
        $hard = false;
        $soft = false;
        foreach ($dependencyStatuses as $dependencyStatus) {
            if ($dependencyStatus['available']) {
                continue;
            }

            if ($dependencyStatus['hard']) {
                $hard = true;
            } else {
                $soft = true;
            }
        }

        return ['hard' => $hard, 'soft' => $soft];
    }

    /** @param array<string, array<string, mixed>> $dependencyStatuses
     *  @return array<string, mixed>
     */
    private function databaseDownStatus(array $dependencyStatuses): array
    {
        return [
            'status' => 'down',
            'checked_at' => now()->toIso8601String(),
            'database' => 'down',
            'worker' => ['last_heartbeat_at' => null, 'stale' => true],
            'instances' => [],
            'queue' => ['pending' => 0, 'claimed' => 0, 'failed' => 0],
            'sources' => [],
            'dependencies' => $dependencyStatuses,
            'archive' => [
                'status' => 'unknown',
                'failed_writes' => null,
                'last_failure_at' => null,
            ],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $dependencyStatuses
     * @return array<string, array<string, mixed>>
     */
    private function withElasticsearchRequestHealth(array $dependencyStatuses): array
    {
        $elasticsearchHealth = $this->elasticsearchRequestHealth->status();
        if (isset($dependencyStatuses[Dependency::Elasticsearch->value])) {
            $dependencyStatuses[Dependency::Elasticsearch->value]['request_failures'] = $elasticsearchHealth['failed_requests'];
            $dependencyStatuses[Dependency::Elasticsearch->value]['last_failure'] = $elasticsearchHealth['last_failure'];

            if ($elasticsearchHealth['status'] === 'down') {
                $dependencyStatuses[Dependency::Elasticsearch->value]['available'] = false;
                $dependencyStatuses[Dependency::Elasticsearch->value]['state'] = 'down';
            }
        }

        return $dependencyStatuses;
    }

    /** @return list<array{slug: string, last_success_at: string|null, last_error_at: string|null}> */
    private function sourceRows(): array
    {
        $sources = Source::query()
            ->orderBy('priority')
            ->orderBy('slug')
            ->get(['slug', 'last_success_at', 'last_error_at']);

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

        return $sourceRows;
    }
}
