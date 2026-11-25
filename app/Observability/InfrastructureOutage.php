<?php

declare(strict_types=1);

namespace App\Observability;

use PDOException;
use RedisException;
use Throwable;

/**
 * Tells a restart of Redis or PostgreSQL apart from a fault of the application.
 *
 * While a data service restarts, every worker loop, scheduled command and job that touches it throws once
 * per attempt (574 events in one minute on 2026-11-20). The restart is not an application fault: the
 * monitoring stack alerts on RedisDown and on PostgreSQL being down, and the jobs are retried when the service
 * is back. A role without free connections, a deadlock or a failing script is a fault and is still reported.
 */
final class InfrastructureOutage
{
    /** What phpredis says while the server is stopped, loading its dataset or has closed the connection. */
    private const REDIS_MARKERS = ['Connection refused', 'LOADING', 'read error on connection', 'went away', 'Connection reset by peer'];

    /** What PostgreSQL says while it stops, starts or ends the sessions for a restart. */
    private const DATABASE_MARKERS = ['Connection refused', 'shutting down', 'starting up', 'terminating connection due to administrator command', 'server closed the connection unexpectedly'];

    public static function matches(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if (self::isRedisOutage($current) || self::isDatabaseOutage($current)) {
                return true;
            }
        }

        return false;
    }

    private static function isRedisOutage(Throwable $exception): bool
    {
        return $exception instanceof RedisException && self::mentionsAny($exception->getMessage(), self::REDIS_MARKERS);
    }

    private static function isDatabaseOutage(Throwable $exception): bool
    {
        return $exception instanceof PDOException && self::mentionsAny($exception->getMessage(), self::DATABASE_MARKERS);
    }

    /** @param list<string> $markers */
    private static function mentionsAny(string $message, array $markers): bool
    {
        foreach ($markers as $marker) {
            if (str_contains($message, $marker)) {
                return true;
            }
        }

        return false;
    }
}
