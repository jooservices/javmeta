<?php

declare(strict_types=1);

namespace Tests\Unit\Listeners;

use App\Listeners\DisconnectIdlePostgresConnections;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Database\Connection;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Horizon\Events\MasterSupervisorLooped;
use Laravel\Horizon\Events\SupervisorLooped;
use Laravel\Horizon\MasterSupervisor;
use Laravel\Horizon\Supervisor;
use Mockery;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

final class DisconnectIdlePostgresConnectionsTest extends TestCase
{
    public function test_queue_loop_disconnects_idle_postgres_connections_and_skips_other_drivers(): void
    {
        config(['database.connections' => [
            'pgsql' => ['driver' => 'pgsql'],
            'sqlite' => ['driver' => 'sqlite'],
            'invalid' => 'invalid',
        ]]);
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        DB::shouldReceive('connection')->once()->with('pgsql')->andReturn($connection);
        DB::shouldReceive('disconnect')->once()->with('pgsql');

        app(DisconnectIdlePostgresConnections::class)->afterQueueLoop(new Looping('redis', 'default'));
    }

    public function test_queue_loop_keeps_a_connection_with_an_active_transaction(): void
    {
        config(['database.connections' => ['pgsql' => ['driver' => 'pgsql']]]);
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('transactionLevel')->twice()->andReturn(1);
        DB::shouldReceive('connection')->once()->with('pgsql')->andReturn($connection);
        DB::shouldReceive('disconnect')->never();
        Log::shouldReceive('warning')->once()->with(
            'Kept a PostgreSQL connection open because a transaction remains active.',
            ['connection' => 'pgsql', 'transaction_level' => 1],
        );

        app(DisconnectIdlePostgresConnections::class)->afterQueueLoop(new Looping('redis', 'default'));
    }

    public function test_queue_loop_logs_a_connection_release_error_without_interrupting_the_worker(): void
    {
        config(['database.connections' => ['pgsql' => ['driver' => 'pgsql']]]);
        DB::shouldReceive('connection')->once()->with('pgsql')->andThrow(new RuntimeException('connection failed'));
        Log::shouldReceive('warning')->once()->with(
            'Could not release an idle PostgreSQL connection after background work.',
            ['connection' => 'pgsql', 'exception' => RuntimeException::class],
        );

        app(DisconnectIdlePostgresConnections::class)->afterQueueLoop(new Looping('redis', 'default'));
    }

    public function test_successful_and_failed_scheduled_tasks_disconnect_idle_postgres_connections(): void
    {
        config(['database.connections' => ['pgsql' => ['driver' => 'pgsql']]]);
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('transactionLevel')->twice()->andReturn(0);
        DB::shouldReceive('connection')->twice()->with('pgsql')->andReturn($connection);
        DB::shouldReceive('disconnect')->twice()->with('pgsql');
        $task = Mockery::mock(ScheduledEvent::class);
        $listener = app(DisconnectIdlePostgresConnections::class);

        $listener->afterScheduledTask(new ScheduledTaskFinished($task, 0.1));
        $listener->afterScheduledTask(new ScheduledTaskFailed($task, new RuntimeException('scheduled task failed'), 0.1));
    }

    public function test_horizon_supervisor_and_master_loops_disconnect_idle_postgres_connections(): void
    {
        config(['database.connections' => ['pgsql' => ['driver' => 'pgsql']]]);
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('transactionLevel')->twice()->andReturn(0);
        DB::shouldReceive('connection')->twice()->with('pgsql')->andReturn($connection);
        DB::shouldReceive('disconnect')->twice()->with('pgsql');
        $listener = app(DisconnectIdlePostgresConnections::class);
        $supervisor = Mockery::mock(Supervisor::class);
        $master = Mockery::mock(MasterSupervisor::class);

        $listener->afterHorizonLoop(new SupervisorLooped($supervisor));
        $listener->afterHorizonLoop(new MasterSupervisorLooped($master));
    }

    public function test_schedule_work_starting_disconnects_while_other_commands_do_not(): void
    {
        config(['database.connections' => ['pgsql' => ['driver' => 'pgsql']]]);
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        DB::shouldReceive('connection')->once()->with('pgsql')->andReturn($connection);
        DB::shouldReceive('disconnect')->once()->with('pgsql');
        $listener = app(DisconnectIdlePostgresConnections::class);

        $listener->afterScheduleWorkStarted(new CommandStarting('inspire', new ArrayInput([]), new NullOutput()));
        $listener->afterScheduleWorkStarted(new CommandStarting('schedule:work', new ArrayInput([]), new NullOutput()));
    }
}
