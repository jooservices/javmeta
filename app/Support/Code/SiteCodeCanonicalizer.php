<?php

declare(strict_types=1);

namespace App\Support\Code;

use InvalidArgumentException;

/**
 * Resolves a SoR-ready movie code from crawlerx MovieDto candidates.
 *
 * Site adapters may return raw IDs (digits-only, date-ids, opaque slugs).
 * This layer preserves product IDs and uses a URL-derived identity only for
 * the four sources whose detail pages can legitimately lack a DVD code.
 */
final class SiteCodeCanonicalizer
{
    /**
     * @param  list<string|null>  $candidates
     * @return non-empty-string|null
     */
    public static function resolve(string $slug, array $candidates, ?string $sourceUrl = null): ?string
    {
        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $trimmed = trim($candidate);
            if ($trimmed === '') {
                continue;
            }

            foreach (self::expand($slug, $trimmed) as $variant) {
                if (self::isValid($variant)) {
                    return $variant;
                }
            }
        }

        if (in_array($slug, ['javbtc', 'ffjav', 'tokyohot', '141jav'], true) && $sourceUrl !== null) {
            $path = parse_url($sourceUrl, PHP_URL_PATH);
            if (is_string($path) && trim($path, '/') !== '') {
                return strtoupper($slug) . '-DERIVED-' . strtoupper(hash('sha256', rtrim($path, '/')));
            }
        }

        return null;
    }

    /**
     * @return list<non-empty-string>
     */
    private static function expand(string $slug, string $raw): array
    {
        $variants = [];

        $siteSpecific = match ($slug) {
            'onepondo' => self::qualifyOnepondo($raw),
            'caribbeancom' => self::qualifyCaribbeancom($raw),
            'heyzo' => self::qualifyHeyzo($raw),
            'tokyohot' => self::qualifyTokyohot($raw),
            'ffjav' => self::qualifyFfjav($raw),
            '141jav' => preg_match('/^C(\d+)$/i', $raw, $match) === 1 ? 'C-' . $match[1] : null,
            'fc2' => self::qualifyFc2($raw),
            'xcity' => self::qualifyXcity($raw),
            default => null,
        };

        if ($siteSpecific !== null) {
            $variants[] = $siteSpecific;
        }

        foreach (self::extractDvdLike($raw) as $dvd) {
            $variants[] = $dvd;
        }

        // Opaque javdb-style slug: never treat as code; DVD extract from
        // surrounding text (title) is handled via other candidates.
        if (! self::looksLikeOpaqueSlug($raw)) {
            $variants[] = $raw;
        }

        return array_values(array_unique($variants));
    }

    /**
     * @return list<non-empty-string>
     */
    private static function extractDvdLike(string $raw): array
    {
        $upper = mb_strtoupper(rawurldecode($raw));
        $upper = str_replace(['_', '+'], ' ', $upper);
        $found = [];

        if (preg_match('/FC2[\s_-]*(?:PPV)?[\s_-]*(\d{4,})/u', $upper, $match) === 1) {
            $found[] = 'FC2-PPV-' . $match[1];
        }

        // Standard: SSIS-001 / SSIS001
        if (preg_match('/\b([A-Z]{2,12})[\s_-]*(\d{2,6})\b/u', $upper, $match) === 1) {
            $found[] = $match[1] . '-' . $match[2];
        }

        // Glued maker codes: MXDLP0185
        if (preg_match('/\b([A-Z]{2,12})(\d{2,6})\b/u', $upper, $match) === 1) {
            $found[] = $match[1] . '-' . $match[2];
        }

        // Digit-leading amateur/web codes: 908JDH004 → 908JDH-004
        if (preg_match('/\b(\d{2,4})([A-Z]{2,8})[\s_-]*(\d{2,5})\b/u', $upper, $match) === 1) {
            $found[] = $match[1] . $match[2] . '-' . $match[3];
        }

        return $found;
    }

    /**
     * @return non-empty-string|null
     */
    private static function qualifyOnepondo(string $raw): ?string
    {
        $upper = mb_strtoupper($raw);

        if (preg_match('/^(?:1PONDO|1PON)[\s._-]*(\d{6})[\s._-](\d{3})$/u', $upper, $match) === 1
            || preg_match('/^(\d{6})[\s._-](\d{3})$/u', $upper, $match) === 1) {
            return '1PONDO-' . $match[1] . '_' . $match[2];
        }

        return null;
    }

    /**
     * @return non-empty-string|null
     */
    private static function qualifyCaribbeancom(string $raw): ?string
    {
        $upper = mb_strtoupper($raw);

        if (preg_match('/^(?:CARIBBEANCOM|CARIBBEAN|CARIB)[\s._-]*(\d{6})[\s._-](\d{3})$/u', $upper, $match) === 1
            || preg_match('/^(\d{6})[\s._-](\d{3})$/u', $upper, $match) === 1) {
            return 'CARIBBEANCOM-' . $match[1] . '-' . $match[2];
        }

        return null;
    }

    /**
     * @return non-empty-string|null
     */
    private static function qualifyHeyzo(string $raw): ?string
    {
        $upper = mb_strtoupper(trim($raw));

        if (preg_match('/^HEYZO[\s._-]*(\d{1,5})$/u', $upper, $match) === 1) {
            return 'HEYZO-' . $match[1];
        }

        if (preg_match('/^\d{1,5}$/u', $upper) === 1) {
            return 'HEYZO-' . $upper;
        }

        return null;
    }

    /**
     * @return non-empty-string|null
     */
    private static function qualifyFfjav(string $raw): ?string
    {
        if (preg_match('/\b(10musume-\d{6}_\d+)\b/i', rawurldecode($raw), $match) === 1) {
            return strtoupper($match[1]);
        }

        return null;
    }

    /** @return non-empty-string|null */
    private static function qualifyTokyohot(string $raw): ?string
    {
        $upper = mb_strtoupper(trim($raw));

        if (preg_match('/^(?:TOKYOHOT|TOKYO[\s._-]?HOT)[\s._-]*N?(\d{3,5})$/u', $upper, $match) === 1) {
            return 'TOKYOHOT-' . $match[1];
        }

        if (preg_match('/^N(\d{3,5})$/u', $upper, $match) === 1) {
            return 'TOKYOHOT-' . $match[1];
        }

        if (preg_match('/^\d{3,5}$/u', $upper) === 1) {
            return 'TOKYOHOT-' . $upper;
        }

        if (preg_match('~(?:^|/product/)([A-Z0-9]+(?:_[A-Z0-9]+)*_SC\d+)(?:/|$)~', $upper, $match) === 1) {
            return 'TOKYOHOT-SLUG-' . $match[1];
        }

        // Letter+digit product ids (crazyasia097085, HO-576) pass through extractDvdLike / raw.
        return null;
    }

    /**
     * @return non-empty-string|null
     */
    private static function qualifyFc2(string $raw): ?string
    {
        $upper = mb_strtoupper($raw);

        if (preg_match('/FC2[\s_-]*(?:PPV)?[\s_-]*(\d{4,})/u', $upper, $match) === 1) {
            return 'FC2-PPV-' . $match[1];
        }

        if (preg_match('/^\d{4,}$/u', $upper) === 1) {
            return 'FC2-PPV-' . $upper;
        }

        return null;
    }

    /**
     * @return non-empty-string|null
     */
    private static function qualifyXcity(string $raw): ?string
    {
        $upper = mb_strtoupper(trim($raw));

        if (preg_match('/^(?:XCITY|XCT)[\s._-]*(\d{2,8})$/u', $upper, $match) === 1) {
            return 'XCITY-' . $match[1];
        }

        if (preg_match('/^([A-Z]{2,10})[\s_-]?(\d{2,6})$/u', $upper, $match) === 1) {
            return $match[1] . '-' . $match[2];
        }

        if (preg_match('/^\d{4,8}$/u', $upper) === 1) {
            return 'XCITY-' . $upper;
        }

        return null;
    }

    private static function looksLikeOpaqueSlug(string $raw): bool
    {
        // javdb /v/a8yV0p style: mixed case alnum, no separator, no long digit run.
        return preg_match('/^[A-Za-z0-9]{4,12}$/u', $raw) === 1
            && preg_match('/[A-Za-z]/u', $raw) === 1
            && preg_match('/\d/u', $raw) === 1
            && preg_match('/^[A-Za-z]{2,10}\d{2,6}$/u', $raw) !== 1
            && preg_match('/^\d+$/u', $raw) !== 1;
    }

    private static function isValid(string $code): bool
    {
        try {
            NormalizedCode::from($code);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
