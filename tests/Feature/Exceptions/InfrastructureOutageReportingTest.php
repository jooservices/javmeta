<?php

declare(strict_types=1);

namespace Tests\Feature\Exceptions;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RedisException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class InfrastructureOutageReportingTest extends TestCase
{
    /** @return array<string, array{Throwable}> */
    public static function outages(): array
    {
        return [
            'Redis refuses connections while it restarts' => [new RedisException('Connection refused')],
            'Redis loads its dataset after a restart' => [new RedisException('LOADING Redis is loading the dataset in memory')],
            'Redis closes a connection during a restart' => [new RedisException('read error on connection to 192.168.10.14:6379')],
            'PostgreSQL refuses connections while it restarts' => [self::databaseError('SQLSTATE[08006] [7] connection to server at "192.168.10.12", port 5432 failed: Connection refused')],
            'PostgreSQL is shutting down' => [self::databaseError('SQLSTATE[08006] [7] connection to server at "192.168.10.12", port 5432 failed: FATAL:  the database system is shutting down')],
            'PostgreSQL is starting up' => [self::databaseError('SQLSTATE[57P03]: Cannot connect now: 7 FATAL:  the database system is starting up')],
            'PostgreSQL ends a session for a restart' => [self::databaseError('SQLSTATE[57P01]: Admin shutdown: 7 FATAL:  terminating connection due to administrator command')],
        ];
    }

    #[DataProvider('outages')]
    public function test_ac1_a_restart_of_redis_or_postgresql_is_not_reported_as_an_application_error(Throwable $exception): void
    {
        self::assertFalse(app(ExceptionHandler::class)->shouldReport($exception));
    }

    /** @return array<string, array{Throwable}> */
    public static function realFaults(): array
    {
        return [
            'PostgreSQL role has no connection left' => [self::databaseError('SQLSTATE[08006] [7] connection to server at "192.168.10.12", port 5432 failed: FATAL:  too many connections for role "xcrawler"')],
            'PostgreSQL deadlock' => [self::databaseError('SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected')],
            'Redis script error' => [new RedisException('ERR Error running script (call to f_abc): wrong number of arguments')],
            'a crawl target refusing connections' => [new RuntimeException('Connection refused')],
        ];
    }

    #[DataProvider('realFaults')]
    public function test_ac2_a_real_fault_is_still_reported(Throwable $exception): void
    {
        self::assertTrue(app(ExceptionHandler::class)->shouldReport($exception));
    }

    public function test_ac1_the_outage_is_recognised_when_it_is_wrapped_by_another_exception(): void
    {
        $wrapped = new RuntimeException('Horizon loop failed', 0, new RedisException('Connection refused'));

        self::assertFalse(app(ExceptionHandler::class)->shouldReport($wrapped));
    }

    private static function databaseError(string $message): QueryException
    {
        return new QueryException('pgsql', 'select 1', [], new PDOException($message));
    }
}
