<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\ApiKey;

/** Persistence boundary for API key management. */
final class ApiKeyServiceRepository extends BusinessQueryRepository
{
    public function __construct(ApiKey $model)
    {
        parent::__construct($model);
    }
}
