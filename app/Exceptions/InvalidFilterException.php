<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Symfony\Component\HttpFoundation\Response;

final class InvalidFilterException extends ApiProblemException implements ShouldntReport
{
    /** @param array<string, list<string>> $errors */
    public function __construct(string $detail, array $errors = [])
    {
        parent::__construct('invalid_filter', 'Invalid Filter', Response::HTTP_BAD_REQUEST, $detail, $errors === [] ? null : $errors);
    }
}
