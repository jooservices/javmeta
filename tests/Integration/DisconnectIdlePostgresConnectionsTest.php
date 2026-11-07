<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Listeners\DisconnectIdlePostgresConnections;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Horizon\Events\MasterSupervisorLooped;
use Laravel\Horizon\Events\SupervisorLooped;
use Laravel\Horizon\MasterSupervisor;
use Laravel\Horizon\Supervisor;
use Mockery;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final class DisconnectIdlePostgresConnectionsTest extends IntegrationTestCase
{
    public function test_queue_loop_releases_idle_named_postgres_connections_and_keeps_active_transactions(): void
    {
        config([
            'database.connections.pgsql_read' => config('database.connections.pgsql'),
            'database.connections.pgsql_transaction' => config('database.connections.pgsql'),
        ]);

        $idleConnections = [DB::connection('pgsql'), DB::connection('pgsql_read')];
        foreach ($idleConnections as $connection) {
            $connection->selectOne('SELECT 1');
            self::assertNotNull($connection->getRawPdo());
        }
        $transaction = DB::connection('pgsql_transaction');
        $transaction->beginTransaction();
        self::assertSame(0, $idleConnections[0]->transactionLevel());
        self::assertSame(0, $idleConnections[1]->transactionLevel());
        self::assertNotEmpty(app('events')->getListeners(Looping::class));

        try {
            Event::dispatch(new Looping('redis', 'default'));

            foreach ($idleConnections as $connection) {
                self::assertNull($connection->getRawPdo(), 'Connection ' . $connection->getName() . ' should be released before the next job.');
            }
            self::assertNotNull($transaction->getRawPdo());
            self::assertSame(1, $transaction->transactionLevel());
        } finally {
            if ($transaction->transactionLevel() > 0) {
                $transaction->rollBack();
            }
        }
    }

    public function test_scheduler_success_and_failure_events_release_idle_postgres_connections(): void
    {
        $task = Mockery::mock(ScheduledEvent::class);
        $events = [
            new ScheduledTaskFinished($task, 0.1),
            new ScheduledTaskFailed($task, new RuntimeException('scheduled task failed'), 0.1),
        ];

        foreach ($events as $event) {
            $connection = DB::connection('pgsql');
            $connection->selectOne('SELECT 1');
            self::assertNotNull($connection->getRawPdo());

            Event::dispatch($event);

            self::assertNull($connection->getRawPdo());
        }
    }

    public function test_horizon_supervisor_and_master_loops_release_idle_postgres_connections(): void
    {
        self::assertTrue($this->disconnectListenerIsRegistered(SupervisorLooped::class, 'afterHorizonLoop'));
        self::assertTrue($this->disconnectListenerIsRegistered(MasterSupervisorLooped::class, 'afterHorizonLoop'));

        // Invoke the app listener directly: Event::dispatch also runs Horizon's Redis/memory
        // listeners, which terminate or fail against mocked supervisor objects in PHPUnit.
        $listener = app(DisconnectIdlePostgresConnections::class);
        $events = [
            new SupervisorLooped(Mockery::mock(Supervisor::class)),
            new MasterSupervisorLooped(Mockery::mock(MasterSupervisor::class)),
        ];

        foreach ($events as $event) {
            $connection = DB::connection('pgsql');
            $connection->selectOne('SELECT 1');
            self::assertNotNull($connection->getRawPdo());

            $listener->afterHorizonLoop($event);

            self::assertNull(
                $connection->getRawPdo(),
                $event::class . ' must release the idle PDO opened at supervisor boot.',
            );
        }
    }

    public function test_supervisor_reconnects_lazily_after_a_loop_releases_the_idle_connection(): void
    {
        $connection = DB::connection('pgsql');
        $connection->selectOne('SELECT 1');
        self::assertNotNull($connection->getRawPdo());

        app(DisconnectIdlePostgresConnections::class)->afterHorizonLoop(
            new SupervisorLooped(Mockery::mock(Supervisor::class)),
        );
        self::assertNull($connection->getRawPdo());

        $row = $connection->selectOne('SELECT 1 AS ok');
        self::assertNotNull($connection->getRawPdo());
        self::assertSame(1, (int) ($row->ok ?? 0));
    }

    public function test_schedule_work_command_starting_releases_the_boot_postgres_connection(): void
    {
        self::assertTrue($this->disconnectListenerIsRegistered(CommandStarting::class, 'afterScheduleWorkStarted'));

        $connection = DB::connection('pgsql');
        $connection->selectOne('SELECT 1');
        self::assertNotNull($connection->getRawPdo());

        Event::dispatch(new CommandStarting('schedule:work', new ArrayInput([]), new NullOutput()));

        self::assertNull($connection->getRawPdo());
    }

    private function disconnectListenerIsRegistered(string $event, string $method): bool
    {
        $expected = [DisconnectIdlePostgresConnections::class, $method];
        foreach (Event::getRawListeners()[$event] ?? [] as $registered) {
            if ($registered === $expected) {
                return true;
            }
        }

        return false;
    }
}
