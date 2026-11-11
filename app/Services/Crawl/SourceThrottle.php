<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Models\Source;
use App\Repositories\SourceRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class SourceThrottle
{
    private const LATENCY_STATE_PREFIX = 'javmeta:source-gap:latency:';

    private const MAX_LATENCY_WINDOW_SIZE = 32;

    private const MAX_LATENCY_SAMPLE_MS = 300_000;

    private readonly SourceGapPolicy $gapPolicy;

    public function __construct(?SourceGapPolicy $gapPolicy = null, private readonly ?SourceRepository $sources = null)
    {
        $this->gapPolicy = $gapPolicy ?? new SourceGapPolicy();
    }

    public function currentGap(Source|string $source): float
    {
        $model = $this->source($source);

        return $this->gapPolicy->adaptiveGap($model);
    }

    public function isOverridden(Source|string $source): bool
    {
        $model = $this->source($source);

        return $this->gapPolicy->isOverridden((string) $model->getKey());
    }

    public function onSuccess(Source|string $source, ?string $latencySampleId = null): Source
    {
        return $this->updateGap($source, $latencySampleId, function (Source $model, float $current): float {
            $slug = (string) $model->getKey();
            $overridden = $this->gapPolicy->isOverridden($slug);
            $minimum = $overridden
                ? $this->gapPolicy->describe($slug, $model)['effective']['min']
                : (float) ($model->gap_seconds_min ?? $model->gap_seconds_default);
            // Recover quickly from a big gap: 10 percent of the distance to the minimum (at least the old fixed step).
            $step = max(0.5, $minimum * 0.10, ($current - $minimum) * 0.10);

            $next = max($minimum, $current - $step);
            if ($overridden) {
                $next = $this->gapPolicy->clamp(
                    $next,
                    $this->gapPolicy->describe($slug, $model)['effective'],
                );
            }

            return $next;
        });
    }

    /**
     * The gap adapts to what the site answers: rate limits, blocks and challenges double it up to a high ceiling,
     * timeouts and network errors raise it by half, errors that say nothing about politeness (parse, not found) leave it alone.
     */
    public function onFailure(Source|string $source, ?string $errorCode = null, ?string $latencySampleId = null): Source
    {
        return $this->updateGap($source, $latencySampleId, function (Source $model, float $current) use ($errorCode): float {
            $slug = (string) $model->getKey();
            $overridden = $this->gapPolicy->isOverridden($slug);
            $bounds = $this->gapPolicy->describe($slug, $model)['effective'];
            $maximum = $overridden
                ? $bounds['max']
                : (float) ($model->gap_seconds_max ?? $model->gap_seconds_default);
            if (! $overridden) {
                $current = max($current, 1.0);
            }
            $blockedMaximum = $overridden
                ? $maximum
                : max($maximum, (float) config('javmeta_queues.gap_blocked_max_seconds', 600));

            $next = match (true) {
                in_array($errorCode, ['rate_limited', 'blocked', 'challenge'], true) => min(
                    $blockedMaximum,
                    $current * 2,
                ),
                in_array($errorCode, ['timeout', 'network'], true) => min($maximum, $current * 1.5),
                in_array($errorCode, ['parse_failed', 'normalization_failed', 'not_found', 'soft404', 'gone', 'unsupported_url', 'ambiguous_url', 'adapter_not_found'], true) => $current,
                default => min($maximum, $current * 2),
            };

            return $overridden ? $this->gapPolicy->clamp($next, $bounds) : $next;
        });
    }

    /** @param list<mixed> $samples */
    public function recordLatencySamples(string $sourceSlug, array $samples): ?string
    {
        if (! $this->latencyGapEnabled() || ! $this->validSourceSlug($sourceSlug) || $samples === []) {
            return null;
        }

        $settings = $this->latencySettings();
        $validSamples = [];
        foreach (array_slice($samples, -self::MAX_LATENCY_WINDOW_SIZE) as $sample) {
            if (is_int($sample) && $sample >= 0 && $sample <= self::MAX_LATENCY_SAMPLE_MS) {
                $validSamples[] = $sample;
            }
        }
        if ($validSamples === []) {
            return null;
        }

        $key = $this->latencyStateKey($sourceSlug);
        try {
            $sampleId = bin2hex(random_bytes(16));
            Cache::lock($key . ':lock', 5)->block(1, function () use ($key, $validSamples, $settings, $sampleId): void {
                $now = now()->getTimestamp();
                $state = Cache::get($key, []);
                $window = $this->recentLatencySamples(
                    is_array($state) ? ($state['samples'] ?? null) : null,
                    $now - $settings['window_ttl_seconds'],
                );

                foreach ($validSamples as $sample) {
                    $window[] = ['duration_ms' => $sample, 'recorded_at' => $now];
                }
                $pendingIds = $this->pendingSampleIds(is_array($state) ? ($state['pending_ids'] ?? null) : null);
                $pendingIds[] = $sampleId;

                Cache::put($key, [
                    'samples' => array_slice($window, -$settings['window_size']),
                    'pending_ids' => array_slice($pendingIds, -self::MAX_LATENCY_WINDOW_SIZE),
                ], now()->addSeconds($settings['window_ttl_seconds']));
            });

            return $sampleId;
        } catch (Throwable) {
            // Latency history is optional; a cache outage must not affect crawling.

            return null;
        }
    }

    private function source(Source|string $source): Source
    {
        if ($source instanceof Source) {
            return $source;
        }

        $model = $this->repository()->findSource($source);
        if (! $model instanceof Source) {
            throw new InvalidArgumentException("Unknown source [{$source}].");
        }

        return $model;
    }

    private function repository(): SourceRepository
    {
        return $this->sources ?? app(SourceRepository::class);
    }

    /** @param callable(Source, float): float $calculateGap */
    private function updateGap(Source|string $source, ?string $latencySampleId, callable $calculateGap): Source
    {
        $slug = $source instanceof Source ? (string) $source->getKey() : $source;
        $hasSuppliedCurrentGap = $source instanceof Source && $source->isDirty('gap_seconds_current');
        $suppliedCurrentGap = $hasSuppliedCurrentGap ? $source->getAttribute('gap_seconds_current') : null;
        /** @var array{source: Source, change: array{reason: string, direction: string}|null} $result */
        $result = DB::transaction(function () use (
            $slug,
            $latencySampleId,
            $calculateGap,
            $hasSuppliedCurrentGap,
            $suppliedCurrentGap,
        ): array {
            $model = $this->lockedSource($slug);
            if ($hasSuppliedCurrentGap) {
                // Preserve the public Source-model override semantics while still serializing persisted updates.
                $model->setAttribute('gap_seconds_current', $suppliedCurrentGap);
            }
            $current = $this->currentGap($model);
            $next = $calculateGap($model, $current);

            $this->repository()->updateSource($slug, ['gap_seconds_current' => $next]);
            $model->setAttribute('gap_seconds_current', $next);
            $change = $this->applyPendingLatencyAdjustment($model, $latencySampleId, $current);
            // Returned models represent the persisted value; a later call must reload under the row lock.
            $model->syncOriginalAttribute('gap_seconds_current');

            return [
                'source' => $model,
                'change' => $change,
            ];
        });

        if (is_array($result['change'] ?? null)) {
            try {
                app(LatencyGapMetrics::class)->recordChange($result['change']['reason'], $result['change']['direction']);
            } catch (Throwable) {
                // Metric resolution and transport failures must not affect the crawl outcome.
            }
        }

        return $result['source'];
    }

    private function lockedSource(string $slug): Source
    {
        $model = $this->repository()->findSourceForUpdate($slug);
        if (! $model instanceof Source) {
            throw new InvalidArgumentException("Unknown source [{$slug}].");
        }

        return $model;
    }

    /** @return array{reason: string, direction: string}|null */
    private function applyPendingLatencyAdjustment(Source $model, ?string $sampleId, float $previousGap): ?array
    {
        if (! $this->latencyGapEnabled() || ! $this->validSampleId($sampleId)) {
            return null;
        }

        $slug = (string) $model->getKey();
        if (! $this->validSourceSlug($slug)) {
            return null;
        }

        try {
            return $this->runPendingLatencyAdjustment($model, $slug, $sampleId, $previousGap);
        } catch (Throwable) {
            // Cache and adaptation failures must not affect the existing crawl outcome.

            return null;
        }
    }

    /** @return array{reason: string, direction: string}|null */
    private function runPendingLatencyAdjustment(Source $model, string $slug, string $sampleId, float $previousGap): ?array
    {
        $settings = $this->latencySettings();
        $key = $this->latencyStateKey($slug);

        return Cache::lock($key . ':lock', 5)->block(1, fn(): ?array => $this->consumePendingLatencyAdjustment(
            $key,
            $slug,
            $model,
            $settings,
            $sampleId,
            $previousGap,
        ));
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{reason: string, direction: string}|null
     */
    private function consumePendingLatencyAdjustment(
        string $key,
        string $slug,
        Source $model,
        array $settings,
        string $sampleId,
        float $previousGap,
    ): ?array {
        $state = Cache::get($key, []);
        if (! is_array($state)) {
            return null;
        }

        $pendingIds = $this->pendingSampleIds($state['pending_ids'] ?? null);
        if (! in_array($sampleId, $pendingIds, true)) {
            return null;
        }

        $window = $this->recentLatencySamples(
            $state['samples'] ?? null,
            now()->getTimestamp() - $settings['window_ttl_seconds'],
        );
        $state['samples'] = $window;
        $state['pending_ids'] = array_values(array_filter(
            $pendingIds,
            static fn(string $pendingId): bool => $pendingId !== $sampleId,
        ));

        if (count($window) < $settings['window_size']) {
            $this->storeLatencyState($key, $state, $settings['window_ttl_seconds']);

            return null;
        }

        $reason = $this->latencyChangeReason($window, $settings);
        if ($reason === null) {
            $this->storeLatencyState($key, $state, $settings['window_ttl_seconds']);

            return null;
        }

        return $this->adjustLatencyGap($model, $slug, $key, $state, $settings, $reason, $previousGap);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $settings
     * @return array{reason: string, direction: string}|null
     */
    private function adjustLatencyGap(
        Source $model,
        string $slug,
        string $key,
        array $state,
        array $settings,
        string $reason,
        float $previousGap,
    ): ?array {
        $bounds = $this->gapPolicy->describe($slug, $model)['effective'];
        $minimum = (float) $bounds['min'];
        $maximum = (float) $bounds['max'];
        $current = (float) $model->gap_seconds_current;
        // Existing block handling may intentionally exceed the configured source maximum.
        // Latency adaptation leaves such legacy values alone rather than changing their meaning.
        if ($current < $minimum || $current > $maximum) {
            $this->storeLatencyState($key, $state, $settings['window_ttl_seconds']);

            return null;
        }

        $direction = $reason === 'latency_high' ? 'increase' : 'decrease';
        $next = $direction === 'increase'
            ? max($current, $previousGap) * (1 + ($settings['increase_percent'] / 100))
            : $current - (($current - $minimum) * ($settings['recovery_percent'] / 100));
        // Source gaps are persisted with two decimal places; count only a stored-value change.
        $next = round($this->gapPolicy->clamp($next, $bounds), 2);
        if ($next < $minimum || $next > $maximum || abs($next - $current) < 0.000001) {
            $this->storeLatencyState($key, $state, $settings['window_ttl_seconds']);

            return null;
        }

        $this->repository()->updateSource($slug, ['gap_seconds_current' => $next]);
        $model->setAttribute('gap_seconds_current', $next);
        $this->storeLatencyState($key, $state, $settings['window_ttl_seconds']);

        return ['reason' => $reason, 'direction' => $direction];
    }

    /** @param array<string, mixed> $state */
    private function storeLatencyState(string $key, array $state, int $ttlSeconds): void
    {
        Cache::put($key, $state, now()->addSeconds($ttlSeconds));
    }

    /**
     * @param list<array{duration_ms: int, recorded_at: int}> $window
     * @param array{slow_threshold_ms: int, healthy_threshold_ms: int, sustained_samples: int} $settings
     */
    private function latencyChangeReason(array $window, array $settings): ?string
    {
        $slowSamples = count(array_filter(
            $window,
            static fn(array $sample): bool => $sample['duration_ms'] > $settings['slow_threshold_ms'],
        ));
        if ($slowSamples >= $settings['sustained_samples']) {
            return 'latency_high';
        }

        $healthySamples = count(array_filter(
            $window,
            static fn(array $sample): bool => $sample['duration_ms'] <= $settings['healthy_threshold_ms'],
        ));

        return $healthySamples >= $settings['sustained_samples'] ? 'latency_healthy' : null;
    }

    /**
     * @return array{
     *     window_size: int,
     *     sustained_samples: int,
     *     slow_threshold_ms: int,
     *     healthy_threshold_ms: int,
     *     increase_percent: float,
     *     recovery_percent: float,
     *     window_ttl_seconds: int
     * }
     */
    private function latencySettings(): array
    {
        $windowSize = $this->boundedInteger('sample_window_size', 10, 3, self::MAX_LATENCY_WINDOW_SIZE);
        $slowThreshold = $this->boundedInteger('slow_threshold_ms', 2000, 100, 60_000);

        return [
            'window_size' => $windowSize,
            'sustained_samples' => $this->boundedInteger('sustained_samples', 8, 2, $windowSize),
            'slow_threshold_ms' => $slowThreshold,
            'healthy_threshold_ms' => min(
                $slowThreshold - 1,
                $this->boundedInteger('healthy_threshold_ms', 1000, 0, 60_000),
            ),
            'increase_percent' => $this->boundedPercent('increase_percent', 15.0),
            'recovery_percent' => $this->boundedPercent('recovery_percent', 5.0),
            'window_ttl_seconds' => $this->boundedInteger('window_ttl_seconds', 3600, 60, 86_400),
        ];
    }

    private function latencyGapEnabled(): bool
    {
        return filter_var(config('javmeta_queues.latency_gap.enabled', false), FILTER_VALIDATE_BOOL) === true;
    }

    private function boundedInteger(string $key, int $default, int $minimum, int $maximum): int
    {
        $value = config('javmeta_queues.latency_gap.' . $key, $default);
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            return $default;
        }

        return min($maximum, max($minimum, (int) $value));
    }

    private function boundedPercent(string $key, float $default): float
    {
        $value = config('javmeta_queues.latency_gap.' . $key, $default);
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            return $default;
        }

        return min(50.0, max(1.0, (float) $value));
    }

    private function validSourceSlug(string $sourceSlug): bool
    {
        return preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/iD', $sourceSlug) === 1;
    }

    private function validSampleId(?string $sampleId): bool
    {
        return is_string($sampleId) && preg_match('/\A[a-f0-9]{32}\z/D', $sampleId) === 1;
    }

    /** @return list<string> */
    private function pendingSampleIds(mixed $sampleIds): array
    {
        if (! is_array($sampleIds)) {
            return [];
        }

        $validIds = array_values(array_filter(
            $sampleIds,
            fn(mixed $sampleId): bool => $this->validSampleId(is_string($sampleId) ? $sampleId : null),
        ));

        return array_slice($validIds, -self::MAX_LATENCY_WINDOW_SIZE);
    }

    private function latencyStateKey(string $sourceSlug): string
    {
        return self::LATENCY_STATE_PREFIX . hash('sha256', $sourceSlug);
    }

    /** @return list<array{duration_ms: int, recorded_at: int}> */
    private function recentLatencySamples(mixed $samples, int $windowStartedAt): array
    {
        if (! is_array($samples)) {
            return [];
        }

        $recent = [];
        foreach (array_slice($samples, -self::MAX_LATENCY_WINDOW_SIZE) as $sample) {
            if (! is_array($sample)
                || ! is_int($sample['duration_ms'] ?? null)
                || $sample['duration_ms'] < 0
                || $sample['duration_ms'] > self::MAX_LATENCY_SAMPLE_MS
                || ! is_int($sample['recorded_at'] ?? null)
                || $sample['recorded_at'] < $windowStartedAt
            ) {
                continue;
            }

            $recent[] = [
                'duration_ms' => $sample['duration_ms'],
                'recorded_at' => $sample['recorded_at'],
            ];
        }

        return $recent;
    }
}
