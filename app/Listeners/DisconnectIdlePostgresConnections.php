<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Horizon\Events\MasterSupervisorLooped;
use Laravel\Horizon\Events\SupervisorLooped;
use Throwable;

final class DisconnectIdlePostgresConnections
{
    public function afterQueueLoop(Looping $event): void
    {
        $this->disconnectIdleConnections();
    }

    public function afterScheduledTask(ScheduledTaskFinished|ScheduledTaskFailed $event): void
    {
        $this->disconnectIdleConnections();
    }

    public function afterHorizonLoop(SupervisorLooped|MasterSupervisorLooped $event): void
    {
        $this->disconnectIdleConnections();
    }

    public function afterScheduleWorkStarted(CommandStarting $event): void
    {
        if ($event->command !== 'schedule:work') {
            return;
        }

        $this->disconnectIdleConnections();
    }

    private function disconnectIdleConnections(): void
    {
        foreach ((array) config('database.connections', []) as $name => $configuration) {
            if (! is_string($name) || ! is_array($configuration) || ($configuration['driver'] ?? null) !== 'pgsql') {
                continue;
            }

            try {
                $connection = DB::connection($name);
                if ($connection->transactionLevel() > 0) {
                    Log::warning('Kept a PostgreSQL connection open because a transaction remains active.', [
                        'connection' => $name,
                        'transaction_level' => $connection->transactionLevel(),
                    ]);

                    continue;
                }

                DB::disconnect($name);
            } catch (Throwable $exception) {
                Log::warning('Could not release an idle PostgreSQL connection after background work.', [
                    'connection' => $name,
                    'exception' => $exception::class,
                ]);
            }
        }
    }
}
