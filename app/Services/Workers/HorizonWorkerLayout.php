<?php

declare(strict_types=1);

namespace App\Services\Workers;

use Illuminate\Support\Str;

final class HorizonWorkerLayout
{
    /** @return array<string, array<string, mixed>> */
    public function supervisors(): array
    {
        $queueConfig = (array) config('javmeta_queues');
        $queues = (array) ($queueConfig['horizon_names'] ?? []);
        $browserQueues = (array) ($queueConfig['browser_names'] ?? []);
        $default = max(1, (int) ($queueConfig['workers_per_queue'] ?? 1));
        $direct = $queueConfig['direct_workers_per_queue'] ?? null;
        $browser = max(1, (int) ($queueConfig['browser_workers_per_queue'] ?? $default));
        $queueOverrides = (array) ($queueConfig['worker_queue_overrides'] ?? []);
        $nodeOverrides = $this->nodeOverrides((array) ($queueConfig['worker_node_overrides'] ?? []));
        $default = max(1, (int) ($nodeOverrides['default'] ?? $default));
        $direct = $nodeOverrides['direct'] ?? $direct;
        $browser = max(1, (int) ($nodeOverrides['browser'] ?? $browser));
        $queueOverrides = [...$queueOverrides, ...(array) ($nodeOverrides['queues'] ?? [])];

        $supervisors = [];
        foreach ($queues as $queue) {
            $isBrowser = in_array($queue, $browserQueues, true);
            $workers = $isBrowser ? $browser : ($direct === null ? $default : max(1, (int) $direct));
            $workers = max(1, min(32, (int) ($queueOverrides[$queue] ?? $workers)));
            $supervisorName = $queue === 'observability' ? 'api-observability' : 'crawler-' . $queue;
            $supervisors[$supervisorName] = [
                'connection' => 'redis',
                'queue' => [$queue],
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

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $supervisors = $this->supervisors();
        $encoded = json_encode($supervisors, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return [
            'node' => self::nodeId(),
            'role' => self::role(),
            'supervisors' => $supervisors,
            'hash' => hash('sha256', $encoded),
        ];
    }

    public function apply(): array
    {
        $snapshot = $this->snapshot();
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
    private function nodeOverrides(array $overrides): array
    {
        $role = self::role();
        $host = (string) (config('sentry.node') ?: gethostname() ?: 'unknown');
        $specific = $overrides[$role . '@' . $host] ?? $overrides[$role . '@' . Str::slug($host)] ?? [];
        $roleOverrides = (array) ($overrides[$role] ?? []);
        $combined = [...$roleOverrides, ...(array) $specific];
        if (isset($roleOverrides['queues']) || isset($specific['queues'])) {
            $combined['queues'] = [...(array) ($roleOverrides['queues'] ?? []), ...(array) ($specific['queues'] ?? [])];
        }

        return $combined;
    }
}
