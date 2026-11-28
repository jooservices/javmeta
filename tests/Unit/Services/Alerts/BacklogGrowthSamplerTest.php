<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Alerts;

use App\Services\Alerts\BacklogGrowthSampler;
use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(BacklogGrowthSampler::class)]
final class BacklogGrowthSamplerTest extends TestCase
{
    private CarbonImmutable $start;

    protected function setUp(): void
    {
        parent::setUp();
        $this->start = CarbonImmutable::parse('2026-11-28 10:00:00');
    }

    public function test_the_first_call_has_no_history_and_reports_no_growth(): void
    {
        self::assertSame([], $this->sampler()->growthPerHour(['a' => 100], ['a'], $this->start));
    }

    public function test_a_history_shorter_than_forty_five_minutes_reports_no_growth(): void
    {
        $sampler = $this->sampler();
        $sampler->growthPerHour(['a' => 100], ['a'], $this->start);

        self::assertSame([], $sampler->growthPerHour(['a' => 5000], ['a'], $this->at(44)));
    }

    public function test_an_hour_of_history_reports_the_change_of_every_source(): void
    {
        $sampler = $this->sampler();
        $sampler->growthPerHour(['a' => 100, 'b' => 10], ['a', 'b'], $this->start);

        self::assertEquals(
            ['a' => 1500, 'b' => -10, 'c' => 5],
            $sampler->growthPerHour(['a' => 1600, 'c' => 5], ['a', 'b', 'c'], $this->at(60)),
            'A source that is new counts from zero and one that disappeared shrinks by what it had.',
        );
    }

    public function test_an_enabled_source_with_nothing_due_in_both_samples_has_zero_growth_once_the_history_is_long_enough(): void
    {
        $sampler = $this->sampler();
        $sampler->growthPerHour(['a' => 100], ['a', 'idle'], $this->start);

        self::assertSame([], $sampler->growthPerHour(['a' => 100], ['a', 'idle'], $this->at(30)), 'Still warming up: nothing is known.');
        self::assertEquals(['a' => 0, 'idle' => 0], $sampler->growthPerHour(['a' => 100], ['a', 'idle'], $this->at(60)));
    }

    public function test_the_change_is_scaled_to_one_hour(): void
    {
        $sampler = $this->sampler();
        $sampler->growthPerHour(['a' => 0], ['a'], $this->start);

        self::assertSame(['a' => 800], $sampler->growthPerHour(['a' => 600], ['a'], $this->at(45)));
    }

    public function test_a_steady_growth_is_reported_after_the_oldest_samples_are_dropped(): void
    {
        $sampler = $this->sampler();
        $growth = [];
        for ($step = 0; $step <= 20; $step++) {
            $growth = $sampler->growthPerHour(['a' => $step * 100], ['a'], $this->at($step * 5));
        }

        self::assertSame(['a' => 1200], $growth);
    }

    public function test_the_baseline_is_the_sample_nearest_one_hour_not_the_oldest_one_kept(): void
    {
        $sampler = $this->sampler();
        $growth = [];
        for ($step = 0; $step <= 17; $step++) {
            $minute = $step * 5;
            $growth = $sampler->growthPerHour(['a' => $minute >= 15 ? 1300 : 0], ['a'], $this->at($minute));
        }

        self::assertSame(['a' => 0], $growth, 'The backlog jumped 70 minutes ago and has not moved in the last hour.');
    }

    public function test_a_second_call_within_five_minutes_does_not_replace_the_baseline(): void
    {
        $sampler = $this->sampler();
        $sampler->growthPerHour(['a' => 100], ['a'], $this->start);
        $sampler->growthPerHour(['a' => 900], ['a'], $this->at(1));

        self::assertSame(['a' => 400], $sampler->growthPerHour(['a' => 500], ['a'], $this->at(60)));
    }

    public function test_a_flushed_cache_starts_again_without_a_false_growth(): void
    {
        $repository = new Repository(new ArrayStore());
        $sampler = new BacklogGrowthSampler($repository);
        $sampler->growthPerHour(['a' => 100], ['a'], $this->start);
        $repository->flush();

        self::assertSame([], $sampler->growthPerHour(['a' => 9000], ['a'], $this->at(60)));
    }

    private function at(int $minutes): CarbonImmutable
    {
        return $this->start->addMinutes($minutes);
    }

    private function sampler(): BacklogGrowthSampler
    {
        return new BacklogGrowthSampler(new Repository(new ArrayStore()));
    }
}
