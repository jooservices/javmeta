<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\Report\WorkerBusyStateStore;
use App\Services\Report\WorkerLane;
use App\Services\Report\NodeSampler;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Throwable;

final class WorkerBusyStateListener
{
    public function __construct(private readonly WorkerBusyStateStore $states) {}

    public function processing(JobProcessing $event): void
    {
        try {
            $pid = getmypid();
            if ($pid !== false) {
                $this->states->markBusy(
                    NodeSampler::nodeName(),
                    $pid,
                    WorkerLane::fromQueue($event->job->getQueue()),
                    $event->job->getQueue(),
                );
            }
        } catch (Throwable) {
            // Worker telemetry must never change a job outcome.
        }
    }

    public function processed(JobProcessed|JobFailed $event): void
    {
        try {
            $pid = getmypid();
            if ($pid !== false) {
                $this->states->clear(NodeSampler::nodeName(), $pid);
            }
        } catch (Throwable) {
            // Worker telemetry must never change a job outcome.
        }
    }
}
