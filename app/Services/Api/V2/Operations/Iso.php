<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Operations;

use Carbon\CarbonImmutable;

/** Timestamps of the Command Center as the contract writes them: UTC, millisecond precision, a trailing Z. */
final class Iso
{
    public static function stamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value, 'UTC')->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
