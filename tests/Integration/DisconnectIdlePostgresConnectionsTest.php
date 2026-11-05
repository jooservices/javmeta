<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
use RuntimeException;

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
}
