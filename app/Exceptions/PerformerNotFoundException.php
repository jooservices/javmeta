<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Symfony\Component\HttpFoundation\Response;

final class PerformerNotFoundException extends ApiProblemException implements ShouldntReport
{
    public function __construct(string|int $id)
    {
        parent::__construct('performer_not_found', 'Performer Not Found', Response::HTTP_NOT_FOUND, "Performer {$id} was not found.");
    }
}
