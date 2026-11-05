<?php

declare(strict_types=1);

namespace App\Services\Workers;

use InvalidArgumentException;

final class PostgresConnectionBudget
{
    /** @return array{worker_processes: int, connections_per_worker: int, demand: int, role_limit: int, reserve: int, budget_limit: int, warning_threshold: float, warning: bool, within_budget: bool} */
    public function summary(int $configuredWorkerProcesses): array
    {
        if ($configuredWorkerProcesses < 0) {
            throw new InvalidArgumentException('Configured worker process count cannot be negative.');
        }

        $budget = (array) config('javmeta_queues.postgres_connection_budget', []);
        $roleLimit = (int) ($budget['role_limit'] ?? 120);
        $reserve = (int) ($budget['reserve'] ?? 30);
        $warningPercent = (int) config('javmeta_alerts.postgres_connection_budget_warning_percent', 75);
        if ($roleLimit < 1 || $reserve < 0 || $reserve >= $roleLimit || $warningPercent < 1 || $warningPercent > 100) {
            throw new InvalidArgumentException('PostgreSQL connection budget configuration is invalid.');
        }

        $connectionsPerWorker = $this->postgresConnectionSlotsPerWorker();
        $demand = $configuredWorkerProcesses * $connectionsPerWorker;
        $budgetLimit = $roleLimit - $reserve;
        $warningThreshold = (float) ($roleLimit * $warningPercent) / 100;

        return [
            'worker_processes' => $configuredWorkerProcesses,
            'connections_per_worker' => $connectionsPerWorker,
            'demand' => $demand,
            'role_limit' => $roleLimit,
            'reserve' => $reserve,
            'budget_limit' => $budgetLimit,
            'warning_threshold' => $warningThreshold,
            'warning' => $demand >= $warningThreshold,
            'within_budget' => $demand <= $budgetLimit,
        ];
    }

    public function assertWithinBudget(int $configuredWorkerProcesses): void
    {
        $summary = $this->summary($configuredWorkerProcesses);
        if (! $summary['within_budget']) {
            throw new InvalidArgumentException(sprintf(
                'Configured PostgreSQL connection demand (%d) exceeds the safe role budget (%d).',
                $summary['demand'],
                $summary['budget_limit'],
            ));
        }
    }

    private function postgresConnectionSlotsPerWorker(): int
    {
        $slots = 0;
        foreach ((array) config('database.connections', []) as $connection) {
            if (($connection['driver'] ?? null) !== 'pgsql') {
                continue;
            }

            $slots++;
            if (isset($connection['read']) || isset($connection['write'])) {
                $slots++;
            }
        }

        return max(1, $slots);
    }
}
