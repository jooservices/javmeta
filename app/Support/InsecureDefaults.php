<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/** Stops a staging or production boot that still uses a sample password. The value itself is never printed. */
final class InsecureDefaults
{
    /**
     * @param  array{allow_samples?: bool, environments: list<string>, settings: list<string>, known_defaults: list<string>}  $rules
     * @param  array<string, string|null>  $values  setting name => configured value
     */
    public static function assertSafe(string $environment, array $rules, array $values): void
    {
        if (($rules['allow_samples'] ?? false) || ! in_array($environment, $rules['environments'], true)) {
            return;
        }

        foreach ($rules['settings'] as $setting) {
            $value = $values[$setting] ?? null;
            if (is_string($value) && in_array($value, $rules['known_defaults'], true)) {
                throw new RuntimeException("{$setting} still holds a sample default value; set a real secret before running in {$environment}.");
            }
        }
    }
}
