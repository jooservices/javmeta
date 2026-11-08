<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Models\Source;
use Illuminate\Support\Arr;

final class SourceGapPolicy
{
    /** @return array{min: float, default: float, max: float} */
    public function definition(string $slug, ?Source $source = null): array
    {
        $configuredSources = (array) config('javmeta_sources.sources', []);
        $definition = is_array($configuredSources[$slug] ?? null) ? $configuredSources[$slug] : [];
        $fallback = (array) config('javmeta_sources.defaults.fallback_gap_seconds', []);

        $default = $this->number(
            Arr::get($definition, 'gap_seconds_default'),
            $this->sourceNumber($source, 'gap_seconds_default', (float) ($fallback['default'] ?? 30)),
        );
        $minimum = $this->number(
            Arr::get($definition, 'gap_seconds_min'),
            $this->sourceNumber($source, 'gap_seconds_min', (float) ($fallback['min'] ?? $default)),
        );
        $maximum = $this->number(
            Arr::get($definition, 'gap_seconds_max'),
            $this->sourceNumber($source, 'gap_seconds_max', (float) ($fallback['max'] ?? 120)),
        );

        return ['min' => $minimum, 'default' => $default, 'max' => $maximum];
    }

    /** @return array{min: float, default: float, max: float}|null */
    public function override(string $slug): ?array
    {
        $overrides = (array) config('javmeta_queues.source_gap_overrides', []);
        $override = $overrides[$slug] ?? null;
        if (! is_array($override)) {
            return null;
        }

        return [
            'min' => (float) $override['min'],
            'default' => (float) $override['default'],
            'max' => (float) $override['max'],
        ];
    }

    /**
     * @return array{
     *     definition: array{min: float, default: float, max: float},
     *     override: array{min: float, default: float, max: float}|null,
     *     effective: array{min: float, default: float, max: float},
     *     overridden: bool
     * }
     */
    public function describe(string $slug, ?Source $source = null): array
    {
        $definition = $this->definition($slug, $source);
        $override = $this->override($slug);

        return [
            'definition' => $definition,
            'override' => $override,
            'effective' => $override ?? $definition,
            'overridden' => $override !== null,
        ];
    }

    public function isOverridden(string $slug): bool
    {
        return $this->override($slug) !== null;
    }

    public function adaptiveGap(Source $source): float
    {
        $default = $this->sourceNumber(
            $source,
            'gap_seconds_default',
            $this->definition((string) $source->getKey(), $source)['default'],
        );
        $current = $this->sourceNumber(
            $source,
            'gap_seconds_current',
            $default,
        );
        $override = $this->override((string) $source->getKey());
        if ($override === null) {
            return $current;
        }

        return $this->clamp($current, $override);
    }

    /** @param array{min: float, default: float, max: float} $bounds */
    public function clamp(float $gap, array $bounds): float
    {
        return min($bounds['max'], max($bounds['min'], $gap));
    }

    private function sourceNumber(?Source $source, string $attribute, float $fallback): float
    {
        $value = $source?->getAttribute($attribute);

        return is_numeric($value) ? (float) $value : $fallback;
    }

    private function number(mixed $value, float $fallback): float
    {
        return is_numeric($value) ? (float) $value : $fallback;
    }
}
