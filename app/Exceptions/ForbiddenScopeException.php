<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Symfony\Component\HttpFoundation\Response;

final class ForbiddenScopeException extends ApiProblemException implements ShouldntReport
{
    public function __construct(string $scope)
    {
        parent::__construct('forbidden_scope', 'Forbidden', Response::HTTP_FORBIDDEN, 'This API key has no access to the "' . $scope . '" scope.');
    }
}
