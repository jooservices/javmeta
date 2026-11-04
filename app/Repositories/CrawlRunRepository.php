<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\CrawlRun;
use DateTimeInterface;
use JOOservices\LaravelRepository\Contracts\ReadRepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;

final class CrawlRunRepository extends EloquentRepository implements ReadRepositoryInterface
{
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;

    public function __construct(CrawlRun $model)
    {
        parent::__construct($model);
    }

    public function summarySince(DateTimeInterface $since): ?\stdClass
    {
        return $this->newQuery()->toBase()
            ->where('started_at', '>=', $since)
            ->selectRaw('COUNT(*) AS total, COALESCE(SUM(pages_fetched), 0) AS pages, COALESCE(SUM(movies_new), 0) AS movies_new, COALESCE(SUM(movies_updated), 0) AS movies_updated, COALESCE(SUM(failures), 0) AS failures')
            ->first();
    }
}
