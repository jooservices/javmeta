<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Downloads;

use Carbon\CarbonImmutable;

/** Timestamps of the downloads area as ISO 8601 text in UTC. */
final class Stamp
{
    public static function iso(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->utc()->format('Y-m-d\TH:i:s\Z') : null;
    }
}
