<?php

declare(strict_types=1);

namespace App\Services\Search;

use RuntimeException;

final class SearchDeadlineExceeded extends RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('The search deadline expired.', previous: $previous);
    }
}
