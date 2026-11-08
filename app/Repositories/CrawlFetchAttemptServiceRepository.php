<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\CrawlQueue;
use Illuminate\Support\Facades\DB;
use JOOservices\LaravelRepository\Contracts\RepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasCrud;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;

/** Stores and reads fetch attempt records for crawl services. */
final class CrawlFetchAttemptServiceRepository extends EloquentRepository implements RepositoryInterface
{
    use HasCrud;
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;

    public function __construct(CrawlQueue $model)
    {
        parent::__construct($model);
    }

    /** @param list<array<string, mixed>> $rows */
    public function insertAttempts(array $rows): void
    {
        if ($rows !== []) {
            DB::table('crawl_fetch_attempts')->insert($rows);
        }
    }
}
