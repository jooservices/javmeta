<?php

declare(strict_types=1);

namespace App\Support;

final class NodeIdentity
{
    public static function current(): string
    {
        $configured = config('sentry.node');
        $node = is_string($configured) && trim($configured) !== '' && trim($configured) !== 'unknown'
            ? trim($configured)
            : self::hostname();

        return self::normalize($node);
    }

    public static function hostname(): string
    {
        $hostname = gethostname();

        return self::normalize(is_string($hostname) && trim($hostname) !== '' ? trim($hostname) : 'local');
    }

    private static function normalize(string $node): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9_.-]/', '-', $node) ?? 'local';
        $changed = $normalized !== $node;
        if (! $changed && mb_strlen($normalized) <= 64) {
            return $normalized;
        }

        return mb_substr($normalized, 0, 31) . '-' . substr(hash('sha256', $changed ? $node : $normalized), 0, 32);
    }
}
