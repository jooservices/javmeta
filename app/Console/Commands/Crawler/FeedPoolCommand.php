<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Repositories\CrawlQueueRepository;
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
use App\Services\Crawl\FeedPoolDiagnostics;
use App\Services\Crawl\FeedPoolStopReason;
use App\Services\Crawl\SourceThrottle;
use App\Services\Health\CrawlDispatchHeartbeat;
use Carbon\CarbonImmutable;
use App\Support\Crawl\LaravelCrawlQueue;
use Illuminate\Console\Command;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Claims pending crawl_queue rows and dispatches Laravel jobs (buffer → worker pool).
 */
final class FeedPoolCommand extends Command
{
    protected $signature = 'crawler:feed-pool {--limit= : Per-lane jobs for this invocation (defaults to the scaled per-minute setting)} {--scheduled : Apply the current database feed interval}';

    protected $aliases = ['crawl:dispatch'];

    protected $description = 'Claim pending crawl_queue rows and dispatch FetchListing/FetchDetail/Performer/Gallery jobs.';

    public function handle(CrawlQueueService $queue, ObservabilityEmitter $observability, SourceThrottle $throttle, CrawlDispatchHeartbeat $dispatchHeartbeat, FeedPoolDiagnostics $diagnostics): int
    {
        $runStartedAt = hrtime(true);
        $intervalStopReason = null;
        if ($this->option('scheduled') && ! $this->feedIntervalElapsed($intervalStopReason)) {
            $reason = $intervalStopReason ?? FeedPoolStopReason::forQueue(false, true, true, true);
            $this->recordDiagnosticsSafely($diagnostics, [], [], $this->stopReasonsForQueues($this->queues(), (string) $reason), $runStartedAt, 0);
            $this->components->info('Feed interval has not elapsed.');

            return self::SUCCESS;
        }

        // Keep depth reads and Redis pushes in one scope, including concurrent manual feeders.
        $postgres = app(CrawlQueueRepository::class)->driverName() === 'pgsql';
        if ($postgres && ! app(CrawlQueueRepository::class)->tryFeedPoolAdvisoryLock()) {
            $reason = FeedPoolStopReason::forQueue(true, false, true, true);
            $this->recordDiagnosticsSafely($diagnostics, [], [], $this->stopReasonsForQueues($this->queues(), (string) $reason), $runStartedAt, 0);
            $this->components->info('Another feed pool is dispatching.');

            return self::SUCCESS;
        }
        try {
            return $this->feed($queue, $observability, $throttle, $dispatchHeartbeat, $diagnostics, $runStartedAt);
        } finally {
            if ($postgres) {
                app(CrawlQueueRepository::class)->releaseFeedPoolAdvisoryLock();
            }
        }
    }

    private function feed(CrawlQueueService $queue, ObservabilityEmitter $observability, SourceThrottle $throttle, CrawlDispatchHeartbeat $dispatchHeartbeat, FeedPoolDiagnostics $diagnostics, int $runStartedAt): int
    {
        $sourceSnapshots = [];
        /** @var array<string, int> $dispatchedBySource */
        $dispatchedBySource = [];
        $stopReasons = [];
        $claimRowsExamined = 0;

        try {
            $limitPerMinute = max(1, (int) config('javmeta_queues.feed_pool_limit', 50));
            $limitPerRun = $this->scaledRunLimit($limitPerMinute);
            $retryLimitPerRun = $this->scaledRunLimit(max(1, (int) config('javmeta_queues.retry.feed_pool_limit', 5)));
            if ($this->option('limit') !== null) {
                $limitPerRun = min($limitPerRun, max(1, (int) $this->option('limit')));
                $retryLimitPerRun = min($retryLimitPerRun, max(1, (int) $this->option('limit')));
            }
            $queues = $this->queues();
            $workerId = gethostname() . ':' . getmypid();

            // Keep queued work and recent starts in the same rolling politeness window.
            $horizon = max(1, (int) config('javmeta_queues.pacing_horizon_seconds', 60));
            $activity = $queue->pacingActivityBySource(now()->subSeconds($horizon), 'direct');
            $waiting = array_map(static fn(array $source): int => $source['waiting'], $activity);
            $budgetUsed = array_map(static fn(array $source): int => $source['started'], $activity);
            $latestStartedAt = array_filter(
                array_map(static fn(array $source): ?CarbonImmutable => $source['latest_started_at'], $activity),
                static fn(?CarbonImmutable $timestamp): bool => $timestamp !== null,
            );
            $sourceGaps = [];
            $gapOf = $this->sourceGaps($throttle, $sourceGaps);
            $retryHorizon = max(1, (int) config('javmeta_queues.retry.pacing_horizon_seconds', 60));
            $retryActivity = $queue->pacingActivityBySource(now()->subSeconds($retryHorizon), 'retry');
            $retryWaiting = array_map(static fn(array $source): int => $source['waiting'], $retryActivity);
            $retryBudgetUsed = array_map(static fn(array $source): int => $source['started'], $retryActivity);
            $retryLatestStartedAt = array_filter(
                array_map(static fn(array $source): ?CarbonImmutable => $source['latest_started_at'], $retryActivity),
                static fn(?CarbonImmutable $timestamp): bool => $timestamp !== null,
            );
            $retryMultiplier = max(1.0, (float) config('javmeta_queues.retry.pacing_gap_multiplier', 2.0));
            $retryGapOf = static fn(string $slug): float => $gapOf($slug) * $retryMultiplier;
            foreach (array_unique([...array_keys($sourceGaps), ...array_keys($activity)]) as $slug) {
                $slug = (string) $slug;
                $sourceActivity = $activity[$slug] ?? ['waiting' => 0, 'started' => 0];
                $sourceSnapshots[$slug] = [
                    'budget' => max(1, (int) floor($horizon / $gapOf($slug))),
                    'started' => (int) $sourceActivity['started'],
                    'waiting' => (int) $sourceActivity['waiting'],
                ];
            }
            $exhausted = $this->exhaustedSources($waiting, $budgetUsed, $horizon, $gapOf);
            $traceparent = TraceContext::formatTraceparent(TraceContext::newTraceId(), TraceContext::newSpanId());
            $dispatch = $this->dispatchQueues(
                $queues,
                $queue,
                $observability,
                $limitPerRun,
                $exhausted,
                $waiting,
                $budgetUsed,
                $dispatchedBySource,
                $stopReasons,
                $latestStartedAt,
                $gapOf,
                $retryLimitPerRun,
                $retryWaiting,
                $retryBudgetUsed,
                $retryLatestStartedAt,
                $retryGapOf,
                $retryHorizon,
                $traceparent,
                $workerId,
                $claimRowsExamined,
            );
            $dispatched = $dispatch['dispatched'];
            $laneDispatched = $dispatch['lane_dispatched'];

            $this->emitDispatchMetrics($laneDispatched, $limitPerMinute, $limitPerRun, $retryLimitPerRun, $observability);

            $observability->emitOps('crawl_dispatch', ['dispatched' => $dispatched]);
            if ($dispatched > 0) {
                $dispatchHeartbeat->record();
            }
            $this->components->info("Dispatched {$dispatched} crawl job(s).");

            return self::SUCCESS;
        } finally {
            $this->recordDiagnosticsSafely($diagnostics, $sourceSnapshots, $dispatchedBySource, $stopReasons, $runStartedAt, $claimRowsExamined);
        }
    }

    /** @param array{direct: int, browser: int, retry: int} $laneDispatched */
    private function emitDispatchMetrics(array $laneDispatched, int $limitPerMinute, int $limitPerRun, int $retryLimitPerRun, ObservabilityEmitter $observability): void
    {
        foreach ($laneDispatched as $lane => $count) {
            $laneLimitPerMinute = $lane === 'retry' ? max(1, (int) config('javmeta_queues.retry.feed_pool_limit', 5)) : $limitPerMinute;
            $laneLimitPerRun = $lane === 'retry' ? $retryLimitPerRun : $limitPerRun;
            $observability->emitOps('feed_dispatched', [
                'lane' => $lane,
                'dispatched' => $count,
                'limit_per_minute' => $laneLimitPerMinute,
                'limit_per_run' => $laneLimitPerRun,
            ]);
            if ($laneLimitPerRun === 0 || $count >= $laneLimitPerRun) {
                $observability->emitOps('feed_gated', [
                    'reason' => 'global_cap',
                    'lane' => $lane,
                    'limit_per_minute' => $laneLimitPerMinute,
                    'limit_per_run' => $laneLimitPerRun,
                ]);
            }
        }
    }

    /**
     * @param  array<string, string>  $queues
     * @param  array<string, int>  $waiting
     * @param  array<string, int>  $budgetUsed
     * @param  array<string, int>  $dispatchedBySource
     * @param  list<array{queue: string, lane: string, reason: string}>  $stopReasons
     * @param  array<string, CarbonImmutable>  $latestStartedAt
     * @param-out  array<string, int>  $budgetUsed
     * @param-out  array<string, int>  $dispatchedBySource
     * @param-out  int  $claimRowsExamined
     * @return array{dispatched: int, lane_dispatched: array{direct: int, browser: int, retry: int}}
     */
    private function dispatchQueues(array $queues, CrawlQueueService $queue, ObservabilityEmitter $observability, int $limitPerRun, callable $exhausted, array $waiting, array &$budgetUsed, array &$dispatchedBySource, array &$stopReasons, array $latestStartedAt, callable $gapOf, int $retryLimitPerRun, array $retryWaiting, array &$retryBudgetUsed, array $retryLatestStartedAt, callable $retryGapOf, int $retryHorizon, string $traceparent, string $workerId, int &$claimRowsExamined): array
    {
        $dispatched = 0;
        $laneDispatched = ['direct' => 0, 'browser' => 0, 'retry' => 0];
        /** @var array<string, int> $retryDispatchedBySource */
        $retryDispatchedBySource = [];
        foreach ($queues as $key => $queueName) {
            $lane = str_starts_with($key, 'browser:') ? 'browser' : (str_starts_with($key, 'retry:') ? 'retry' : 'direct');
            $kind = $lane === 'browser' || $lane === 'retry' ? substr($key, strlen($lane) + 1) : $key;
            $laneLimit = $lane === 'retry' ? $retryLimitPerRun : $limitPerRun;
            $laneWaiting = $lane === 'retry' ? $retryWaiting : $waiting;
            if ($lane === 'retry') {
                $laneBudgetUsed = &$retryBudgetUsed;
                $laneDispatchedBySource = &$retryDispatchedBySource;
            } else {
                $laneBudgetUsed = &$budgetUsed;
                $laneDispatchedBySource = &$dispatchedBySource;
            }
            $laneLatestStartedAt = $lane === 'retry' ? $retryLatestStartedAt : $latestStartedAt;
            $laneGapOf = $lane === 'retry' ? $retryGapOf : $gapOf;
            $laneExhausted = $lane === 'retry'
                ? $this->exhaustedSources($retryWaiting, $retryBudgetUsed, $retryHorizon, $retryGapOf)
                : $exhausted;
            if ($queueName === '') {
                $stopReasons[] = ['queue' => (string) $queueName, 'lane' => $lane, 'reason' => 'no_due_rows'];
                continue;
            }

            $remainingLane = $laneLimit - $laneDispatched[$lane];
            if ($remainingLane <= 0) {
                $stopReasons[] = ['queue' => $queueName, 'lane' => $lane, 'reason' => (string) FeedPoolStopReason::forQueue(true, true, false, true)];
                continue;
            }

            $depthCapacity = $this->capacity($queueName, $observability);
            if ($depthCapacity === 0) {
                $stopReasons[] = ['queue' => $queueName, 'lane' => $lane, 'reason' => (string) FeedPoolStopReason::forQueue(true, true, true, false)];
                continue;
            }

            $claimStopReason = null;
            $claimCapacity = min($depthCapacity, $remainingLane);
            $sent = $this->dispatchQueue(
                $queue,
                $observability,
                $queueName,
                $kind,
                $lane,
                $claimCapacity,
                $laneExhausted,
                $laneWaiting,
                $laneBudgetUsed,
                $laneDispatchedBySource,
                $laneLatestStartedAt,
                $laneGapOf,
                $traceparent,
                $workerId,
                $claimRowsExamined,
                $claimStopReason,
            );
            $dispatched += $sent;
            $laneDispatched[$lane] += $sent;

            $reason = $claimStopReason ?? FeedPoolStopReason::forQueue(
                true,
                true,
                $remainingLane > $sent,
                $depthCapacity > $sent,
            );
            $stopReasons[] = [
                'queue' => $queueName,
                'lane' => $lane,
                'reason' => $reason ?? 'no_due_rows',
            ];
        }

        foreach ($retryDispatchedBySource as $source => $count) {
            $dispatchedBySource[$source] = ($dispatchedBySource[$source] ?? 0) + $count;
        }

        return ['dispatched' => $dispatched, 'lane_dispatched' => $laneDispatched];
    }

    /** @param array<string, float> $sourceGaps */
    private function sourceGaps(SourceThrottle $throttle, array &$sourceGaps): callable
    {
        $sources = app(CrawlQueueRepository::class)->modelQuery(Source::class)->get()->keyBy('slug');
        foreach ($sources as $slug => $source) {
            $gap = $throttle->currentGap($source);
            $sourceGaps[(string) $slug] = $throttle->isOverridden($source) ? $gap : max(1.0, $gap);
        }

        return static function (string $slug) use (&$sourceGaps): float {
            return $sourceGaps[$slug] ?? 5.0;
        };
    }

    /**
     * @param  array<string, int>  $waiting
     * @param  array<string, int>  $budgetUsed
     * @param  array<string, int>  $dispatchedBySource
     * @param-out  array<string, int>  $budgetUsed
     * @param-out  array<string, int>  $dispatchedBySource
     * @param-out  int  $claimRowsExamined
     */
    private function dispatchQueue(CrawlQueueService $queue, ObservabilityEmitter $observability, string $queueName, string $kind, string $lane, int $capacity, callable $exhausted, array $waiting, array &$budgetUsed, array &$dispatchedBySource, array $latestStartedAt, callable $gapOf, string $traceparent, string $workerId, int &$claimRowsExamined, ?string &$claimStopReason): int
    {
        $dispatched = 0;
        $claimStopReason = null;
        for ($i = 0; $i < $capacity; $i++) {
            $excludedSources = $exhausted();
            /** @var array{rows_examined: int, stop_reason: ?string} $claimDiagnostics */
            $claimDiagnostics = [];
            $row = $queue->claimNext($workerId, $kind, $excludedSources, $lane, $claimDiagnostics);
            $claimRowsExamined += $claimDiagnostics['rows_examined'];
            if (! $row instanceof CrawlQueue) {
                $claimStopReason = (string) ($claimDiagnostics['stop_reason'] ?? 'no_due_rows');
                if ($excludedSources !== []) {
                    $observability->emitOps('feed_gated', [
                        'reason' => 'source_budget',
                        'lane' => $lane,
                        'queue' => $queueName,
                        'sources' => count($excludedSources),
                    ]);
                }

                break;
            }

            $slug = (string) $row->source_slug;
            $gap = $gapOf($slug);
            $ahead = ($waiting[$slug] ?? 0) + ($dispatchedBySource[$slug] ?? 0);
            $delay = $this->delayBeforeNextStart($latestStartedAt[$slug] ?? null, $gap)
                + ($ahead * $gap);
            $delay = (int) ceil($delay);
            $budgetUsed[$slug] = ($budgetUsed[$slug] ?? 0) + 1;
            $dispatchedBySource[$slug] = ($dispatchedBySource[$slug] ?? 0) + 1;

            $pending = $this->dispatchJob($row, $queueName, $traceparent, $workerId);
            // A zero delay would still park the job in the delayed set; only pace the rows that really wait.
            if ($delay > 0) {
                $pending?->delay($delay);
            }

            $dispatched++;
        }

        return $dispatched;
    }

    /** @param array<string, string> $queues
     *  @return list<array{queue: string, lane: string, reason: string}>
     */
    private function stopReasonsForQueues(array $queues, string $reason): array
    {
        $stops = [];
        foreach ($queues as $key => $queueName) {
            $stops[] = [
                'queue' => $queueName,
                'lane' => str_starts_with($key, 'browser:') ? 'browser' : (str_starts_with($key, 'retry:') ? 'retry' : 'direct'),
                'reason' => $reason,
            ];
        }

        return $stops;
    }

    /**
     * @param array<string, array{budget: int, started: int, waiting: int}> $sources
     * @param array<string, int> $dispatchedBySource
     * @param list<array{queue: string, lane: string, reason: string}> $stopReasons
     */
    private function recordDiagnosticsSafely(FeedPoolDiagnostics $diagnostics, array $sources, array $dispatchedBySource, array $stopReasons, int $runStartedAt, int $claimRowsExamined): void
    {
        try {
            $diagnostics->recordRun(
                $sources,
                $dispatchedBySource,
                $stopReasons,
                (int) ((hrtime(true) - $runStartedAt) / 1_000_000),
                $claimRowsExamined,
            );
        } catch (Throwable) {
            // Diagnostics must not delay or interrupt feed dispatch.
        }
    }

    private function capacity(string $queueName, ObservabilityEmitter $observability): int
    {
        $depthLimit = LaravelCrawlQueue::depthLimit($queueName);
        $depth = LaravelCrawlQueue::readyDepth($queueName);
        $capacity = max(0, $depthLimit - $depth);
        if ($depth >= $depthLimit) {
            $observability->emitOps('pool_gated', ['queue' => $queueName, 'depth' => $depth, 'threshold' => $depthLimit]);
        }
        if ($depth > 0) {
            $observability->emitOps('feed_gated', [
                'reason' => 'depth',
                'lane' => in_array($queueName, (array) config('javmeta_queues.browser_names', []), true)
                    ? 'browser'
                    : (in_array($queueName, (array) config('javmeta_queues.retry_names', []), true) ? 'retry' : 'direct'),
                'queue' => $queueName,
                'depth' => $depth,
                'threshold' => $depthLimit,
            ]);
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

    private function scaledRunLimit(int $limitPerMinute): int
    {
        $interval = (int) config('javmeta_queues.feed_interval_seconds', 10);
        if (! in_array($interval, [5, 10, 15, 30, 60], true)) {
            $interval = 10;
        }

        $slotStart = intdiv(now()->second, $interval) * $interval;
        $slotEnd = $slotStart + $interval;

        return intdiv($limitPerMinute * $slotEnd, 60) - intdiv($limitPerMinute * $slotStart, 60);
    }

    private function feedIntervalElapsed(?string &$stopReason = null): bool
    {
        $interval = (int) config('javmeta_queues.feed_interval_seconds', 10);
        if (! in_array($interval, [5, 10, 15, 30, 60], true)) {
            $interval = 10;
        }

        $lock = Cache::lock('javmeta:crawl:feed-pool:interval-lock', 5);
        if (! $lock->get()) {
            $stopReason = 'advisory_lock';

            return false;
        }

        try {
            $last = (int) Cache::get('javmeta:crawl:feed-pool:last-scheduled', 0);
            if ($last > 0 && now()->timestamp < $last + $interval) {
                $stopReason = 'interval_not_elapsed';

                return false;
            }
            Cache::put('javmeta:crawl:feed-pool:last-scheduled', now()->timestamp, 86400);

            return true;
        } finally {
            $lock->release();
        }
    }

    private function delayBeforeNextStart(?CarbonImmutable $latestStartedAt, float $gap): int
    {
        if ($latestStartedAt === null) {
            return 0;
        }

        $elapsed = max(0, $latestStartedAt->diffInMicroseconds(now()) / 1_000_000);

        return max(0, (int) ceil($gap - $elapsed));
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
        /** @var array<string, string> $retryNames */
        $retryNames = config('javmeta_queues.retry_names', []);
        foreach ($retryNames as $kind => $name) {
            if (in_array($name, $feedPoolNames, true)) {
                $queues['retry:' . $kind] = $name;
            }
        }

        return $queues;
    }
}
