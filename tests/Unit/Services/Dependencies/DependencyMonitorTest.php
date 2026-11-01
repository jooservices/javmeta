<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dependencies;

use App\Services\Dependencies\Dependency;
use App\Services\Dependencies\DependencyMonitor;
use App\Services\Dependencies\DependencyProbe;
use App\Services\Dependencies\DetailedDependencyProbe;
use App\Services\Dependencies\ProbeResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

final class DependencyMonitorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-03 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_caches_a_probe_result_for_ten_seconds(): void
    {
        $probe = Mockery::mock(DependencyProbe::class);
        $probe->shouldReceive('probe')->twice()->andReturn(true, true);
        $monitor = new DependencyMonitor([Dependency::Postgres->value => $probe]);

        self::assertTrue($monitor->isAvailable(Dependency::Postgres));
        Carbon::setTestNow(now()->addSeconds(9));
        self::assertTrue($monitor->isAvailable(Dependency::Postgres));
        Carbon::setTestNow(now()->addSecond());
        self::assertTrue($monitor->isAvailable(Dependency::Postgres));
    }

    public function test_it_reports_an_unconfigured_detailed_probe_as_disabled(): void
    {
        $probe = new class implements DetailedDependencyProbe {
            public function probe(): bool
            {
                return true;
            }

            public function check(): ProbeResult
            {
                return new ProbeResult(null, 'Not configured.');
            }
        };
        $monitor = new DependencyMonitor([Dependency::Browser->value => $probe]);

        $status = $monitor->check(Dependency::Browser);

        self::assertSame('disabled', $status['state']);
        self::assertTrue($status['available']);
    }

    public function test_it_opens_after_three_failures_and_closes_after_a_successful_half_open_probe(): void
    {
        $probe = Mockery::mock(DependencyProbe::class);
        $probe->shouldReceive('probe')->times(4)->andReturn(false, false, false, true);
        $monitor = new DependencyMonitor([Dependency::Postgres->value => $probe]);

        self::assertSame('down', $monitor->check(Dependency::Postgres)['state']);
        Carbon::setTestNow(now()->addSeconds(10));
        self::assertSame('down', $monitor->check(Dependency::Postgres)['state']);
        Carbon::setTestNow(now()->addSeconds(10));
        self::assertSame('open', $monitor->check(Dependency::Postgres)['state']);

        Carbon::setTestNow(now()->addSeconds(29));
        self::assertSame('open', $monitor->state(Dependency::Postgres));
        self::assertFalse($monitor->isAvailable(Dependency::Postgres));

        Carbon::setTestNow(now()->addSecond());
        self::assertSame('half_open', $monitor->state(Dependency::Postgres));
        self::assertTrue($monitor->isAvailable(Dependency::Postgres));
        self::assertSame('healthy', $monitor->state(Dependency::Postgres));
    }

    public function test_statuses_include_each_dependency_with_hardness_for_the_current_role(): void
    {
        config()->set('dependencies.role', 'worker');
        $probe = Mockery::mock(DependencyProbe::class);
        $probe->shouldReceive('probe')->once()->andReturn(true);
        $monitor = new DependencyMonitor([Dependency::Postgres->value => $probe]);

        $statuses = $monitor->statuses();

        self::assertSame(array_column(Dependency::cases(), 'value'), array_keys($statuses));
        self::assertTrue($statuses[Dependency::Postgres->value]['hard']);
        self::assertFalse($statuses[Dependency::Elasticsearch->value]['hard']);
        self::assertSame('healthy', $statuses[Dependency::Postgres->value]['state']);
    }

    public function test_it_logs_only_when_a_dependency_state_changes(): void
    {
        $probe = Mockery::mock(DependencyProbe::class);
        $probe->shouldReceive('probe')->twice()->andReturn(false, false);
        Log::shouldReceive('log')
            ->once()
            ->with(
                'warning',
                'Runtime dependency state changed.',
                Mockery::on(static fn(array $context): bool => $context['dependency'] === 'postgres'
                    && $context['from'] === 'unknown'
                    && $context['to'] === 'down'),
            );
        $monitor = new DependencyMonitor([Dependency::Postgres->value => $probe]);

        self::assertSame('down', $monitor->check(Dependency::Postgres)['state']);
        Carbon::setTestNow(now()->addSeconds(10));
        self::assertSame('down', $monitor->check(Dependency::Postgres)['state']);
    }

    public function test_it_logs_observability_failures_to_stderr_and_recovery_to_the_default_channel(): void
    {
        $probe = Mockery::mock(DependencyProbe::class);
        $probe->shouldReceive('probe')->times(4)->andReturn(false, false, false, true);
        $stderrLogger = Mockery::mock(LoggerInterface::class);
        $stderrLogger->shouldReceive('log')
            ->once()
            ->with(
                'warning',
                'Runtime dependency state changed.',
                Mockery::on(static fn(array $context): bool => $context['dependency'] === 'observability'
                    && $context['from'] === 'unknown'
                    && $context['to'] === 'down'),
            )->ordered();
        $stderrLogger->shouldReceive('log')
            ->once()
            ->with(
                'warning',
                'Runtime dependency state changed.',
                Mockery::on(static fn(array $context): bool => $context['dependency'] === 'observability'
                    && $context['from'] === 'down'
                    && $context['to'] === 'open'),
            )->ordered();
        $stderrLogger->shouldReceive('log')
            ->once()
            ->with(
                'info',
                'Runtime dependency state changed.',
                Mockery::on(static fn(array $context): bool => $context['dependency'] === 'observability'
                    && $context['from'] === 'open'
                    && $context['to'] === 'half_open'),
            )->ordered();
        Log::shouldReceive('channel')->times(3)->with('stderr')->andReturn($stderrLogger);
        Log::shouldReceive('log')
            ->once()
            ->with(
                'info',
                'Runtime dependency state changed.',
                Mockery::on(static fn(array $context): bool => $context['dependency'] === 'observability'
                    && $context['from'] === 'half_open'
                    && $context['to'] === 'healthy'),
            );
        $monitor = new DependencyMonitor([Dependency::Observability->value => $probe]);

        self::assertFalse($monitor->isAvailable(Dependency::Observability));
        Carbon::setTestNow(now()->addSeconds(10));
        self::assertFalse($monitor->isAvailable(Dependency::Observability));
        Carbon::setTestNow(now()->addSeconds(10));
        self::assertSame('open', $monitor->check(Dependency::Observability)['state']);
        Carbon::setTestNow(now()->addSeconds(30));
        self::assertTrue($monitor->isAvailable(Dependency::Observability));
    }
}
