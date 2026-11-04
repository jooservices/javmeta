<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Performer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use JOOservices\LaravelRepository\Contracts\ReadRepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;

final class PerformerCatalogRepository extends EloquentRepository implements ReadRepositoryInterface
{
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;

    public function __construct(Performer $model)
    {
        parent::__construct($model);
    }

    /** @return Collection<int, \stdClass> */
    public function facetGroups(string $column): Collection
    {
        return DB::table('performers')->whereNull('canonical_performer_id')->whereNotNull($column)->where($column, '<>', '')
            ->selectRaw('LOWER(' . $column . ') AS raw_name, COUNT(*) AS performer_count')->groupByRaw('LOWER(' . $column . ')')->get();
    }

    public function unknownBloodTypeCount(): int
    {
        return (int) DB::table('performers')->whereNull('canonical_performer_id')
            ->where(static function ($query): void {
                $query->whereNull('blood_type')->orWhere('blood_type', '=', '');
            })->count();
    }
}
