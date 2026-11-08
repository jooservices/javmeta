<?php

declare(strict_types=1);

namespace App\Services\Settings;

use DateTimeImmutable;

/** Validates the setting shapes that define performer normalization rules. */
final class PerformerNormalizationSettingsValidator
{
    /**
     * @param list<string> $items
     * @param callable(string): never $fail
     * @return list<string>
     */
    public static function normalizeAllowList(string $key, array $items, callable $fail): array
    {
        if ($key === 'normalization.blood_types') {
            $items = array_values(array_unique(array_map(static fn(string $item): string => mb_strtoupper($item, 'UTF-8'), $items)));
            if ($items === [] || array_diff($items, ['A', 'B', 'O', 'AB']) !== []) {
                $fail('must contain one or more of A, B, O, and AB');
            }
        }
        if ($key === 'normalization.cups') {
            $items = array_values(array_unique(array_map('strtoupper', $items)));
            if ($items === [] || array_diff($items, range('A', 'K')) !== []) {
                $fail('must contain one or more letters from A through K');
            }
        }

        return $items;
    }

    /** @param array<array-key, mixed> $value
     *  @param callable(string): never $fail
     */
    public static function validateJsonShape(string $shape, array $value, callable $fail): bool
    {
        if ($shape === 'performer_field_ranges') {
            self::validateFieldRanges($value, $fail);

            return true;
        }
        if ($shape === 'performer_location_aliases') {
            self::validateLocationAliases($value, $fail);

            return true;
        }
        if ($shape === 'performer_junk_tokens') {
            self::validateJunkTokens($value, $fail);

            return true;
        }

        return false;
    }

    /** @param array<array-key, mixed> $value
     *  @param callable(string): never $fail
     */
    private static function validateFieldRanges(array $value, callable $fail): void
    {
        $expected = ['birth_date', 'bust', 'debut_date', 'height_cm', 'hip', 'waist'];
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== $expected) {
            $fail('must define ranges for every performer profile field');
        }
        foreach ($value as $field => $bounds) {
            $boundKeys = is_array($bounds) ? array_keys($bounds) : [];
            sort($boundKeys);
            if (! is_array($bounds) || array_is_list($bounds) || $boundKeys !== ['max', 'min']) {
                $fail("{$field} must define min and max");
            }
            if (in_array($field, ['birth_date', 'debut_date'], true)) {
                self::validateDateRange((string) $field, $bounds, $fail);
            } elseif (! is_int($bounds['min']) || ! is_int($bounds['max']) || $bounds['min'] < 1 || $bounds['min'] > $bounds['max'] || $bounds['max'] > 500) {
                $fail("{$field} bounds must be positive centimeters up to 500");
            }
        }
    }

    /** @param array<array-key, mixed> $bounds
     *  @param callable(string): never $fail
     */
    private static function validateDateRange(string $field, array $bounds, callable $fail): void
    {
        if (! is_string($bounds['min']) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $bounds['min'])
            || ! (is_string($bounds['max']) && ($bounds['max'] === 'today' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $bounds['max'])))) {
            $fail("{$field} dates must use YYYY-MM-DD or today");
        }
        if (! self::isCalendarDate($bounds['min'])
            || ($bounds['max'] !== 'today' && ! self::isCalendarDate($bounds['max']))
            || ($bounds['max'] !== 'today' && $bounds['min'] > $bounds['max'])) {
            $fail("{$field} must define an ordered range of valid calendar dates");
        }
    }

    /** @param array<array-key, mixed> $value
     *  @param callable(string): never $fail
     */
    private static function validateLocationAliases(array $value, callable $fail): void
    {
        $officialNames = array_fill_keys(array_values((array) config('javmeta_japan_locations', [])), true);
        foreach ($value as $alias => $canonical) {
            if (! is_string($alias) || trim($alias) === '' || mb_strlen($alias) > 100
                || ! is_string($canonical) || ! isset($officialNames[$canonical])) {
                $fail('must map non-empty aliases to official Japanese prefecture or city names');
            }
        }
    }

    /** @param array<array-key, mixed> $value
     *  @param callable(string): never $fail
     */
    private static function validateJunkTokens(array $value, callable $fail): void
    {
        $fields = ['birth_date', 'blood_rh', 'blood_type', 'bust', 'cup', 'debut_date', 'height_cm', 'hip', 'location', 'waist'];
        foreach ($value as $field => $tokens) {
            if (! is_string($field) || ! in_array($field, $fields, true) || ! is_array($tokens) || ! array_is_list($tokens)) {
                $fail('must map performer profile field names to lists of junk tokens');
            }
            if (count($tokens) > 100) {
                $fail("{$field} has too many junk tokens");
            }
            foreach ($tokens as $token) {
                if (! is_string($token) || trim($token) === '' || mb_strlen($token) > 100) {
                    $fail("{$field} tokens must be non-empty text up to 100 characters");
                }
            }
        }
    }

    private static function isCalendarDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        return $date instanceof DateTimeImmutable
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d') === $value;
    }
}
