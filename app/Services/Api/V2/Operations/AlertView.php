<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Operations;

use stdClass;

/** The shape of an alert event in the contract. */
final class AlertView
{
    /** @return array<string, mixed> */
    public static function summary(stdClass $row): array
    {
        return [
            'id' => (int) $row->id,
            'rule' => $row->rule,
            'severity' => $row->severity,
            'value' => self::decimal($row->value),
            'threshold' => self::decimal($row->threshold),
            'started_at' => Iso::stamp($row->started_at),
            'resolved_at' => Iso::stamp($row->resolved_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function full(stdClass $row): array
    {
        return self::summary($row) + ['notified_at' => Iso::stamp($row->notified_at)];
    }

    private static function decimal(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
