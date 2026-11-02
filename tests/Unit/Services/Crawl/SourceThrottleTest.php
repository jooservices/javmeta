<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawl;

use App\Models\Source;
use App\Services\Crawl\SourceThrottle;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
