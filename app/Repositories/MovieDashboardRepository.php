<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Movie;
use DateTimeInterface;
use JOOservices\LaravelRepository\Contracts\ReadRepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;

/** Reads the movie totals displayed by the operations dashboard. */
final class MovieDashboardRepository extends EloquentRepository implements ReadRepositoryInterface
{
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;

    public function __construct(Movie $model)
    {
        parent::__construct($model);
    }

    public function countAll(): int
    {
        return $this->newQuery()->count();
    }

    public function countFirstSeenSince(DateTimeInterface $since): int
    {
        return $this->newQuery()->where('first_seen_at', '>=', $since)->count();
    }
}
