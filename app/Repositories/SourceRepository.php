<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Source;
use Illuminate\Support\Collection;
use JOOservices\LaravelRepository\Contracts\ReadRepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;

final class SourceRepository extends EloquentRepository implements ReadRepositoryInterface
{
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;

    public function __construct(Source $model)
    {
        parent::__construct($model);
    }

    /** @return Collection<int, \stdClass> */
    public function enabledForDashboard(): Collection
    {
        return $this->newQuery()->toBase()
            ->where('enabled', true)
            ->orderBy('priority')
            ->get(['slug', 'name', 'last_success_at', 'last_error_at', 'last_error']);
    }
}
