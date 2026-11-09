<?php

declare(strict_types=1);

namespace App\Support\Catalog;

/** Which writing system a catalog name belongs to, for the script index of the catalog page. */
final class NameScript
{
    /** Kana always means Japanese; Han without kana is listed as Chinese; the first letter or digit decides Latin. */
    public static function classify(string $name): string
    {
        if (preg_match('/[\x{3040}-\x{30FF}\x{31F0}-\x{31FF}\x{FF66}-\x{FF9F}]/u', $name) === 1) {
            return 'ja';
        }
        if (preg_match('/[\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}]/u', $name) === 1) {
            return 'zh';
        }
        if (preg_match('/^[^\p{L}\p{N}]*[\p{Latin}0-9]/u', $name) === 1) {
            return 'latin';
        }

        return 'other';
    }

    public static function matches(string $name, string $script): bool
    {
        return $script === 'all' || self::classify($name) === $script;
    }
}
