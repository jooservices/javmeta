<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Symfony\Component\HttpFoundation\Response;

final class MovieNotFoundException extends ApiProblemException implements ShouldntReport
{
    public function __construct(string $code)
    {
        parent::__construct('movie_not_found', 'Movie Not Found', Response::HTTP_NOT_FOUND, "Movie {$code} was not found.");
    }
}
