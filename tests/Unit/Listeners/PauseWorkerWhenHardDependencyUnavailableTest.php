<?php

declare(strict_types=1);

namespace Tests\Unit\Listeners;

use App\Listeners\PauseWorkerWhenHardDependencyUnavailable;
use App\Services\Dependencies\Dependency;
use App\Services\Dependencies\DependencyMonitor;
use App\Services\Dependencies\DependencyProbe;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

final class PauseWorkerWhenHardDependencyUnavailableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('dependencies.role', 'worker');
        Carbon::setTestNow('2026-10-03 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_returns_false_while_a_hard_dependency_is_down(): void
    {
        $postgres = Mockery::mock(DependencyProbe::class);
        $postgres->shouldReceive('probe')->once()->andReturn(false);
        $monitor = new DependencyMonitor([Dependency::Postgres->value => $postgres]);
        $listener = new PauseWorkerWhenHardDependencyUnavailable($monitor);

        self::assertFalse($listener->handle(new Looping('redis', 'default')));
    }

    public function test_it_returns_true_when_a_hard_dependency_recovers(): void
    {
        $postgres = Mockery::mock(DependencyProbe::class);
        $postgres->shouldReceive('probe')->twice()->andReturn(false, true);
        $monitor = new DependencyMonitor([Dependency::Postgres->value => $postgres]);
        $listener = new PauseWorkerWhenHardDependencyUnavailable($monitor);

        self::assertFalse($listener->handle(new Looping('redis', 'default')));
        Carbon::setTestNow(now()->addSeconds(10));

        self::assertTrue($listener->handle(new Looping('redis', 'default')));
    }

    public function test_it_returns_true_when_only_soft_dependencies_are_down(): void
    {
        $postgres = Mockery::mock(DependencyProbe::class);
        $postgres->shouldReceive('probe')->once()->andReturn(true);
        $elasticsearch = Mockery::mock(DependencyProbe::class);
        $elasticsearch->shouldReceive('probe')->once()->andReturn(false);
        $monitor = new DependencyMonitor([
            Dependency::Postgres->value => $postgres,
            Dependency::Elasticsearch->value => $elasticsearch,
        ]);
        $listener = new PauseWorkerWhenHardDependencyUnavailable($monitor);

        self::assertTrue($listener->handle(new Looping('redis', 'default')));
    }
}
