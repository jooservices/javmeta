<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\Search\SearchBudget;
use App\Services\Search\SearchDeadlineExceeded;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use WeakMap;

/** Applies the active search deadline to one short PostgreSQL read transaction. */
final class SearchDeadlineRepository
{
    /** @var WeakMap<object, bool>|null */
    private static ?WeakMap $registeredConnections = null;

    public function __construct(private readonly SearchBudget $budget) {}

    /** @template T
     * @param Closure(): T $query
     * @return T
     */
    public function run(Closure $query): mixed
    {
        if (! $this->budget->active()) {
            return $query();
        }

        $connection = DB::connection();
        if ($connection->getDriverName() !== 'pgsql') {
            return $query();
        }

        $this->registerDeadlineCallback($connection);

        try {
            return $connection->transaction($query);
        } catch (QueryException $exception) {
            $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
            if ($sqlState === '57014') {
                throw new SearchDeadlineExceeded(previous: $exception);
            }

            throw $exception;
        }
    }

    private function registerDeadlineCallback(object $connection): void
    {
        self::$registeredConnections ??= new WeakMap();
        if (isset(self::$registeredConnections[$connection])) {
            return;
        }

        $connection->beforeExecuting(static function (string $query, array $bindings, object $connection): void {
            $budget = app(SearchBudget::class);
            if (! $budget->active() || $connection->transactionLevel() < 1) {
                return;
            }

            $timeout = $budget->statementTimeoutMilliseconds();
            if ($timeout < 1) {
                throw new SearchDeadlineExceeded();
            }

            $connection->getPdo()->exec('SET LOCAL statement_timeout TO ' . $timeout);
            if ($budget->expired()) {
                throw new SearchDeadlineExceeded();
            }
        });

        self::$registeredConnections[$connection] = true;
    }
}
