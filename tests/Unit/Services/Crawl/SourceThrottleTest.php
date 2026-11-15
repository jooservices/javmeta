<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawl;

use App\Models\Source;
use App\Services\Crawl\SourceThrottle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SourceThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_failure_increases_gap_and_clamps_to_maximum(): void
    {
        $source = Source::factory()->create([
            'gap_seconds_current' => 20,
            'gap_seconds_min' => 10,
            'gap_seconds_max' => 30,
        ]);

        $throttle = new SourceThrottle();

        $afterFirstFailure = $throttle->onFailure($source);
        $afterSecondFailure = $throttle->onFailure($afterFirstFailure);

        $this->assertSame(30.0, (float) $afterFirstFailure->gap_seconds_current);
        $this->assertSame(30.0, (float) $afterSecondFailure->gap_seconds_current);
    }

    public function test_success_decreases_gap_and_clamps_to_minimum(): void
    {
        $source = Source::factory()->create([
            'gap_seconds_current' => 11,
            'gap_seconds_min' => 10,
            'gap_seconds_max' => 30,
        ]);

        $updated = (new SourceThrottle())->onSuccess($source);

        $this->assertSame(10.0, (float) $updated->gap_seconds_current);
    }

    public function test_current_gap_uses_current_source_value(): void
    {
        $source = Source::factory()->create(['gap_seconds_current' => 12.5]);

        $this->assertSame(12.5, (new SourceThrottle())->currentGap($source->slug));
    }

    private function sourceWithGap(float $gap, float $min = 4.0, float $max = 60.0): Source
    {
        return Source::query()->create([
            'slug' => 'adaptive-' . uniqid(), 'name' => 'A', 'base_url' => 'https://a.test', 'enabled' => true, 'priority' => 1,
            'needs_proxy' => false, 'gap_seconds_default' => $gap, 'gap_seconds_min' => $min, 'gap_seconds_max' => $max, 'gap_seconds_current' => $gap,
        ]);
    }

    public function test_blocks_and_rate_limits_double_the_gap_up_to_the_high_ceiling(): void
    {
        config()->set('javmeta_queues.gap_blocked_max_seconds', 600);
        $source = $this->sourceWithGap(40.0);
        $throttle = new SourceThrottle();

        $this->assertEquals(80.0, (float) $throttle->onFailure($source, 'rate_limited')->gap_seconds_current);
        $this->assertEquals(160.0, (float) $throttle->onFailure($source, 'blocked')->gap_seconds_current);
        $this->assertEquals(320.0, (float) $throttle->onFailure($source, 'challenge')->gap_seconds_current);
        $this->assertEquals(600.0, (float) $throttle->onFailure($source, 'challenge')->gap_seconds_current);
        $this->assertEquals(600.0, (float) $throttle->onFailure($source, 'challenge')->gap_seconds_current);
    }

    public function test_timeouts_raise_the_gap_by_half_and_parse_errors_leave_it_alone(): void
    {
        $source = $this->sourceWithGap(10.0);
        $throttle = new SourceThrottle();

        $this->assertEquals(15.0, (float) $throttle->onFailure($source, 'network')->gap_seconds_current);
        $this->assertEquals(22.5, (float) $throttle->onFailure($source, 'timeout')->gap_seconds_current);
        $this->assertEquals(22.5, (float) $throttle->onFailure($source, 'parse_failed')->gap_seconds_current);
        $this->assertEquals(22.5, (float) $throttle->onFailure($source, 'not_found')->gap_seconds_current);
        $this->assertEquals(45.0, (float) $throttle->onFailure($source)->gap_seconds_current);
    }

    public function test_success_recovers_a_large_gap_faster_than_a_fixed_step(): void
    {
        $source = $this->sourceWithGap(204.0, 4.0, 600.0);
        $throttle = new SourceThrottle();

        $this->assertEquals(184.0, (float) $throttle->onSuccess($source)->gap_seconds_current);
    }

    public function test_overrides_clamp_success_and_failure_gaps_to_the_effective_bounds(): void
    {
        config()->set('javmeta_queues.source_gap_overrides', [
            'limited-site' => ['min' => 3.0, 'default' => 5.0, 'max' => 8.0],
        ]);
        $source = $this->sourceWithGap(20.0, 1.0, 60.0);
        $source->forceFill(['slug' => 'limited-site'])->save();
        $throttle = new SourceThrottle();

        self::assertSame(8.0, $throttle->currentGap($source));
        self::assertSame(8.0, (float) $throttle->onFailure($source, 'blocked')->gap_seconds_current);
        self::assertSame(3.0, (float) $throttle->onSuccess($source->forceFill(['gap_seconds_current' => 1.0]))->gap_seconds_current);

        self::assertSame(
            4.5,
            (float) $throttle->onFailure($source->forceFill(['gap_seconds_current' => 1.0]), 'timeout')->gap_seconds_current,
        );
    }

    public function test_disabled_or_missing_latency_switch_preserves_unsaved_source_gaps_on_success_and_failure(): void
    {
        foreach ([['enabled' => false], []] as $settings) {
            config()->set('javmeta_queues.latency_gap', $settings);
            $successSource = $this->sourceWithGap(20.0, 4.0, 60.0);
            $failureSource = $this->sourceWithGap(20.0, 4.0, 60.0);
            $throttle = new SourceThrottle();

            $afterSuccess = $throttle->onSuccess($successSource->forceFill(['gap_seconds_current' => 10.0]));
            $afterFailure = $throttle->onFailure(
                $failureSource->forceFill(['gap_seconds_current' => 10.0]),
                'timeout',
            );

            self::assertSame(9.4, (float) $afterSuccess->gap_seconds_current);
            self::assertSame(15.0, (float) $afterFailure->gap_seconds_current);
        }
    }

    public function test_latency_adaptation_is_off_by_default_and_preserves_existing_success_behavior(): void
    {
        config()->set('javmeta_queues.latency_gap.enabled', false);
        $source = $this->sourceWithGap(20.0, 4.0, 60.0);
        $throttle = new SourceThrottle();

        $throttle->recordLatencySamples((string) $source->getKey(), array_fill(0, 10, 5000));
        $updated = $throttle->onSuccess($source);

        self::assertSame(18.4, (float) $updated->gap_seconds_current);
    }

    public function test_missing_latency_switch_is_off(): void
    {
        config()->set('javmeta_queues.latency_gap.enabled', null);
        $source = $this->sourceWithGap(20.0, 4.0, 60.0);
        $throttle = new SourceThrottle();

        $throttle->recordLatencySamples((string) $source->getKey(), array_fill(0, 10, 5000));
        $updated = $throttle->onSuccess($source);

        self::assertSame(18.4, (float) $updated->gap_seconds_current);
    }

    public function test_sustained_slow_latency_widens_the_gap_and_emits_a_bounded_metric(): void
    {
        $this->configureLatencyGap();
        config([
            'openobserve.enabled' => true,
            'openobserve.url' => 'https://openobserve.test',
            'openobserve.org' => 'test',
            'openobserve.service_name' => 'javmeta-test',
            'app.env' => 'testing',
        ]);
        Http::fake(['*' => Http::response([], 200)]);
        $source = $this->sourceWithGap(30.0, 4.0, 60.0);
        $throttle = new SourceThrottle();

        $sampleId = $throttle->recordLatencySamples((string) $source->getKey(), [2500]);
        $this->assertSame(30.0, (float) $throttle->onFailure($source, 'parse_failed', $sampleId)->gap_seconds_current);
        $sampleId = $throttle->recordLatencySamples((string) $source->getKey(), [3000]);
        $this->assertSame(30.0, (float) $throttle->onFailure($source, 'parse_failed', $sampleId)->gap_seconds_current);
        $sampleId = $throttle->recordLatencySamples((string) $source->getKey(), [4000]);
        $updated = $throttle->onFailure($source, 'parse_failed', $sampleId);

        self::assertSame(34.5, (float) $updated->gap_seconds_current);
        self::assertLessThanOrEqual(60.0, (float) $updated->gap_seconds_current);
        Http::assertSent(static fn(\Illuminate\Http\Client\Request $request): bool => data_get(
            $request->data(),
            'resourceMetrics.0.scopeMetrics.0.metrics.0.name',
        ) === 'javmeta_source_gap_changes_total');
    }

    public function test_healthy_latency_recovers_gradually_and_invalid_samples_are_ignored(): void
    {
        $this->configureLatencyGap();
        $source = $this->sourceWithGap(30.0, 4.0, 60.0);
        $throttle = new SourceThrottle();

        $sampleId = $throttle->recordLatencySamples((string) $source->getKey(), [2500, 3000, 4000]);
        $source = $throttle->onFailure($source, 'parse_failed', $sampleId);
        self::assertSame(34.5, (float) $source->gap_seconds_current);

        $sampleId = $throttle->recordLatencySamples((string) $source->getKey(), [100, 200, 300]);
        $firstRecovery = $throttle->onFailure($source, 'parse_failed', $sampleId);
        self::assertSame(32.98, (float) $firstRecovery->gap_seconds_current);
        self::assertGreaterThan(4.0, (float) $firstRecovery->gap_seconds_current);

        $sampleId = $throttle->recordLatencySamples((string) $source->getKey(), [-1, 300_001, '500', null, 2.5]);
        self::assertNull($sampleId);
        $unchanged = $throttle->onFailure($firstRecovery, 'parse_failed', $sampleId);
        self::assertSame(32.98, (float) $unchanged->gap_seconds_current);
    }

    public function test_latency_adaptation_clamps_changes_to_effective_source_bounds(): void
    {
        $this->configureLatencyGap();
        $source = $this->sourceWithGap(34.0, 4.0, 35.0);
        $throttle = new SourceThrottle();

        $sampleId = $throttle->recordLatencySamples((string) $source->getKey(), [2500, 3000, 4000]);
        $updated = $throttle->onFailure($source, 'parse_failed', $sampleId);

        self::assertSame(35.0, (float) $updated->gap_seconds_current);
    }

    public function test_slow_successes_accumulate_above_low_and_high_effective_minimums(): void
    {
        $this->configureLatencyGap([
            'sample_window_size' => 1,
            'sustained_samples' => 1,
        ]);
        $throttle = new SourceThrottle();

        foreach ([0.5, 2.0] as $minimum) {
            $source = $this->sourceWithGap($minimum, $minimum, 60.0);
            $previous = $minimum;

            for ($index = 0; $index < 4; $index++) {
                $sampleId = $throttle->recordLatencySamples((string) $source->getKey(), [3000]);
                $source = $throttle->onSuccess($source, $sampleId);
                $current = (float) $source->gap_seconds_current;

                if ($index < 2) {
                    self::assertSame($minimum, $current);
                } else {
                    self::assertGreaterThan($previous, $current);
                }
                self::assertGreaterThanOrEqual($minimum, $current);
                self::assertLessThanOrEqual(60.0, $current);
                $previous = $current;
            }
        }
    }

    public function test_interleaved_fetch_sample_ids_cannot_consume_each_other_or_lose_gap_updates(): void
    {
        $this->configureLatencyGap([
            'sample_window_size' => 3,
            'sustained_samples' => 2,
        ]);
        config([
            'openobserve.enabled' => true,
            'openobserve.url' => 'https://openobserve.test',
            'openobserve.org' => 'test',
            'openobserve.service_name' => 'javmeta-test',
            'app.env' => 'testing',
        ]);
        Http::fake(['*' => Http::response([], 200)]);

        $source = $this->sourceWithGap(10.0, 2.0, 60.0);
        $workerA = Source::query()->findOrFail($source->getKey());
        $workerB = Source::query()->findOrFail($source->getKey());
        $throttle = new SourceThrottle();

        $sampleA = $throttle->recordLatencySamples((string) $source->getKey(), [2500]);
        $sampleB = $throttle->recordLatencySamples((string) $source->getKey(), [3000]);
        $sampleC = $throttle->recordLatencySamples((string) $source->getKey(), [4000]);
        $invalidSample = $throttle->recordLatencySamples((string) $source->getKey(), [-1]);
        self::assertNotNull($sampleA);
        self::assertNotNull($sampleB);
        self::assertNotNull($sampleC);
        self::assertNull($invalidSample);

        $withoutSample = $throttle->onSuccess($workerB, $invalidSample);
        self::assertSame(9.2, (float) $withoutSample->gap_seconds_current);

        $afterB = $throttle->onSuccess($workerB, $sampleB);
        $afterA = $throttle->onSuccess($workerA, $sampleA);
        $afterC = $throttle->onSuccess($afterB, $sampleC);

        self::assertGreaterThan(9.2, (float) $afterB->gap_seconds_current);
        self::assertGreaterThan((float) $afterB->gap_seconds_current, (float) $afterA->gap_seconds_current);
        self::assertGreaterThan((float) $afterA->gap_seconds_current, (float) $afterC->gap_seconds_current);
        self::assertSame((float) $afterC->gap_seconds_current, (float) Source::query()->findOrFail($source->getKey())->gap_seconds_current);
        Http::assertSentCount(3);
    }

    /** @param array<string, mixed> $overrides */
    private function configureLatencyGap(array $overrides = []): void
    {
        config()->set('javmeta_queues.latency_gap', array_merge([
            'enabled' => true,
            'sample_window_size' => 3,
            'sustained_samples' => 2,
            'slow_threshold_ms' => 2000,
            'healthy_threshold_ms' => 1000,
            'increase_percent' => 15,
            'recovery_percent' => 5,
            'window_ttl_seconds' => 3600,
        ], $overrides));
    }
}
