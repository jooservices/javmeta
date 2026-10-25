<?php

declare(strict_types=1);

namespace App\Services\Settings;

use InvalidArgumentException;

/** Base service URLs never carry credentials; dedicated secret settings do. */
final class SettingUrl
{
    /** @param array<string, mixed> $definition */
    public static function validate(string $key, array $definition, string $value): void
    {
        if ($value === '') {
            return;
        }
        $parts = parse_url($value);
        $schemes = $definition['url_schemes'];
        if (($definition['url_local_http'] ?? false) && is_array($parts) && in_array(strtolower($parts['host'] ?? ''), ['localhost', '127.0.0.1'], true)) {
            $schemes[] = 'http';
        }
        if (! is_array($parts) || empty($parts['host']) || ! in_array(strtolower($parts['scheme'] ?? ''), $schemes, true)
            || preg_match('/[\x00-\x20\x7f\\\\]/', $value) === 1
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException("{$key}: must be a base URL with an allowed HTTP(S) scheme, a host, and no credentials, query or fragment");
        }
    }

    public static function redact(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $parts = parse_url($value);
        if (! is_array($parts) || empty($parts['host'])) {
            return '[invalid URL]';
        }

        return ($parts['scheme'] ?? 'https') . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '');
    }

    public static function redactText(string $text): string
    {
        return preg_replace_callback('~https?://[^\s<>"\']+~i', static fn(array $match): string => self::redact($match[0]), $text) ?? $text;
    }
}
