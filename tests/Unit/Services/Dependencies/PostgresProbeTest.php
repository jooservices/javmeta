<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dependencies;

use App\Services\Dependencies\DependencyProbe;
use App\Services\Dependencies\Probes\PostgresProbe;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

final class PostgresProbeTest extends TestCase
{
    public function test_it_runs_a_select_one_query_and_reports_a_healthy_connection(): void
    {
        config(['database.default' => 'pgsql']);
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(static fn(Closure $callback): mixed => $callback());
        $connection->shouldReceive('statement')->once()->with('SET LOCAL statement_timeout TO 2000');
        $connection->shouldReceive('select')
            ->once()
            ->with('SELECT 1')
            ->andReturn([['?column?' => 1]]);
        $connection->shouldReceive('select')
            ->once()
            ->with('SHOW timezone')
            ->andReturn([['TimeZone' => 'Etc/UTC']]);

        DB::shouldReceive('purge')->once()->with((string) config('database.default'));
        DB::shouldReceive('connection')->once()->andReturn($connection);

        $probe = new PostgresProbe();

        self::assertInstanceOf(DependencyProbe::class, $probe);
        self::assertTrue($probe->probe());
    }

    public function test_it_reports_an_unhealthy_connection_when_the_query_fails(): void
    {
        config(['database.default' => 'pgsql']);
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(static fn(Closure $callback): mixed => $callback());
        $connection->shouldReceive('statement')->once()->with('SET LOCAL statement_timeout TO 2000');
        $connection->shouldReceive('select')
            ->once()
            ->with('SELECT 1')
            ->andThrow(new \RuntimeException('database unavailable'));

        DB::shouldReceive('purge')->once()->with((string) config('database.default'));
        DB::shouldReceive('connection')->once()->andReturn($connection);

        self::assertFalse((new PostgresProbe())->probe());
    }

    public function test_it_rejects_a_postgres_timezone_other_than_utc(): void
    {
        config(['database.default' => 'pgsql']);
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(static fn(Closure $callback): mixed => $callback());
        $connection->shouldReceive('statement')->once()->with('SET LOCAL statement_timeout TO 2000');
        $connection->shouldReceive('select')->once()->with('SELECT 1')->andReturn([['?column?' => 1]]);
        $connection->shouldReceive('select')->once()->with('SHOW timezone')->andReturn([['TimeZone' => 'Asia/Bangkok']]);
        DB::shouldReceive('purge')->once()->with((string) config('database.default'));
        DB::shouldReceive('connection')->once()->andReturn($connection);

        $result = (new PostgresProbe())->check();

        self::assertFalse($result->available);
        self::assertSame('PostgreSQL timezone must be UTC.', $result->message);
    }
}
