<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Console\Commands\NodeSampleCommand;
use App\Services\Report\ProcWorkerProcessSource;
use App\Services\Report\WorkerProcessSource;
use Tests\TestCase;

/** node:sample runs every minute in production; it failed because the process source interface had no binding. */
final class WorkerProcessSourceBindingTest extends TestCase
{
    public function test_the_worker_process_source_resolves_to_the_proc_reader(): void
    {
        self::assertInstanceOf(ProcWorkerProcessSource::class, $this->app->make(WorkerProcessSource::class));
    }

    public function test_the_node_sample_command_can_be_built_by_the_container(): void
    {
        self::assertInstanceOf(NodeSampleCommand::class, $this->app->make(NodeSampleCommand::class));
    }
}
