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
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\DB;

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
        // Keep depth reads and Redis pushes in one scope, including concurrent manual feeders.
        $postgres = DB::getDriverName() === 'pgsql';
        if ($postgres && ! DB::selectOne('SELECT pg_try_advisory_lock(?) AS acquired', [282])->acquired) {
            $this->components->info('Another feed pool is dispatching.');

            return self::SUCCESS;
        }
        try {
            return $this->feed($queue, $observability, $throttle);
        } finally {
            if ($postgres) {
                DB::select('SELECT pg_advisory_unlock(?)', [282]);
            }
        }
    }

    private function feed(CrawlQueueService $queue, ObservabilityEmitter $observability, SourceThrottle $throttle): int
    {
        $limit = max(1, (int) ($this->option('limit') ?: config('javmeta_queues.feed_pool_limit', 50)));
        $queues = $this->queues();
        $workerId = gethostname() . ':' . getmypid();

        // Pacing: a source may have at most floor(horizon / gap) rows waiting at once, and its rows start one gap apart.
        $horizon = max(1, (int) config('javmeta_queues.pacing_horizon_seconds', 60));
        $sources = Source::query()->get()->keyBy('slug');
        $waiting = $queue->claimedWaitingBySource();
        $gapOf = static function (string $slug) use ($sources, $throttle): float {
            $source = $sources->get($slug);

            return max(1.0, $source instanceof Source ? $throttle->currentGap($source) : 5.0);
        };
        /** @var array<string, int> $budgetUsed */
        $budgetUsed = [];
        $exhausted = $this->exhaustedSources($waiting, $budgetUsed, $horizon, $gapOf);
        $dispatched = 0;
        $laneDispatched = ['direct' => 0, 'browser' => 0];
        $traceparent = TraceContext::formatTraceparent(TraceContext::newTraceId(), TraceContext::newSpanId());

        foreach ($queues as $key => $queueName) {
            $lane = str_starts_with($key, 'browser:') ? 'browser' : 'direct';
            $kind = $lane === 'browser' ? substr($key, 8) : $key;
            if ($laneDispatched[$lane] >= $limit || $queueName === '') {
                continue;
            }

            $capacity = $this->capacity($queueName, $observability);
            if ($capacity === 0) {
                continue;
            }

            $sent = $this->dispatchQueue($queue, $queueName, $kind, $lane, min($capacity, $limit - $laneDispatched[$lane]), $exhausted, $waiting, $budgetUsed, $gapOf, $traceparent, $workerId);
            $dispatched += $sent;
            $laneDispatched[$lane] += $sent;
        }

        $observability->emitOps('crawl_dispatch', ['dispatched' => $dispatched]);
        $this->components->info("Dispatched {$dispatched} crawl job(s).");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $waiting
     * @param  array<string, int>  $budgetUsed
     */
    private function dispatchQueue(CrawlQueueService $queue, string $queueName, string $kind, string $lane, int $capacity, callable $exhausted, array $waiting, array &$budgetUsed, callable $gapOf, string $traceparent, string $workerId): int
    {
        $dispatched = 0;
        for ($i = 0; $i < $capacity; $i++) {
            $row = $queue->claimNext($workerId, $kind, $exhausted(), $lane);
            if (! $row instanceof CrawlQueue) {
                break;
            }

            $slug = (string) $row->source_slug;
            $ahead = ($waiting[$slug] ?? 0) + ($budgetUsed[$slug] ?? 0);
            $delay = (int) round($ahead * $gapOf($slug));
            $budgetUsed[$slug] = ($budgetUsed[$slug] ?? 0) + 1;

            $pending = $this->dispatchJob($row, $queueName, $traceparent, $workerId);
            // A zero delay would still park the job in the delayed set; only pace the rows that really wait.
            if ($delay > 0) {
                $pending?->delay($delay);
            }

            $dispatched++;
        }

        return $dispatched;
    }

    private function capacity(string $queueName, ObservabilityEmitter $observability): int
    {
        $depthLimit = LaravelCrawlQueue::depthLimit($queueName);
        $depth = LaravelCrawlQueue::readyDepth($queueName);
        $capacity = max(0, $depthLimit - $depth);
        if ($capacity === 0) {
            $observability->emitOps('pool_gated', ['queue' => $queueName, 'depth' => $depth, 'threshold' => $depthLimit]);
        }

        return $capacity;
    }

    /**
     * @param  array<string, int>  $waiting
     * @param  array<string, int>  $budgetUsed
     */
    private function exhaustedSources(array &$waiting, array &$budgetUsed, int $horizon, callable $gapOf): callable
    {
        return static function () use (&$waiting, &$budgetUsed, $horizon, $gapOf): array {
            $slugs = [];
            foreach (array_keys($waiting + $budgetUsed) as $slug) {
                $slug = (string) $slug;
                if (($waiting[$slug] ?? 0) + ($budgetUsed[$slug] ?? 0) >= max(1, (int) floor($horizon / $gapOf($slug)))) {
                    $slugs[] = $slug;
                }
            }

            return $slugs;
        };
    }

    private function dispatchJob(CrawlQueue $row, string $queueName, string $traceparent, string $workerId): ?PendingDispatch
    {
        $pending = match ($row->kind) {
            CrawlQueue::KIND_LISTING => FetchListingJob::dispatch($row->id, $traceparent, $workerId),
            CrawlQueue::KIND_DETAIL => FetchDetailJob::dispatch($row->id, $traceparent, $workerId),
            CrawlQueue::KIND_PERFORMER_LISTING => FetchPerformerListingJob::dispatch($row->id, $traceparent, $workerId),
            CrawlQueue::KIND_PERFORMER_DETAIL => FetchPerformerDetailJob::dispatch($row->id, $traceparent, $workerId),
            CrawlQueue::KIND_GALLERY => FetchGalleryJob::dispatch($row->id, $traceparent, $workerId),
            default => null,
        };

        return $pending?->onQueue($queueName);
    }

    /** @return array<string, string> */
    private function queues(): array
    {
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
        /** @var array<string, string> $browserNames */
        $browserNames = config('javmeta_queues.browser_names', []);
        if (config('javmeta_queues.lanes_enabled', true)) {
            foreach ($browserNames as $kind => $name) {
                if (in_array($name, $feedPoolNames, true)) {
                    $queues['browser:' . $kind] = $name;
                }
            }
        }

        return $queues;
    }
}
