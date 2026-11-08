<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\CrawlEvent;
use Illuminate\Support\Facades\DB;

/** Persistence boundary for observability records and metric snapshots. */
final class ObservabilityServiceRepository extends BusinessQueryRepository
{
    public function __construct(CrawlEvent $model)
    {
        parent::__construct($model);
    }

    /** @return list<object> */
    public function observationConflictRates(): array
    {
        if ($this->driverName() === 'pgsql') {
            return DB::select(<<<'SQL'
                SELECT field,
                       COUNT(*) FILTER (WHERE distinct_hashes >= 2)::float
                         / NULLIF(COUNT(*), 0) AS conflict_rate
                FROM (
                    SELECT movie_id, field, COUNT(DISTINCT value_hash) AS distinct_hashes
                    FROM movie_observations
                    GROUP BY movie_id, field
                ) per_movie
                GROUP BY field
            SQL);
        }

        return DB::select(<<<'SQL'
            SELECT field,
                   CAST(SUM(CASE WHEN distinct_hashes >= 2 THEN 1 ELSE 0 END) AS REAL)
                     / NULLIF(COUNT(*), 0) AS conflict_rate
            FROM (
                SELECT movie_id, field, COUNT(DISTINCT value_hash) AS distinct_hashes
                FROM movie_observations
                GROUP BY movie_id, field
            ) per_movie
            GROUP BY field
        SQL);
    }

    public function postgresConnectionCapacity(): ?object
    {
        if ($this->driverName() !== 'pgsql') {
            return null;
        }

        return DB::selectOne("SELECT COUNT(*)::int AS used, current_setting('max_connections')::int AS maximum FROM pg_stat_activity WHERE backend_type = 'client backend'");
    }
}
