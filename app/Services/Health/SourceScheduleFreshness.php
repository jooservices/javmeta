<?php

declare(strict_types=1);

namespace App\Services\Health;

use DateTimeInterface;
use JOOservices\CrawlerX\Registry\FileAdapterManifestRegistry;

/** Resolve how long each source may go without a successful crawl before it is overdue. */
final class SourceScheduleFreshness
{
    public function __construct(private readonly FileAdapterManifestRegistry $manifests) {}

    public function maximumSuccessAgeSeconds(string $slug, int $defaultSeconds): int
    {
        $maximum = max(1, $defaultSeconds);
        $manifest = $this->manifests->get($slug);
        if ($manifest === null) {
            return $maximum;
        }

        $cycleDelay = $manifest->defaultCrawlConfig['cycle_delay_seconds'] ?? null;
        if (! is_numeric($cycleDelay)) {
            foreach ($manifest->configSchema as $field) {
                if (($field['key'] ?? null) === 'cycle_delay_seconds' && is_numeric($field['default'] ?? null)) {
                    $cycleDelay = $field['default'];
                    break;
                }
            }
        }

        if (! is_numeric($cycleDelay) || (int) $cycleDelay <= 0) {
            return $maximum;
        }

        // Allow one fresh-listing scheduler sweep after a low-frequency source cycle.
        $sweepGrace = max(1, (int) config('javmeta_sources.defaults.loop.fresh_interval_minutes', 60)) * 60;

        return max($maximum, (int) $cycleDelay + $sweepGrace);
    }

    public function isPastDue(DateTimeInterface|string|null $lastSuccessAt, string $slug, int $defaultSeconds): bool
    {
        if ($lastSuccessAt === null) {
            return true;
        }

        $timestamp = $lastSuccessAt instanceof DateTimeInterface ? $lastSuccessAt->getTimestamp() : strtotime($lastSuccessAt);
        if ($timestamp === false) {
            return true;
        }

        return $timestamp < time() - $this->maximumSuccessAgeSeconds($slug, $defaultSeconds);
    }
}
