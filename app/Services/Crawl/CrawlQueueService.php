<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Enums\CrawlErrorCode;
use App\Models\CrawlEvent;
use App\Models\CrawlQueue;
use App\Models\Source;
use App\Observability\Sentry\CrawlFailureReporter;
use App\Repositories\CrawlQueueRepository;
use App\Repositories\CrawlEventRepository;
use App\Repositories\SourceRepository;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use RuntimeException;

final class CrawlQueueService
{
    private readonly SourceGapPolicy $sourceGapPolicy;
    private readonly SourceRequestPacer $sourceRequestPacer;

    public function __construct(
        ?SourceGapPolicy $sourceGapPolicy = null,
        ?SourceRequestPacer $sourceRequestPacer = null,
        private readonly ?CrawlQueueRepository $queueRepository = null,
        private readonly ?SourceRepository $sourceRepository = null,
        private readonly ?CrawlEventRepository $eventRepository = null,
    ) {
        $this->sourceGapPolicy = $sourceGapPolicy ?? new SourceGapPolicy();
        $this->sourceRequestPacer = $sourceRequestPacer ?? new SourceRequestPacer();
    }

    /**
     * @param  list<string>  $excludeSources  sources that must not get a row now (for example their pacing budget is used up)
     */
    /**
     * @param  array{rows_examined?: int, stop_reason?: ?string}|null  $diagnostics
     * @param-out  array{rows_examined: int, stop_reason: ?string}  $diagnostics
     */
    public function claimNext(string $workerId, ?string $kind = null, array $excludeSources = [], ?string $lane = null, ?array &$diagnostics = null): ?CrawlQueue
    {
        $disabledSources = array_values(array_unique([
            ...$this->sourceRepository()->disabledSlugs(),
            ...app(SourceCircuitBreaker::class)->pausedSourceSlugs(),
        ]));

        return $this->queueRepository()->claimNext(
            $workerId,
            $kind,
            $excludeSources,
            $lane,
            $diagnostics,
            $disabledSources,
            app(CrawlLaneService::class),
        );
    }

    /**
     * Rows that are claimed and not finished yet, per source (all kinds): work that is already waiting in the worker queues.
     *
     * @return array<string, int>
     */
    public function claimedWaitingBySource(): array
    {
        return $this->queueRepository()->claimedWaitingBySource();
    }

    /**
     * Pending claims and recent request activity used to preserve the source's pacing window across feeder runs.
     * An active claim counts once until its current request attempt has been recorded.
     *
     * @return array<string, array{waiting: int, started: int, latest_started_at: ?CarbonImmutable}>
     */
    public function pacingActivityBySource(DateTimeInterface $since, ?string $lane = null): array
    {
        return $this->queueRepository()->pacingActivityBySource($since, $lane);
    }

    public function enqueue(string $url, string $sourceSlug, string $kind, ?int $maxAttempts = null): CrawlQueue
    {
        $configuredMaxAttempts = $maxAttempts ?? (int) config("javmeta_sources.sources.{$sourceSlug}.max_attempts", config('javmeta_sources.defaults.max_attempts', 3));
        $source = $this->sourceRepository()->findSource($sourceSlug);

        return $this->queueRepository()->enqueue($url, $sourceSlug, $kind, $configuredMaxAttempts, $this->nextSourceAttemptAt($source));
    }

    /**
     * Starts a source's listing again from page 1 once every listing chain of that kind has reached its last
     * page: the finished page rows are removed (so the pages can be queued again), the seed pages are reset and
     * they wait the configured pause. Detail rows are left alone: the movies already known stay known, new ones
     * are queued as the pages are read again.
     *
     * @param  list<string>  $seedUrls
     */
    public function restartListingLoopIfFinished(string $sourceSlug, string $kind, array $seedUrls): bool
    {
        if ($seedUrls === [] || ! in_array($kind, [CrawlQueue::KIND_LISTING, CrawlQueue::KIND_PERFORMER_LISTING], true)) {
            return false;
        }

        $pause = max(0, (int) config('javmeta_sources.defaults.loop.pause_seconds', 300));

        return $this->queueRepository()->restartListingLoop(
            $sourceSlug,
            $kind,
            $seedUrls,
            $pause,
            function (string $url) use ($sourceSlug, $kind): array {
                $maxAttempts = (int) config("javmeta_sources.sources.{$sourceSlug}.max_attempts", config('javmeta_sources.defaults.max_attempts', 3));
                $source = $this->sourceRepository()->findSource($sourceSlug);

                return [
                    'kind' => $kind,
                    'status' => CrawlQueue::STATUS_PENDING,
                    'attempts' => 0,
                    'max_attempts' => $maxAttempts,
                    'next_attempt_at' => $this->nextSourceAttemptAt($source),
                    'claimed_at' => null,
                    'started_at' => null,
                    'locked_by' => null,
                    'last_error' => null,
                    'error_code' => null,
                    'last_failed_at' => null,
                ];
            },
        );
    }

    /**
     * Requeues stale page-one listing rows without waiting for the rest of the listing chain to finish.
     * The due timestamp stored on each completed listing row is the crawl timestamp; legacy rows without one
     * are treated as due once done.
     * Detail rows and later listing pages are left untouched.
     *
     * @param  list<string>  $seedUrls
     */
    public function refreshListingSeedsIfDue(string $sourceSlug, string $kind, array $seedUrls): int
    {
        if ($seedUrls === [] || ! in_array($kind, [CrawlQueue::KIND_LISTING, CrawlQueue::KIND_PERFORMER_LISTING], true)) {
            return 0;
        }

        $interval = max(1, (int) config('javmeta_sources.defaults.loop.fresh_interval_minutes', 60));

        return $this->queueRepository()->refreshListingSeeds(
            $sourceSlug,
            $kind,
            $seedUrls,
            $interval,
            function () use ($sourceSlug): DateTimeInterface {
                $source = $this->sourceRepository()->findSource($sourceSlug);
                return $this->nextSourceAttemptAt($source);
            },
        );
    }

    public function markFailed(
        CrawlQueue $row,
        CrawlErrorCode $errorCode,
        string $message,
        bool $retryable,
        ?int $retryAfterSeconds = null,
    ): void {
        $safeMessage = self::sanitizeErrorMessage($message);
        $policy = app(CrawlRetryPolicy::class);
        $isRetryable = $policy->shouldRetry($errorCode, $retryable);
        $isRetryLane = $row->claimed_lane === 'retry' || $row->status === CrawlQueue::STATUS_RETRY_PENDING;
        $attemptLimit = $isRetryLane
            ? max(1, (int) config('javmeta_queues.retry.attempts_per_round', 3))
            : max(1, (int) $row->max_attempts);
        $attemptsRemain = $row->attempts < $attemptLimit;
        $maxRounds = max(1, (int) config('javmeta_queues.retry.max_rounds', 3));
        $round = (int) $row->retry_round;
        $nextStatus = CrawlQueue::STATUS_FAILED;
        $nextAttemptAt = null;
        $nextRound = $round;

        if ($isRetryable && $attemptsRemain) {
            $nextStatus = $isRetryLane ? CrawlQueue::STATUS_RETRY_PENDING : CrawlQueue::STATUS_PENDING;
            $delay = $this->retryBackoffSeconds($row, $errorCode, $retryAfterSeconds);
            if ($isRetryLane) {
                $delay = max(1, $delay);
            }
            $nextAttemptAt = now()->addSeconds(max(1, (int) $delay));
        } elseif ($isRetryable && $round < $maxRounds) {
            $nextStatus = CrawlQueue::STATUS_RETRY_PENDING;
            $nextRound = max(1, $round + 1);
            $nextAttemptAt = now()->addSeconds($this->retryRoundDelaySeconds($nextRound, $retryAfterSeconds));
        }

        if (! $isRetryLane && $isRetryable && ! $attemptsRemain) {
            $nextStatus = CrawlQueue::STATUS_RETRY_PENDING;
            $nextRound = 1;
            $nextAttemptAt = now()->addSeconds($this->retryRoundDelaySeconds($nextRound, $retryAfterSeconds));
        }


        $this->queueRepository()->updateQueue($row, [
            'status' => $nextStatus,
            'retry_round' => $nextRound,
            'attempts' => $nextRound !== $round ? 0 : $row->attempts,
            'claimed_at' => null,
            'started_at' => null,
            'locked_by' => null,
            'next_attempt_at' => $nextAttemptAt,
            'error_code' => $errorCode->value,
            'last_error' => $safeMessage,
            'retryable' => $isRetryable,
            'last_failed_at' => now(),
        ]);

        if ($isRetryLane && $nextStatus === CrawlQueue::STATUS_FAILED) {
            app(CrawlRetryMetrics::class)->increment((string) $row->source_slug, max(1, $round), 'failed_permanent', (string) ($row->retry_egress_label ?? 'default'));
        }
    }

    public function startClaimedRow(int $id, string $expectedKind, ?string $claimToken): ?CrawlQueue
    {
        return $this->queueRepository()->startClaimedRow($id, $expectedKind, $claimToken, function (CrawlQueue $row): ?array {
            $sourceSlug = (string) $row->source_slug;
            if (app(SourceCircuitBreaker::class)->isPaused($sourceSlug)) {
                return null;
            }
            if ($this->sourceGapPolicy->isOverridden($sourceSlug)) {
                $source = $this->sourceRepository()->findSource($sourceSlug);
                if ($source instanceof Source) {
                    $retryMultiplier = $row->claimed_lane === 'retry'
                        ? max(1.0, (float) config('javmeta_queues.retry.pacing_gap_multiplier', 2.0))
                        : 1.0;
                    $wait = $this->sourceRequestPacer->reserveStart(
                        $sourceSlug,
                        $this->sourceGapPolicy->adaptiveGap($source) * $retryMultiplier,
                        (string) ($row->claimed_lane ?? 'direct'),
                        $this->pacingWaitBudgetMicroseconds(),
                    );
                    if ($wait === null) {
                        return null;
                    }
                    $this->sourceRequestPacer->wait($wait);
                    if (app(SourceCircuitBreaker::class)->isPaused($sourceSlug)) {
                        return null;
                    }
                }
            }

            return [
                'started_at' => now(),
                'claimed_at' => now(),
                'attempts' => $row->attempts + 1,
                'retry_egress_label' => $row->claimed_lane === 'retry'
                    ? (string) data_get(config('javmeta_queues.retry.fetch_profile', []), 'egress_label', 'default')
                    : $row->retry_egress_label,
            ];
        });
    }

    public static function sanitizeErrorMessage(string $message): string
    {
        $message = strip_tags($message);
        $message = preg_replace('/\bBearer\s+\S+/i', 'Bearer [redacted]', $message) ?? $message;
        $message = preg_replace('/\bBasic\s+\S+/i', 'Basic [redacted]', $message) ?? $message;
        $message = preg_replace('/([A-Za-z0-9_-]*(?:password|token|secret|api[ _-]?key))\s*[:=]\s*\S+/i', '$1=[redacted]', $message) ?? $message;
        $message = preg_replace('~(https?://)[^/@\s]+:[^/@\s]+@~i', '$1[redacted]@', $message) ?? $message;
        $message = preg_replace('~(https?://[^\s?#<>"\']+)(?:\?[^\s#<>"\']*)?(?:#[^\s<>"\']*)?~i', '$1', $message) ?? $message;
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        if ($message === '') {
            return 'Crawl failed without an error message.';
        }

        return mb_substr($message, 0, 500);
    }

    public function reclaimStaleClaimed(int $timeoutSeconds): int
    {
        $timeoutSeconds = max(1, $timeoutSeconds);
        $cutoff = now()->subSeconds($timeoutSeconds);
        $unstartedCutoff = now()->subSeconds($this->unstartedClaimTimeoutSeconds($timeoutSeconds));
        $lostRows = [];

        $reclaimed = $this->queueRepository()->forEachStaleClaimed($cutoff, $unstartedCutoff, function (CrawlQueue $row) use (&$lostRows): void {
            if ($row->started_at === null) {
                $this->eventRepository()->record(
                    (string) $row->source_slug,
                    CrawlEvent::KIND_QUEUE_RECLAIMED_WITHOUT_START,
                    ['crawl_queue_id' => (int) $row->id],
                );
                $this->queueRepository()->updateQueue($row, [
                    'status' => $row->claimed_lane === 'retry' ? CrawlQueue::STATUS_RETRY_PENDING : CrawlQueue::STATUS_PENDING,
                    'claimed_at' => null,
                    'started_at' => null,
                    'locked_by' => null,
                    'next_attempt_at' => null,
                    'error_code' => CrawlErrorCode::WorkerLost->value,
                    'last_error' => 'Worker claim expired before the crawl row started.',
                    'last_failed_at' => now(),
                ]);
                $lostRows[] = [$row->source_slug, $row->kind, 'Worker claim expired before the crawl row started.'];
                return;
            }

            $this->markFailed($row, CrawlErrorCode::WorkerLost, 'Worker claim expired before the crawl row was completed.', true, 0);
            $lostRows[] = [$row->source_slug, $row->kind, 'Worker claim expired before the crawl row was completed.'];
        });

        foreach ($lostRows as [$source, $crawlKind, $message]) {
            app(CrawlFailureReporter::class)->report(
                new RuntimeException($message),
                $source,
                $crawlKind,
                CrawlErrorCode::WorkerLost->value,
            );
        }

        return $reclaimed;
    }

    private function unstartedClaimTimeoutSeconds(int $configuredTimeout): int
    {
        $queueDepth = max(
            1,
            (int) config('javmeta_queues.depth_limit', 100),
            (int) config('javmeta_queues.browser_depth_limit', 100),
            (int) config('javmeta_queues.retry.depth_limit', 10),
        );
        $workerTimeout = max(1, (int) config('javmeta_queues.worker_timeout', 180));

        return max($configuredTimeout, $queueDepth * $workerTimeout);
    }
    private function pacingWaitBudgetMicroseconds(): int
    {
        $workerTimeout = max(1, (int) config('javmeta_queues.worker_timeout', 180));
        $fetchTimeout = max(0, (int) config('javmeta_queues.fetch_service_timeout_seconds', 45));

        return max(0, $workerTimeout - $fetchTimeout - 1) * 1_000_000;
    }

    private function retryBackoffSeconds(CrawlQueue $row, CrawlErrorCode $errorCode, ?int $retryAfterSeconds): int
    {
        if (in_array($errorCode, [CrawlErrorCode::Blocked, CrawlErrorCode::Challenge], true)) {
            /** @var list<int> $blockDelays */
            $blockDelays = array_values((array) config('javmeta_sources.defaults.queue.block_delays_seconds', []));
            $blockDelay = $blockDelays[max($row->attempts, 1) - 1] ?? null;
            if (is_numeric($blockDelay) && (int) $blockDelay > 0) {
                return max((int) $blockDelay, max(1, (int) $retryAfterSeconds));
            }
        }
        if ($retryAfterSeconds !== null && $retryAfterSeconds > 0) {
            return $retryAfterSeconds;
        }
        /** @var list<int> $backoff */
        $backoff = array_values((array) config('javmeta_sources.defaults.queue.retry_backoff_seconds', [60, 300, 1800]));
        $index = min(max($row->attempts, 1), max(1, count($backoff))) - 1;

        return max(1, (int) ($backoff[$index] ?? 1800));
    }

    private function retryRoundDelay(int $round): int
    {
        /** @var list<int> $delays */
        $delays = array_values((array) config('javmeta_queues.retry.delays_seconds', [1800, 7200, 21600]));

        return max(1, (int) ($delays[$round - 1] ?? end($delays) ?: 21600));
    }

    private function retryRoundDelaySeconds(int $round, ?int $retryAfterSeconds): int
    {
        return max($this->retryRoundDelay($round), max(0, (int) $retryAfterSeconds));
    }

    public function requeuePermanentFailure(CrawlQueue $row): bool
    {
        $code = CrawlErrorCode::tryFrom((string) $row->error_code);
        if ($row->status !== CrawlQueue::STATUS_FAILED || ! $code instanceof CrawlErrorCode || ! app(CrawlRetryPolicy::class)->isRetryableCode($code)) {
            return false;
        }

        $requiresRetryableFlag = in_array($code, [CrawlErrorCode::Network, CrawlErrorCode::RateLimited], true);
        if ($requiresRetryableFlag && ! filter_var($row->getAttribute('retryable'), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        return $this->queueRepository()->updateRetryableFailure($row, $code->value, $requiresRetryableFlag, [
            'status' => CrawlQueue::STATUS_RETRY_PENDING,
            'attempts' => 0,
            'retry_round' => 1,
            'next_attempt_at' => now()->addSeconds($this->retryRoundDelay(1)),
            'claimed_at' => null,
            'started_at' => null,
            'locked_by' => null,
        ]);
    }

    public function requeuePermanentFailuresForSource(string $source): int
    {
        $rows = $this->queueRepository()->failedForSource($source);
        $count = 0;
        foreach ($rows as $row) {
            $count += $this->requeuePermanentFailure($row) ? 1 : 0;
        }

        return $count;
    }

    public function importHistoricalFailures(bool $dryRun, ?string $source = null, ?int $sliceSize = null): int
    {
        $delay = max(1, $this->retryRoundDelay(1));
        $cutoff = now()->subSeconds($delay);
        $slice = max(1, $sliceSize ?? (int) config('javmeta_queues.retry.import_slice_size', 100));
        return $this->queueRepository()->importHistoricalFailures(
            $dryRun,
            $source,
            $cutoff,
            $slice,
            app(CrawlRetryPolicy::class)->retryableCodeValues(),
        );
    }

    private function nextSourceAttemptAt(?Source $source): Carbon
    {
        if (! $source instanceof Source) {
            return now();
        }

        $current = (float) ($source->gap_seconds_current ?? 0);
        if (! $this->sourceGapPolicy->isOverridden((string) $source->getKey())) {
            return now()->addSeconds((int) $current);
        }

        $milliseconds = (int) round($this->sourceGapPolicy->adaptiveGap($source) * 1000);

        return now()->addMilliseconds($milliseconds);
    }

    private function queueRepository(): CrawlQueueRepository
    {
        return $this->queueRepository ?? app(CrawlQueueRepository::class);
    }

    private function sourceRepository(): SourceRepository
    {
        return $this->sourceRepository ?? app(SourceRepository::class);
    }

    private function eventRepository(): CrawlEventRepository
    {
        return $this->eventRepository ?? app(CrawlEventRepository::class);
    }

    public const MAX_SSRF_REQUEUE_BATCH_SIZE = 500;

    /** @return array{eligible: int, requeued: int} */
    public function requeueSsrfBlockedFailures(bool $apply, ?string $source, int $batchSize): array
    {
        if ($batchSize < 1 || $batchSize > self::MAX_SSRF_REQUEUE_BATCH_SIZE) {
            throw new RuntimeException('The SSRF requeue batch size is outside the supported range.');
        }

        return $this->queueRepository()->requeueSsrfBlockedFailures(
            $apply,
            $source,
            $batchSize,
            now()->addSeconds($this->retryRoundDelay(1)),
        );
    }
}
