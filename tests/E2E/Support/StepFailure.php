<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

use RuntimeException;
use Tests\E2E\Context;

final class StepFailure extends RuntimeException
{
    public function __construct(string $message, public readonly Context $context, ?\Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
