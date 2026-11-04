<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Source;
use Illuminate\Support\Facades\Schema;
use JOOservices\LaravelRepository\Contracts\RepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasCrud;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;

/** Persistence lookups used while validating settings against configured sources. */
final class SettingsServiceRepository extends EloquentRepository implements RepositoryInterface
{
    use HasCrud;
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;

    public function __construct(Source $model)
    {
        parent::__construct($model);
    }

    /** @return list<string> */
    public function sourceSlugs(): array
    {
        if (! Schema::hasTable('sources')) {
            return [];
        }

        return Source::query()->pluck('slug')->map('strval')->all();
    }
}
