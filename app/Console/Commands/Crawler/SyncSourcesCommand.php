<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Models\Source;
use App\Services\Crawl\SourceGapPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

final class SyncSourcesCommand extends Command
{
    protected $signature = 'crawler:sync-sources';

    protected $description = 'Synchronize configured JAVMeta sources.';

    public function handle(SourceGapPolicy $gapPolicy): int
    {
        $configuredSources = config('javmeta_sources.sources', []);
        $sources = is_array($configuredSources) ? $configuredSources : [];

        foreach ($sources as $slug => $source) {
            if (! is_string($slug) || ! is_array($source)) {
                continue;
            }

            $gaps = $gapPolicy->describe($slug)['effective'];
            $defaultGap = $gaps['default'];
            $existing = Source::query()->find($slug);
            $attributes = [
                'name' => (string) Arr::get($source, 'name'),
                'base_url' => (string) Arr::get($source, 'base_url'),
                'priority' => (int) Arr::get($source, 'priority'),
                'needs_proxy' => (bool) Arr::get($source, 'needs_proxy', config('javmeta_sources.defaults.needs_proxy', false)),
                'gap_seconds_default' => $gaps['default'],
                'gap_seconds_min' => $gaps['min'],
                'gap_seconds_max' => $gaps['max'],
            ];

            if ($existing instanceof Source) {
                if ($gapPolicy->isOverridden($slug)) {
                    $attributes['gap_seconds_current'] = $gapPolicy->clamp(
                        (float) $existing->gap_seconds_current,
                        $gaps,
                    );
                }

                // The database is the only on/off switch: an existing row is never given an `enabled` value here.
                $existing->forceFill($attributes)->save();

                continue;
            }

            Source::query()->create($attributes + [
                'slug' => $slug,
                // A new source starts on unless it needs a login first.
                'enabled' => ! (bool) Arr::get($source, 'requires_login', false)
                    && (bool) Arr::get($source, 'enabled', config('javmeta_sources.defaults.enabled', true)),
                'gap_seconds_current' => $defaultGap,
                'last_success_at' => null,
                'last_error_at' => null,
                'last_error' => null,
            ]);
        }

        $overrides = (array) config('javmeta_queues.source_gap_overrides', []);
        foreach ($overrides as $slug => $override) {
            if (! is_string($slug) || array_key_exists($slug, $sources) || ! is_array($override)) {
                continue;
            }

            $existing = Source::query()->find($slug);
            if (! $existing instanceof Source) {
                continue;
            }

            $gaps = $gapPolicy->describe($slug, $existing)['effective'];
            $existing->forceFill([
                'gap_seconds_default' => $gaps['default'],
                'gap_seconds_min' => $gaps['min'],
                'gap_seconds_max' => $gaps['max'],
                'gap_seconds_current' => $gapPolicy->clamp((float) $existing->gap_seconds_current, $gaps),
            ])->save();
        }

        return self::SUCCESS;
    }
}
