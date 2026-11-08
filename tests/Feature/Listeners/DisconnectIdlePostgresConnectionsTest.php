<?php

declare(strict_types=1);

namespace Tests\Feature\Listeners;

use App\Listeners\DisconnectIdlePostgresConnections;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Horizon\Events\MasterSupervisorLooped;
use Laravel\Horizon\Events\SupervisorLooped;
use Laravel\Horizon\MasterSupervisor;
use Laravel\Horizon\Supervisor;
use Mockery;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

final class DisconnectIdlePostgresConnectionsTest extends TestCase
{
    public function test_booted_app_fires_horizon_and_schedule_work_listeners_that_release_idle_postgres(): void
    {
        self::assertTrue($this->listenerIsRegistered(SupervisorLooped::class, [DisconnectIdlePostgresConnections::class, 'afterHorizonLoop']));
        self::assertTrue($this->listenerIsRegistered(MasterSupervisorLooped::class, [DisconnectIdlePostgresConnections::class, 'afterHorizonLoop']));
        self::assertTrue($this->listenerIsRegistered(CommandStarting::class, [DisconnectIdlePostgresConnections::class, 'afterScheduleWorkStarted']));

        config(['database.connections' => ['pgsql' => ['driver' => 'pgsql']]]);
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('transactionLevel')->times(3)->andReturn(0);
        DB::shouldReceive('connection')->times(3)->with('pgsql')->andReturn($connection);
        DB::shouldReceive('disconnect')->times(3)->with('pgsql');

        $listener = app(DisconnectIdlePostgresConnections::class);
        $listener->afterHorizonLoop(new SupervisorLooped(Mockery::mock(Supervisor::class)));
        $listener->afterHorizonLoop(new MasterSupervisorLooped(Mockery::mock(MasterSupervisor::class)));
        Event::dispatch(new CommandStarting('schedule:work', new ArrayInput([]), new NullOutput()));
        Event::dispatch(new CommandStarting('inspire', new ArrayInput([]), new NullOutput()));
    }

    /**
     * @param  class-string  $event
     * @param  array{0: class-string, 1: string}  $listener
     */
    private function listenerIsRegistered(string $event, array $listener): bool
    {
        foreach (Event::getRawListeners()[$event] ?? [] as $registered) {
            if ($registered === $listener) {
                return true;
            }
        }

        return false;
    }
}
