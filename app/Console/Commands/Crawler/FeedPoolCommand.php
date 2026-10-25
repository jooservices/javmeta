<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Jobs\FetchDetailJob;
use App\Jobs\FetchGalleryJob;
use App\Jobs\FetchListingJob;
use App\Jobs\FetchPerformerDetailJob;
use App\Jobs\FetchPerformerListingJob;
use App\Models\CrawlQueue;
use App\Models\Source;
use App\Observability\ObservabilityEmitter;
use App\Observability\TraceContext;
use App\Services\Crawl\CrawlQueueService;
use App\Services\Crawl\SourceThrottle;
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

    public function handle(CrawlQueueService $queue, ObservabilityEmitter $observability, SourceThrottle $throttle): int
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

        // Pacing: a source may have at most floor(horizon / gap) rows waiting at once, and its rows start one gap apart.
        $horizon = max(1, (int) config('javmeta_queues.pacing_horizon_seconds', 60));
        $sources = Source::query()->get()->keyBy('slug');
        $waiting = $queue->claimedWaitingBySource();
        $gapOf = static function (string $slug) use ($sources, $throttle): float {
            $source = $sources->get($slug);

            return max(1.0, $source instanceof Source ? $throttle->currentGap($source) : 5.0);
        };
        $budgetUsed = [];
        $exhausted = static function () use (&$waiting, &$budgetUsed, $horizon, $gapOf): array {
            $slugs = [];
            foreach (array_keys($waiting + $budgetUsed) as $slug) {
                $slug = (string) $slug;
                if (($waiting[$slug] ?? 0) + ($budgetUsed[$slug] ?? 0) >= max(1, (int) floor($horizon / $gapOf($slug)))) {
                    $slugs[] = $slug;
                }
            }

            return $slugs;
        };
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
                $row = $queue->claimNext($workerId, $kind, $exhausted());
                if (! $row instanceof CrawlQueue) {
                    break;
                }

                $slug = (string) $row->source_slug;
                $ahead = ($waiting[$slug] ?? 0) + ($budgetUsed[$slug] ?? 0);
                $delay = (int) round($ahead * $gapOf($slug));
                $budgetUsed[$slug] = ($budgetUsed[$slug] ?? 0) + 1;

                $pending = match ($row->kind) {
                    CrawlQueue::KIND_LISTING => FetchListingJob::dispatch($row->id, $traceparent, $workerId),
                    CrawlQueue::KIND_DETAIL => FetchDetailJob::dispatch($row->id, $traceparent, $workerId),
                    CrawlQueue::KIND_PERFORMER_LISTING => FetchPerformerListingJob::dispatch($row->id, $traceparent, $workerId),
                    CrawlQueue::KIND_PERFORMER_DETAIL => FetchPerformerDetailJob::dispatch($row->id, $traceparent, $workerId),
                    CrawlQueue::KIND_GALLERY => FetchGalleryJob::dispatch($row->id, $traceparent, $workerId),
                    default => null,
                };
                // A zero delay would still park the job in the delayed set; only pace the rows that really wait.
                if ($delay > 0) {
                    $pending?->delay($delay);
                }

                $dispatched++;
            }
        }

        $observability->emitOps('crawl_dispatch', ['dispatched' => $dispatched]);
        $this->components->info("Dispatched {$dispatched} crawl job(s).");

        return self::SUCCESS;
    }
}
