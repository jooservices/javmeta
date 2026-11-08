<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Health;

use App\Services\Health\SourceScheduleFreshness;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(SourceScheduleFreshness::class)]
final class SourceScheduleFreshnessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['javmeta_sources.defaults.loop.fresh_interval_minutes' => 30]);
        $this->travelTo('2026-11-05T03:00:00Z');
    }

    public function test_an_unknown_source_keeps_the_default_age_with_a_one_second_floor(): void
    {
        self::assertSame(900, $this->freshness()->maximumSuccessAgeSeconds('no-such-source', 900));
        self::assertSame(1, $this->freshness()->maximumSuccessAgeSeconds('no-such-source', 0));
    }

    public function test_a_source_without_a_cycle_delay_keeps_the_default_age(): void
    {
        self::assertSame(900, $this->freshness()->maximumSuccessAgeSeconds('javdb', 900));
    }

    public function test_a_source_with_a_cycle_delay_may_stay_quiet_for_the_delay_plus_one_sweep(): void
    {
        $sweep = 30 * 60;

        self::assertSame(3600 + $sweep, $this->freshness()->maximumSuccessAgeSeconds('jable', 900));
        self::assertSame(2_592_000 + $sweep, $this->freshness()->maximumSuccessAgeSeconds('javlibrary', 900));
    }

    public function test_the_default_wins_when_it_is_larger_than_the_cycle_delay_and_sweep(): void
    {
        self::assertSame(10_000_000, $this->freshness()->maximumSuccessAgeSeconds('javlibrary', 10_000_000));
    }

    public function test_the_sweep_grace_is_at_least_one_minute(): void
    {
        config(['javmeta_sources.defaults.loop.fresh_interval_minutes' => 0]);

        self::assertSame(3600 + 60, $this->freshness()->maximumSuccessAgeSeconds('jable', 900));
    }

    public function test_a_source_that_never_succeeded_or_has_an_unreadable_time_is_past_due(): void
    {
        self::assertTrue($this->freshness()->isPastDue(null, 'jable', 900));
        self::assertTrue($this->freshness()->isPastDue('not a date', 'jable', 900));
    }

    public function test_a_success_is_past_due_only_after_the_allowed_age(): void
    {
        $limit = 3600 + 30 * 60;
        $now = now()->getTimestamp();

        self::assertFalse($this->freshness()->isPastDue((new DateTimeImmutable())->setTimestamp($now - $limit), 'jable', 900));
        self::assertTrue($this->freshness()->isPastDue((new DateTimeImmutable())->setTimestamp($now - $limit - 1), 'jable', 900));
        self::assertFalse($this->freshness()->isPastDue(gmdate('c', $now - 60), 'javdb', 900));
        self::assertTrue($this->freshness()->isPastDue(gmdate('c', $now - 901), 'javdb', 900));
    }

    private function freshness(): SourceScheduleFreshness
    {
        return app(SourceScheduleFreshness::class);
    }
}
