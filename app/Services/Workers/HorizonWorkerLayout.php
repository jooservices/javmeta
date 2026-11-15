<?php

declare(strict_types=1);

namespace App\Services\Workers;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Illuminate\Support\Str;

final class HorizonWorkerLayout
{
    /** @return array<string, array<string, mixed>> */
    public function supervisors(): array
    {
        return $this->supervisorsForNode(self::role(), $this->currentHost());
    }

    /** Count the configured Horizon worker processes across the crawler fleet. */
    public function configuredFleetWorkerCount(): int
    {
        $nodeOverrides = (array) config('javmeta_queues.worker_node_overrides', []);
        $fleetProcesses = 0;
        foreach (['main', 'worker'] as $role) {
            $nodeCount = $this->nodeCountForRole($role);
            if ($nodeCount === 0) {
                continue;
            }

            $hosts = [];
            foreach (array_keys($nodeOverrides) as $key) {
                $prefix = $role . '@';
                if (! is_string($key) || ! str_starts_with($key, $prefix)) {
                    continue;
                }

                $host = substr($key, strlen($prefix));
                if ($host !== '') {
                    $hosts[Str::slug($host)] = $host;
                }
            }

            $baseProcesses = $this->workerProcessCount($this->supervisorsForNode($role, null));
            $specificProcesses = array_map(
                fn(string $host): int => $this->workerProcessCount($this->supervisorsForNode($role, $host)),
                array_values($hosts),
            );
            $fleetProcesses += $baseProcesses * max(0, $nodeCount - count($specificProcesses)) + array_sum($specificProcesses);
        }

        return $fleetProcesses;
    }

    /** @return array<string, array<string, mixed>> */
    private function supervisorsForNode(string $role, ?string $host): array
    {
        $queueConfig = (array) config('javmeta_queues');
        $queues = (array) ($queueConfig['horizon_names'] ?? []);
        $searchQueue = (string) ($queueConfig['search_queue'] ?? 'search');
        $downloadSearchQueue = (string) ($queueConfig['download_search_queue'] ?? 'download_search');
        $browserQueues = (array) ($queueConfig['browser_names'] ?? []);
        $retryQueues = (array) ($queueConfig['retry_names'] ?? []);
        $default = max(1, (int) ($queueConfig['workers_per_queue'] ?? 1));
        $direct = $queueConfig['direct_workers_per_queue'] ?? null;
        $browser = max(1, (int) ($queueConfig['browser_workers_per_queue'] ?? $default));
        $retry = max(1, (int) ($queueConfig['retry']['workers_per_queue'] ?? 1));
        $queueOverrides = (array) ($queueConfig['worker_queue_overrides'] ?? []);
        $nodeOverrides = $this->nodeOverrides((array) ($queueConfig['worker_node_overrides'] ?? []), $role, $host);
        $default = max(1, (int) ($nodeOverrides['default'] ?? $default));
        $direct = $nodeOverrides['direct'] ?? $direct;
        $browser = max(1, (int) ($nodeOverrides['browser'] ?? $browser));
        $retry = max(1, (int) ($nodeOverrides['retry'] ?? $retry));
        $queueOverrides = [...$queueOverrides, ...(array) ($nodeOverrides['queues'] ?? [])];

        $supervisors = [];
        foreach ($queues as $queue) {
            if ($queue === $downloadSearchQueue && in_array($searchQueue, $queues, true)) {
                continue;
            }

            $supervisorQueues = [$queue];
            if ($queue === $searchQueue && in_array($downloadSearchQueue, $queues, true)) {
                $supervisorQueues[] = $downloadSearchQueue;
            }

            $isBrowser = in_array($queue, $browserQueues, true);
            $isRetry = in_array($queue, $retryQueues, true);
            $defaultWorkers = $isRetry ? $retry : ($isBrowser ? $browser : ($direct === null ? $default : max(1, (int) $direct)));
            $workers = 0;
            foreach ($supervisorQueues as $supervisorQueue) {
                $workers = max($workers, max(1, min(32, (int) ($queueOverrides[$supervisorQueue] ?? $defaultWorkers))));
            }
            $supervisorName = $queue === 'observability' ? 'api-observability' : 'crawler-' . $queue;
            $supervisors[$supervisorName] = [
                'connection' => 'redis',
                'queue' => $supervisorQueues,
                'balance' => false,
                'maxProcesses' => $workers,
                'maxTime' => (int) $queueConfig['queue_max_time'],
                'maxJobs' => (int) $queueConfig['queue_max_jobs'],
                'memory' => 128,
                'tries' => (int) $queueConfig['queue_tries'],
                'backoff' => (int) $queueConfig['queue_backoff_seconds'],
                'timeout' => (int) $queueConfig['worker_timeout'],
                'nice' => 0,
            ];
        }

        return $supervisors;
    }

    /** @param array<string, array<string, mixed>> $supervisors */
    private function workerProcessCount(array $supervisors): int
    {
        return array_sum(self::workerCounts($supervisors));
    }

    private function nodeCountForRole(string $role): int
    {
        $setting = $role === 'main' ? 'primary_nodes' : 'worker_nodes';
        $nodeCount = (int) config('javmeta_queues.postgres_connection_budget.' . $setting, $role === 'main' ? 1 : 2);
        if ($nodeCount < ($role === 'main' ? 0 : 1)) {
            throw new InvalidArgumentException('PostgreSQL Horizon node counts are invalid.');
        }

        return $nodeCount;
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $supervisors = $this->supervisors();
        return $this->snapshotForSupervisors($supervisors);
    }

    /** @param array<string, array<string, mixed>> $supervisors
     *  @return array<string, mixed>
     */
    private function snapshotForSupervisors(array $supervisors): array
    {
        $encoded = json_encode($supervisors, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return [
            'node' => self::nodeId(),
            'role' => self::role(),
            'supervisors' => $supervisors,
            'hash' => hash('sha256', $encoded),
        ];
    }

    /** @param array<string, array<string, mixed>> $supervisors
     *  @return array<string, int>
     */
    public static function workerCounts(array $supervisors): array
    {
        $counts = [];
        foreach ($supervisors as $supervisor) {
            $queue = $supervisor['queue'][0] ?? null;
            if (is_string($queue)) {
                $counts[$queue] = max(0, (int) ($supervisor['maxProcesses'] ?? 0));
            }
        }

        ksort($counts);

        return $counts;
    }

    public function apply(): array
    {
        $snapshot = $this->snapshot();
        $budget = app(PostgresConnectionBudget::class);
        $summary = $budget->summary($this->configuredFleetWorkerCount());
        if (! $summary['within_budget']) {
            $defaults = (array) config('horizon.defaults', []);
            $defaultFleetDemand = $this->workerProcessCount($defaults)
                * ($this->nodeCountForRole('main') + $this->nodeCountForRole('worker'));
            $budget->assertWithinBudget($defaultFleetDemand);
            $snapshot = $this->snapshotForSupervisors($defaults);
            Log::warning('Horizon worker settings exceed the PostgreSQL connection budget; keeping the code default layout.', [
                'node' => $snapshot['node'],
                'configured_demand' => $summary['demand'],
                'budget_limit' => $summary['budget_limit'],
            ]);
        }

        $environments = array_fill_keys(['production', 'staging', 'local', 'testing'], $snapshot['supervisors']);
        config(['horizon.defaults' => $snapshot['supervisors'], 'horizon.environments' => $environments]);
        config(['javmeta_workers.booted_layout' => $snapshot]);

        return $snapshot;
    }

    public static function role(): string
    {
        $role = (string) config('javmeta_workers.role', 'main');

        return in_array($role, ['main', 'worker'], true) ? $role : 'main';
    }

    public static function nodeId(): string
    {
        $host = (string) (config('sentry.node') ?: gethostname() ?: 'unknown');

        return self::role() . '-' . Str::slug($host);
    }

    /** @param array<string, mixed> $overrides
     *  @return array<string, mixed>
     */
    private function nodeOverrides(array $overrides, string $role, ?string $host): array
    {
        $specific = $host === null ? [] : ($overrides[$role . '@' . $host] ?? $overrides[$role . '@' . Str::slug($host)] ?? []);
        $roleOverrides = (array) ($overrides[$role] ?? []);
        $combined = [...$roleOverrides, ...(array) $specific];
        if (isset($roleOverrides['queues']) || isset($specific['queues'])) {
            $combined['queues'] = [...(array) ($roleOverrides['queues'] ?? []), ...(array) ($specific['queues'] ?? [])];
        }

        return $combined;
    }

    private function currentHost(): string
    {
        return (string) (config('sentry.node') ?: gethostname() ?: 'unknown');
    }
}
