<?php

declare(strict_types=1);

namespace Tests\Unit\Listeners;

use App\Listeners\WorkerBusyStateListener;
use App\Services\Report\NodeSampler;
use App\Services\Report\WorkerBusyStateStore;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Tests\TestCase;

#[CoversClass(WorkerBusyStateListener::class)]
final class WorkerBusyStateListenerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'sentry.node' => 'worker-test', 'javmeta_queues.browser_names' => ['browser_detail']]);
        Cache::store('array')->flush();
    }

    public function test_a_starting_job_marks_this_worker_busy_on_its_lane_and_a_finished_job_clears_it(): void
    {
        $listener = new WorkerBusyStateListener(new WorkerBusyStateStore());
        $key = WorkerBusyStateStore::key(NodeSampler::nodeName(), getmypid());

        $listener->processing(new JobProcessing('redis', $this->job('browser_detail')));
        $marked = Cache::get($key);
        self::assertSame('browser', $marked['lane']);
        self::assertSame('browser_detail', $marked['queue']);

        $listener->processed(new JobProcessed('redis', $this->job('browser_detail')));
        self::assertNull(Cache::get($key));
    }

    public function test_a_failed_job_also_clears_the_busy_marker(): void
    {
        $listener = new WorkerBusyStateListener(new WorkerBusyStateStore());
        $key = WorkerBusyStateStore::key(NodeSampler::nodeName(), getmypid());
        $listener->processing(new JobProcessing('redis', $this->job('detail')));
        self::assertSame('direct', Cache::get($key)['lane']);

        $listener->processed(new JobFailed('redis', $this->job('detail'), new RuntimeException('boom')));

        self::assertNull(Cache::get($key));
    }

    public function test_a_telemetry_failure_never_changes_the_job_outcome(): void
    {
        $listener = new WorkerBusyStateListener(new WorkerBusyStateStore());
        $broken = Mockery::mock(Job::class);
        $broken->shouldReceive('getQueue')->andThrow(new RuntimeException('queue unreadable'));

        $listener->processing(new JobProcessing('redis', $broken));
        Cache::shouldReceive('forget')->andThrow(new RuntimeException('cache down'));
        $listener->processed(new JobProcessed('redis', $this->job('detail')));

        $this->addToAssertionCount(1);
    }

    private function job(string $queue): Job
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('getQueue')->andReturn($queue);

        return $job;
    }
}
