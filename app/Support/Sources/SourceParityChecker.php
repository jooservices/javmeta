<?php

declare(strict_types=1);

namespace App\Support\Sources;

/**
 * Compares what crawlerx can do per source (manifest capabilities) with what javmeta configures,
 * so a site cannot be silently missing or half-wired.
 *
 * Requirements derived from crawlerx capabilities:
 *  - movie:     both "listing" and "detail" -> movie_listing_urls
 *  - performer: "performer_listing" or "performer_detail" -> performer_listing_urls
 *  - gallery:   "gallery" -> gallery_urls or gallery_listing_urls
 */
final class SourceParityChecker
{
    public const MOVIE = 'movie_listing_urls';

    public const PERFORMER = 'performer_listing_urls';

    public const GALLERY = 'gallery_urls|gallery_listing_urls';

    /**
     * @param  array<string, list<string>>  $capabilitiesBySlug  crawlerx manifest capabilities per slug
     * @param  array<string, array<string, mixed>>  $sources  config('javmeta_sources.sources')
     * @param  array<string, array<string, string>>  $knownGaps  slug => [requirement constant => reason]
     * @return list<string> human readable problems; empty when everything matches
     */
    public function problems(array $capabilitiesBySlug, array $sources, array $knownGaps = []): array
    {
        $problems = [];

        foreach (array_diff(array_keys($capabilitiesBySlug), array_keys($sources)) as $slug) {
            $problems[] = "{$slug}: crawlerx adapter has no javmeta source in config/javmeta_sources.php";
        }
        foreach (array_diff(array_keys($sources), array_keys($capabilitiesBySlug)) as $slug) {
            $problems[] = "{$slug}: javmeta source has no crawlerx adapter";
        }

        foreach ($capabilitiesBySlug as $slug => $capabilities) {
            if (! isset($sources[$slug])) {
                continue;
            }

            $required = $this->requirements($capabilities);
            $configured = $this->configured($sources[$slug]);

            foreach ([self::MOVIE, self::PERFORMER, self::GALLERY] as $requirement) {
                $needed = in_array($requirement, $required, true);
                $has = in_array($requirement, $configured, true);
                $gap = $knownGaps[$slug][$requirement] ?? null;

                if ($needed && ! $has && $gap === null) {
                    $problems[] = "{$slug}: crawlerx declares {$this->label($requirement)} support but config has no {$requirement}";
                }
                if (! $needed && $has) {
                    $problems[] = "{$slug}: config sets {$requirement} but crawlerx does not declare that capability";
                }
                if ($gap !== null && (! $needed || $has)) {
                    $problems[] = "{$slug}: known gap for {$requirement} is stale ({$gap}); remove it";
                }
            }
        }

        sort($problems);

        return $problems;
    }

    /**
     * @param  list<string>  $capabilities
     * @return list<string>
     */
    private function requirements(array $capabilities): array
    {
        $required = [];

        if (in_array('listing', $capabilities, true) && in_array('detail', $capabilities, true)) {
            $required[] = self::MOVIE;
        }
        if (array_intersect(['performer_listing', 'performer_detail'], $capabilities) !== []) {
            $required[] = self::PERFORMER;
        }
        if (in_array('gallery', $capabilities, true)) {
            $required[] = self::GALLERY;
        }

        return $required;
    }

    /**
     * @param  array<string, mixed>  $source
     * @return list<string>
     */
    private function configured(array $source): array
    {
        $configured = [];

        foreach ([self::MOVIE, self::PERFORMER] as $key) {
            if (! empty($source[$key])) {
                $configured[] = $key;
            }
        }
        if (! empty($source['gallery_urls']) || ! empty($source['gallery_listing_urls'])) {
            $configured[] = self::GALLERY;
        }

        return $configured;
    }

    private function label(string $requirement): string
    {
        return match ($requirement) {
            self::MOVIE => 'movie',
            self::PERFORMER => 'performer',
            default => 'gallery',
        };
    }
}
