<?php

declare(strict_types=1);

namespace Tests\Unit\Workers;

use App\Services\Workers\ReconcileHorizonScale;
use Laravel\Horizon\Supervisor;
use Laravel\Horizon\SupervisorOptions;
use Mockery;
use Tests\TestCase;

final class ReconcileHorizonScaleTest extends TestCase
{
    public function test_it_updates_the_running_supervisors_cap_and_keeps_a_floor_of_one(): void
    {
        $options = new SupervisorOptions(name: 'test', connection: 'redis', queue: 'listing', maxProcesses: 4);
        $supervisor = Mockery::mock(Supervisor::class);
        $supervisor->options = $options;
        $supervisor->shouldReceive('scale')->once()->with(2);
        $supervisor->shouldReceive('scale')->once()->with(1);
        $command = new ReconcileHorizonScale();

        $command->process($supervisor, ['scale' => 2]);
        $this->assertSame(2, $options->maxProcesses);

        $command->process($supervisor, ['scale' => 0]);
        $this->assertSame(1, $options->maxProcesses);
    }
}
