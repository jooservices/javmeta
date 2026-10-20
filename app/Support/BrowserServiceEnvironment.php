<?php

declare(strict_types=1);

namespace App\Support;

/**
 * CrawlerX reads FLARESOLVERR_URL; JavMeta used to name it CRAWLERX_FLARESOLVERR_URL.
 * The old name stays valid (deprecated) by copying it to the new name when the new one is not set.
 */
final class BrowserServiceEnvironment
{
    public static function applyAliases(): void
    {
        $new = getenv('FLARESOLVERR_URL');
        $old = getenv('CRAWLERX_FLARESOLVERR_URL');
        if (($new === false || trim($new) === '') && is_string($old) && trim($old) !== '') {
            putenv('FLARESOLVERR_URL=' . trim($old));
        }
    }
}
