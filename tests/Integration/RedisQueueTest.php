<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\CrawlQueue;
use App\Support\Crawl\LaravelCrawlQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\Jobs\RedisQueueProbeJob;
use Tests\TestCase;

final class RedisQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_redis_workers_drain_two_hundred_jobs_without_loss_and_feed_pool_resumes(): void
    {
        config([
            'queue.default' => 'redis',
            'javmeta_queues.depth_limit' => 2,
        ]);
        $suffix = Str::lower(Str::random(12));
        $soakQueue = 'soak_' . $suffix;
        $listingQueue = 'listing_' . $suffix;
        $completionKey = 'queue-probe:' . $suffix;
        $connection = Redis::connection('default');
        $queue = Queue::connection('redis');

        try {
            for ($id = 1; $id <= 200; $id++) {
                $queue->push(new RedisQueueProbeJob((string) $id, $completionKey), '', $soakQueue);
            }

            $this->assertSame(200, LaravelCrawlQueue::readyDepth($soakQueue));
            $this->drainQueueWithTwoWorkers($soakQueue, $completionKey, 200);
            $completed = $connection->lrange($completionKey, 0, -1);
            $completedIds = array_map(static fn(string $item): string => explode(':', $item, 2)[1], $completed);
            $workerIds = array_unique(array_map(static fn(string $item): string => explode(':', $item, 2)[0], $completed));
            sort($completedIds);

            $this->assertCount(200, $completed);
            $this->assertSame(array_map('strval', range(1, 200)), $completedIds);
            $this->assertGreaterThanOrEqual(2, count($workerIds));
            $this->assertSame(0, LaravelCrawlQueue::readyDepth($soakQueue));

            $names = config('javmeta_queues.names');
            $names[CrawlQueue::KIND_LISTING] = $listingQueue;
            config(['javmeta_queues.names' => $names]);
            $pending = CrawlQueue::factory()->create([
                'source_slug' => fake()->slug(2),
                'kind' => CrawlQueue::KIND_LISTING,
            ]);
            $queue->push(new RedisQueueProbeJob('gate-1', $completionKey), '', $listingQueue);
            $queue->push(new RedisQueueProbeJob('gate-2', $completionKey), '', $listingQueue);

            $this->artisan('crawler:feed-pool')->assertSuccessful();
            $this->assertSame(CrawlQueue::STATUS_PENDING, $pending->refresh()->status);
            $this->assertSame(2, LaravelCrawlQueue::readyDepth($listingQueue));

            $this->drainQueueWithTwoWorkers($listingQueue, $completionKey, 2);
            $this->assertSame(0, LaravelCrawlQueue::readyDepth($listingQueue));
            $this->artisan('crawler:feed-pool')->assertSuccessful();

            $this->assertSame(CrawlQueue::STATUS_CLAIMED, $pending->refresh()->status);
            $this->assertSame(1, LaravelCrawlQueue::readyDepth($listingQueue));
            $this->assertSame(0, LaravelCrawlQueue::readyDepth($soakQueue));
        } finally {
            $connection->del([
                'queues:' . $soakQueue,
                'queues:' . $soakQueue . ':delayed',
                'queues:' . $soakQueue . ':reserved',
                'queues:' . $soakQueue . ':notify',
                'queues:' . $listingQueue,
                'queues:' . $listingQueue . ':delayed',
                'queues:' . $listingQueue . ':reserved',
                'queues:' . $listingQueue . ':notify',
                $completionKey,
            ]);
        }
    }

    /** @return void */
    private function drainQueueWithTwoWorkers(string $queue, string $completionKey, int $expectedJobs): void
    {
        $initialCompleted = count(Redis::connection('default')->lrange($completionKey, 0, -1));
        $targetCompleted = $initialCompleted + $expectedJobs;
        $processes = [];
        for ($worker = 0; $worker < 2; $worker++) {
            $processes[] = new Process([
                PHP_BINARY,
                'artisan',
                'queue:work',
                'redis',
                '--queue=' . $queue,
                '--sleep=1',
                '--tries=1',
                '--timeout=30',
            ], base_path());
        }

        foreach ($processes as $process) {
            $process->setTimeout(180);
            $process->start();
        }

        try {
            $deadline = microtime(true) + 180;
            do {
                $completed = Redis::connection('default')->lrange($completionKey, 0, -1);
                if (count($completed) >= $targetCompleted) {
                    break;
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);

            $this->assertCount($targetCompleted, $completed, 'Redis workers did not complete every queued probe job.');
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(5, SIGTERM);
                }
            }
        }

        foreach ($processes as $process) {
            $this->assertFalse($process->isRunning(), 'A Redis worker did not stop after the queue drained.');
        }
    }
}
