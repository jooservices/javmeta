<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Repositories\SettingsServiceRepository;

/**
 * Checks the structure of the JSON settings that name a shape (performer rules, retry profiles, worker maps). Each
 * shape reports the first problem through $fail, so the caller decides how the failure is raised.
 */
final class JsonShapeValidator
{
    public function __construct(
        private readonly SettingsServiceRepository $repository,
        private readonly RetryFetchProfileValidator $retryFetchProfiles,
    ) {}

    /** @param array<array-key, mixed> $value
     *  @param callable(string): never $fail
     */
    public function validate(string $shape, array $value, callable $fail): void
    {
        if (PerformerNormalizationSettingsValidator::validateJsonShape($shape, $value, $fail)) {
            return;
        }
        if ($shape === 'retry_fetch_profile') {
            $this->retryFetchProfiles->validate($value, $fail);

            return;
        }
        if ($shape === 'source_gap_override_map') {
            $knownSources = array_fill_keys(array_map('strval', array_keys((array) config('javmeta_sources.sources', []))), true);
            foreach ($this->repository->sourceSlugs() as $sourceSlug) {
                $knownSources[$sourceSlug] = true;
            }

            foreach ($value as $slug => $bounds) {
                if (! is_string($slug) || preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug) !== 1 || ! is_array($bounds) || array_is_list($bounds)) {
                    $fail('must map source slugs to min, default, and max gap values');
                }
                if (! isset($knownSources[$slug])) {
                    $fail("references unknown source [{$slug}]");
                }
                $fields = array_keys($bounds);
                sort($fields);
                if ($fields !== ['default', 'max', 'min']) {
                    $fail('each source must define only min, default, and max');
                }
                foreach ($bounds as $gap) {
                    if ((! is_int($gap) && ! is_float($gap)) || ! is_finite((float) $gap) || $gap < 0.25 || $gap > 600) {
                        $fail('gap values must be numbers from 0.25 to 600 seconds');
                    }
                }
                if ($bounds['min'] > $bounds['default'] || $bounds['default'] > $bounds['max']) {
                    $fail('each source must satisfy min <= default <= max');
                }
            }

            return;
        }
        if ($shape === 'queue_worker_map') {
            $queues = (array) config('javmeta_queues.horizon_names', []);
            foreach ($value as $queue => $workers) {
                if (! is_string($queue) || ! in_array($queue, $queues, true) || ! is_int($workers) || $workers < 1 || $workers > 32) {
                    $fail('must map known queue names to worker counts from 1 to 32');
                }
            }

            return;
        }
        if ($shape !== 'node_worker_map') {
            $fail('has an unsupported JSON shape');
        }
        foreach ($value as $node => $overrides) {
            if (! is_string($node) || ! preg_match('/^(main|worker)(?:@[A-Za-z0-9._-]+)?$/', $node) || ! is_array($overrides) || array_is_list($overrides)) {
                $fail('must map main, worker, or role@hostname to worker overrides');
            }
            foreach ($overrides as $name => $workers) {
                if ($name === 'queues') {
                    if (! is_array($workers) || array_is_list($workers)) {
                        $fail('node queues must be an object of known queues and worker counts');
                    }
                    foreach ($workers as $queue => $count) {
                        if (! is_string($queue) || ! in_array($queue, (array) config('javmeta_queues.horizon_names', []), true) || ! is_int($count) || $count < 1 || $count > 32) {
                            $fail('node queue overrides must use known queues and worker counts from 1 to 32');
                        }
                    }
                    continue;
                }
                if (! in_array($name, ['default', 'direct', 'browser', 'retry'], true) || ! is_int($workers) || $workers < 1 || $workers > 32) {
                    $fail('node overrides may define default, direct, browser, and retry worker counts from 1 to 32');
                }
            }
        }
    }
}
