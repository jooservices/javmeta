<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\CrawlQueue;

/** Persistence boundary for local media processing. */
final class MediaServiceRepository extends BusinessQueryRepository
{
    public function __construct(CrawlQueue $model)
    {
        parent::__construct($model);
    }
}
