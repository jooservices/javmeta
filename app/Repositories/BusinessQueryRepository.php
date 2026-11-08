<?php

declare(strict_types=1);

namespace App\Repositories;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use JOOservices\LaravelRepository\Contracts\RepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasCrud;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;

/** Shared Laravel repository base for business query repositories. */
abstract class BusinessQueryRepository extends EloquentRepository implements RepositoryInterface
{
    use HasCrud;
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;

    /**
     * @template TModel of Model
     * @param class-string<TModel> $modelClass
     * @return EloquentBuilder<TModel>
     */
    public function modelQuery(string $modelClass): EloquentBuilder
    {
        /** @var TModel $model */
        $model = app($modelClass);

        return $model->newQuery();
    }

    public function tableQuery(string $table): QueryBuilder
    {
        return DB::table($table);
    }

    public function databaseQuery(): QueryBuilder
    {
        return DB::query();
    }

    /** @param array<int, mixed> $bindings
     *  @return list<object>
     */
    public function select(string $query, array $bindings = []): array
    {
        return DB::select($query, $bindings);
    }

    /** @param array<int, mixed> $bindings */
    public function selectOne(string $query, array $bindings = []): ?object
    {
        return DB::selectOne($query, $bindings);
    }

    public function driverName(): string
    {
        return DB::connection()->getDriverName();
    }

    public function transaction(Closure $callback, int $attempts = 1): mixed
    {
        return DB::transaction($callback, $attempts);
    }

    public function beginTransaction(): void
    {
        DB::beginTransaction();
    }

    public function commit(): void
    {
        DB::commit();
    }

    public function rollBack(): void
    {
        DB::rollBack();
    }
}
