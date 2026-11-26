<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Me;

use DateTimeImmutable;
use DateTimeZone;

/** A stored time as the contract prints it: UTC, ISO 8601 with milliseconds. */
final class Timestamp
{
    public static function iso(string $stored): string
    {
        return (new DateTimeImmutable($stored, new DateTimeZone(date_default_timezone_get())))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
}
