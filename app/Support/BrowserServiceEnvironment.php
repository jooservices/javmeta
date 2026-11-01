<?php

declare(strict_types=1);

namespace App\Support;

/** Adapts database-backed service URLs to CrawlerX's environment-only runtime configuration. */
final class BrowserServiceEnvironment
{
    public static function apply(string $key, string $value): bool
    {
        $variable = match ($key) {
            'browser.flaresolverr_url' => 'FLARESOLVERR_URL',
            'browser.playwright_url' => 'PLAYWRIGHT_URL',
            default => null,
        };
        if ($variable === null) {
            return false;
        }

        $value = trim($value);
        if (getenv($variable) === $value) {
            return false;
        }

        putenv($variable . '=' . $value);

        return true;
    }
}
