<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\CrawlQueue;
use Illuminate\Support\Collection;
use JOOservices\LaravelRepository\Contracts\ReadRepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;

final class CrawlQueueRepository extends EloquentRepository implements ReadRepositoryInterface
{
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;

    public function __construct(CrawlQueue $model)
    {
        parent::__construct($model);
    }

    /** @return Collection<int, \stdClass> */
    public function totalsBySourceAndStatus(): Collection
    {
        return $this->newQuery()->toBase()
            ->select('source_slug', 'status')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('source_slug', 'status')
            ->get();
    }

    /** @return Collection<int, \stdClass> */
    public function recentFailures(int $limit): Collection
    {
        return $this->newQuery()->toBase()
            ->where('status', 'failed')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['source_slug', 'kind', 'error_code', 'attempts', 'last_error']);
    }
}
