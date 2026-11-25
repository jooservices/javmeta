<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Symfony\Component\HttpFoundation\Response;

final class BulkLimitExceededException extends ApiProblemException implements ShouldntReport
{
    public function __construct(int $limit = 100)
    {
        parent::__construct('bulk_limit_exceeded', 'Bulk Limit Exceeded', Response::HTTP_REQUEST_ENTITY_TOO_LARGE, "Bulk lookup is limited to {$limit} codes.");
    }
}
