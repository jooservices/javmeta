<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Services\Dependencies\Dependency;
use Illuminate\Http\Response;

final class DependencyUnavailableException extends ApiProblemException
{
    public function __construct(Dependency $dependency)
    {
        parent::__construct(
            'dependency_unavailable',
            'Dependency Unavailable',
            Response::HTTP_SERVICE_UNAVAILABLE,
            "The {$dependency->value} dependency is unavailable.",
            null,
            ['Retry-After' => '30'],
        );
    }
}
