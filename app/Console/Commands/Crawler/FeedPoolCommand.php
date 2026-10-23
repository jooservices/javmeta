<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Jobs\FetchDetailJob;
use App\Jobs\FetchGalleryJob;
use App\Jobs\FetchListingJob;
use App\Jobs\FetchPerformerDetailJob;
use App\Jobs\FetchPerformerListingJob;
use App\Models\CrawlQueue;
use App\Observability\ObservabilityEmitter;
use App\Observability\TraceContext;
use App\Services\Crawl\CrawlQueueService;
use App\Support\Crawl\LaravelCrawlQueue;
use Illuminate\Console\Command;

/**
 * Claims pending crawl_queue rows and dispatches Laravel jobs (buffer → worker pool).
 */
final class FeedPoolCommand extends Command
{
    protected $signature = 'crawler:feed-pool {--limit= : Jobs to dispatch per run (default javmeta_queues.feed_pool_limit)}';

    protected $aliases = ['crawl:dispatch'];

    protected $description = 'Claim pending crawl_queue rows and dispatch FetchListing/FetchDetail/Performer/Gallery jobs.';

    public function handle(CrawlQueueService $queue, ObservabilityEmitter $observability): int
    {
        $limit = max(1, (int) ($this->option('limit') ?: config('javmeta_queues.feed_pool_limit', 50)));
        $depthLimit = max(1, (int) config('javmeta_queues.depth_limit', 100));
        /** @var array<string, string> $crawlQueueNames */
        $crawlQueueNames = config('javmeta_queues.names', []);
        /** @var list<string> $feedPoolNames */
        $feedPoolNames = config('javmeta_queues.feed_pool_names', array_values($crawlQueueNames));
        $queues = [];
        foreach ($feedPoolNames as $queueName) {
            $kind = array_search($queueName, $crawlQueueNames, true);
            if (is_string($kind)) {
                $queues[$kind] = $queueName;
            }
        }
        $workerId = gethostname() . ':' . getmypid();
        $dispatched = 0;
        $traceId = TraceContext::newTraceId();
        $parentSpanId = TraceContext::newSpanId();
        $traceparent = TraceContext::formatTraceparent($traceId, $parentSpanId);

        foreach ($queues as $kind => $queueName) {
            if ($dispatched >= $limit) {
                break;
            }

            if ($queueName === '') {
                continue;
            }

            $depth = LaravelCrawlQueue::readyDepth($queueName);
            $capacity = max(0, $depthLimit - $depth);
            if ($capacity === 0) {
                $observability->emitOps('pool_gated', [
                    'queue' => $queueName,
                    'depth' => $depth,
                    'threshold' => $depthLimit,
                ]);
                continue;
            }

            $queueCapacity = min($capacity, $limit - $dispatched);
            for ($i = 0; $i < $queueCapacity; $i++) {
                $row = $queue->claimNext($workerId, $kind);
                if (! $row instanceof CrawlQueue) {
                    break;
                }

                match ($row->kind) {
                    CrawlQueue::KIND_LISTING => FetchListingJob::dispatch($row->id, $traceparent, $workerId),
                    CrawlQueue::KIND_DETAIL => FetchDetailJob::dispatch($row->id, $traceparent, $workerId),
                    CrawlQueue::KIND_PERFORMER_LISTING => FetchPerformerListingJob::dispatch($row->id, $traceparent, $workerId),
                    CrawlQueue::KIND_PERFORMER_DETAIL => FetchPerformerDetailJob::dispatch($row->id, $traceparent, $workerId),
                    CrawlQueue::KIND_GALLERY => FetchGalleryJob::dispatch($row->id, $traceparent, $workerId),
                    default => null,
                };

                $dispatched++;
            }
        }

        $observability->emitOps('crawl_dispatch', ['dispatched' => $dispatched]);
        $this->components->info("Dispatched {$dispatched} crawl job(s).");

        return self::SUCCESS;
    }
}
