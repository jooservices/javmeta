<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Models\Source;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

final class SyncSourcesCommand extends Command
{
    protected $signature = 'crawler:sync-sources';

    protected $description = 'Synchronize configured JAVMeta sources.';

    public function handle(): int
    {
        $sources = config('javmeta_sources.sources', []);
        if (! is_array($sources) || $sources === []) {
            return self::SUCCESS;
        }

        foreach ($sources as $slug => $source) {
            if (! is_string($slug) || ! is_array($source)) {
                continue;
            }

            $defaultGap = (float) Arr::get($source, 'gap_seconds_default', config('javmeta_sources.defaults.fallback_gap_seconds.default', 30));
            $existing = Source::query()->find($slug);
            $attributes = [
                'name' => (string) Arr::get($source, 'name'),
                'base_url' => (string) Arr::get($source, 'base_url'),
                'priority' => (int) Arr::get($source, 'priority'),
                'needs_proxy' => (bool) Arr::get($source, 'needs_proxy', config('javmeta_sources.defaults.needs_proxy', false)),
                'gap_seconds_default' => $defaultGap,
                'gap_seconds_min' => (float) Arr::get($source, 'gap_seconds_min', config('javmeta_sources.defaults.fallback_gap_seconds.min', 30)),
                'gap_seconds_max' => (float) Arr::get($source, 'gap_seconds_max', config('javmeta_sources.defaults.fallback_gap_seconds.max', 120)),
            ];

            if ($existing instanceof Source) {
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

        return self::SUCCESS;
    }
}
