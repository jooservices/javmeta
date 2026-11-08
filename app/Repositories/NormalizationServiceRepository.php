<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\CrawlEvent;

/** Persistence boundary for normalization metrics and backfills. */
final class NormalizationServiceRepository extends BusinessQueryRepository
{
    public function __construct(CrawlEvent $model)
    {
        parent::__construct($model);
    }
}
