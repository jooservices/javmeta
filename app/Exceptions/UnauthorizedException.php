<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Symfony\Component\HttpFoundation\Response;

final class UnauthorizedException extends ApiProblemException implements ShouldntReport
{
    public function __construct(string $detail = 'API key is missing, invalid, or revoked.')
    {
        parent::__construct('unauthorized', 'Unauthorized', Response::HTTP_UNAUTHORIZED, $detail);
    }
}
