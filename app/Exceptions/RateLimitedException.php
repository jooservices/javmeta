<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Symfony\Component\HttpFoundation\Response;

final class RateLimitedException extends ApiProblemException implements ShouldntReport
{
    public function __construct(int $retryAfterSeconds, string $detail = 'API rate limit exceeded.')
    {
        parent::__construct('rate_limited', 'Rate Limited', Response::HTTP_TOO_MANY_REQUESTS, $detail, null, ['Retry-After' => (string) $retryAfterSeconds]);
    }
}
