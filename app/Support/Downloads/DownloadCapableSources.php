<?php

declare(strict_types=1);

namespace App\Support\Downloads;

/**
 * Sources whose CrawlerX detail adapters emit magnet: and/or .torrent download
 * links (verified in jooservices/crawlerx adapters). Marked in
 * config/javmeta_sources.php as offers_downloads => true.
 */
final class DownloadCapableSources
{
    /** @return list<string> */
    public function slugs(): array
    {
        $sources = config('javmeta_sources.sources', []);
        if (! is_array($sources)) {
            return [];
        }

        $slugs = [];
        foreach ($sources as $slug => $definition) {
            if (! is_string($slug) || ! is_array($definition)) {
                continue;
            }
            if (($definition['offers_downloads'] ?? false) === true) {
                $slugs[] = $slug;
            }
        }

        sort($slugs);

        return $slugs;
    }

    public function offers(string $slug): bool
    {
        return in_array($slug, $this->slugs(), true);
    }
}
