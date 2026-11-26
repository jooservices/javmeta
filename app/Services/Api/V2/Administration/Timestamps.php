<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Administration;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/** Timestamps of the v2 API: ISO 8601 in UTC with milliseconds. */
final class Timestamps
{
    public static function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $moment = $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse((string) $value);

        return $moment->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
