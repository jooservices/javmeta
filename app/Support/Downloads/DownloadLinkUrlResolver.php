<?php

declare(strict_types=1);

namespace App\Support\Downloads;

use App\Repositories\MovieRepository;

/**
 * Resolve a detail URL to fetch for an on-demand download-link search.
 * Prefers an already-known movie_sources row; otherwise builds the
 * deterministic detail URL for torrent-theme / JavBus sources.
 */
final class DownloadLinkUrlResolver
{
    public function __construct(private readonly MovieRepository $movies) {}

    public function resolve(int $movieId, string $displayCode, string $sourceSlug): ?string
    {
        $existing = $this->movies->downloadSearchSourceUrl($movieId, $sourceSlug);
        if ($existing !== null) {
            return $existing;
        }

        $base = rtrim((string) config("javmeta_sources.sources.{$sourceSlug}.base_url", ''), '/');
        if ($base === '' || $displayCode === '') {
            return null;
        }

        $torrentSlug = strtolower(str_replace('-', '', $displayCode));

        return match ($sourceSlug) {
            'onejav' => $base . '/torrent/' . $torrentSlug,
            '141jav' => $base . '/torrent/' . $torrentSlug,
            'ffjav' => $base . '/torrent/' . $torrentSlug,
            'javbus' => $base . '/' . strtoupper($displayCode),
            // javdb / avfan detail paths are opaque; only known source_url works.
            default => null,
        };
    }
}
