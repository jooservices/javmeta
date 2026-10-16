<?php

declare(strict_types=1);

namespace App\Services\Observability;

use RuntimeException;
use Throwable;

/** The target metric stream does not exist yet in OpenObserve (no data ingested). */
final class StreamNotReady extends RuntimeException
{
    public function __construct(public readonly string $stream, ?Throwable $previous = null)
    {
        parent::__construct("OpenObserve stream {$stream} does not exist yet.", 0, $previous);
    }
}
