<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Enums\CrawlErrorCode;
use App\Models\CrawlEvent;
use App\Models\CrawlQueue;
use App\Models\Source;
use App\Observability\Sentry\CrawlFailureReporter;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CrawlQueueService
{
    private readonly SourceGapPolicy $sourceGapPolicy;
    private readonly SourceRequestPacer $sourceRequestPacer;

    public function __construct(?SourceGapPolicy $sourceGapPolicy = null, ?SourceRequestPacer $sourceRequestPacer = null)
    {
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
        $collectDiagnostics = $diagnostics !== null;
        $diagnostics = ['rows_examined' => 0, 'stop_reason' => null];

        return DB::transaction(function () use ($workerId, $kind, $excludeSources, $lane, $collectDiagnostics, &$diagnostics): ?CrawlQueue {
            // Serialize claims across feeder processes so the global browser cap cannot be oversubscribed.
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(?)', [281]);
            }
            $query = CrawlQueue::query()
                ->where('status', $lane === 'retry' ? CrawlQueue::STATUS_RETRY_PENDING : CrawlQueue::STATUS_PENDING)
                ->where(function ($q): void {
                    $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
                })
                ->orderBy('id');

            if ($kind !== null) {
                $query->where('kind', $kind);
            }

            if ($lane === 'retry') {
                // Retry rows use their own queue status and never enter a direct/browser claim.
            } elseif ($lane !== null && config('javmeta_queues.lanes_enabled', true)) {
                $browser = app(CrawlLaneService::class)->browserSources();
                if ($lane === 'browser') {
                    $query->whereIn('source_slug', $browser);
                } else {
                    $query->whereNotIn('source_slug', $browser);
                }
            }

            // Rows of a disabled source stay queued until it is enabled again.
            $query->whereNotIn('source_slug', Source::query()->where('enabled', false)->pluck('slug')->all());

            // A site that is waiting out a block delay gets no new claims until the delay is over.
            $query->whereNotIn('source_slug', $this->sourcesHeldByBlockDelay());

            // Fairness: a source waits until its claimed work across every crawl kind has room.
            $sourcesAtInflightLimit = $this->sourcesAtInflightLimit($lane);
            $eligibleQuery = clone $query;
            if ($excludeSources !== []) {
                $eligibleQuery->whereNotIn('source_slug', $excludeSources);
            }
            $eligibleQuery->whereNotIn('source_slug', $sourcesAtInflightLimit);

            if ($collectDiagnostics) {
                $row = $this->claimWithDiagnostics($query, $eligibleQuery, $excludeSources, $sourcesAtInflightLimit);
                if (! $row instanceof CrawlQueue || $row->getAttribute('id') === null) {
                    if ($row instanceof CrawlQueue) {
                        $hasBudgetCandidate = $row->getAttribute('budget_candidate') !== null;
                        $hasInflightCandidate = $row->getAttribute('inflight_candidate') !== null;
                        $diagnostics['rows_examined'] = $hasBudgetCandidate || $hasInflightCandidate ? 1 : 0;
                        $diagnostics['stop_reason'] = FeedPoolStopReason::forClaim(
                            $hasBudgetCandidate,
                            $hasInflightCandidate,
                        );
                    }

                    return null;
                }
            } else {
                if (DB::getDriverName() === 'pgsql') {
                    $eligibleQuery->lockForUpdate();
                }

                $row = $eligibleQuery->first();
                if (! $row instanceof CrawlQueue) {
                    return null;
                }
            }

            $diagnostics['rows_examined'] = 1;

            $row->forceFill([
                'status' => CrawlQueue::STATUS_CLAIMED,
                'claimed_at' => now(),
                'claimed_lane' => $lane ?? app(CrawlLaneService::class)->lane((string) $row->source_slug),
                'started_at' => null,
                'locked_by' => $workerId,
            ])->save();

            return $row->refresh();
        });
    }

    /**
     * Keep candidate selection and gate classification in one database round trip.
     * The sentinel branch is returned only when the unchanged eligible-row query is empty.
     *
     * @param  Builder<CrawlQueue>  $baseQuery
     * @param  Builder<CrawlQueue>  $eligibleQuery
     * @param  list<string>  $excludeSources
     * @param  list<string>  $sourcesAtInflightLimit
     */
    private function claimWithDiagnostics(Builder $baseQuery, Builder $eligibleQuery, array $excludeSources, array $sourcesAtInflightLimit): ?CrawlQueue
    {
        $candidateQuery = clone $eligibleQuery;
        $candidateQuery->select(['id', 'source_slug', 'kind'])->limit(1);
        if (DB::getDriverName() === 'pgsql') {
            $candidateQuery->lockForUpdate();
        }

        $budgetCandidates = clone $baseQuery;
        if ($excludeSources === []) {
            $budgetCandidates->whereRaw('1 = 0');
        } else {
            $budgetCandidates->whereIn('source_slug', $excludeSources);
        }
        $budgetCandidates->selectRaw('1')->limit(1);

        $inflightCandidates = clone $baseQuery;
        if ($sourcesAtInflightLimit === []) {
            $inflightCandidates->whereRaw('1 = 0');
        } else {
            $inflightCandidates->whereIn('source_slug', $sourcesAtInflightLimit);
        }
        $inflightCandidates->selectRaw('1')->limit(1);

        $budgetCandidates->whereNull('candidate.id');
        $inflightCandidates->whereNull('candidate.id');
        $singleton = DB::query()->selectRaw('1 AS singleton');

        $row = CrawlQueue::query()
            ->fromSub($singleton, 'feed_singleton')
            ->leftJoinSub($candidateQuery, 'candidate', static function (JoinClause $join): void {
                $join->on(DB::raw('1'), '=', DB::raw('1'));
            })
            ->select('candidate.id', 'candidate.source_slug', 'candidate.kind')
            ->selectSub($budgetCandidates, 'budget_candidate')
            ->selectSub($inflightCandidates, 'inflight_candidate')
            ->first();

        return $row instanceof CrawlQueue ? $row : null;
    }

    /**
     * Rows that are claimed and not finished yet, per source (all kinds): work that is already waiting in the worker queues.
     *
     * @return array<string, int>
     */
    public function claimedWaitingBySource(): array
    {
        $counts = [];
        foreach (CrawlQueue::query()
            ->where('status', CrawlQueue::STATUS_CLAIMED)
            ->whereNull('started_at')
            ->select('source_slug', DB::raw('COUNT(*) AS total'))
            ->groupBy('source_slug')
            ->get() as $row) {
            $counts[(string) $row->source_slug] = (int) $row->getAttribute('total');
        }

        return $counts;
    }

    /**
     * Pending claims and recent request activity used to preserve the source's pacing window across feeder runs.
     * An active claim counts once until its current request attempt has been recorded.
     *
     * @return array<string, array{waiting: int, started: int, latest_started_at: ?CarbonImmutable}>
     */
    public function pacingActivityBySource(DateTimeInterface $since, ?string $lane = null): array
    {
        $activity = [];
        $claims = CrawlQueue::query()
            ->where('status', CrawlQueue::STATUS_CLAIMED)
            ->when($lane === 'retry', static fn(Builder $query) => $query->where('claimed_lane', 'retry'))
            ->when($lane !== null && $lane !== 'retry', static fn(Builder $query) => $query->where(static function (Builder $nested): void {
                $nested->whereNull('claimed_lane')->orWhere('claimed_lane', '!=', 'retry');
            }))
            ->select('source_slug')
            ->selectRaw('SUM(CASE WHEN started_at IS NULL THEN 1 ELSE 0 END) AS waiting')
            ->selectRaw(
                'SUM(CASE WHEN started_at >= ? AND NOT EXISTS ('
                    . 'SELECT 1 FROM crawl_fetch_attempts '
                    . 'WHERE crawl_fetch_attempts.crawl_queue_id = crawl_queue.id '
                    . 'AND crawl_fetch_attempts.started_at >= crawl_queue.started_at '
                    . 'AND crawl_fetch_attempts.outcome <> ?'
                . ') THEN 1 ELSE 0 END) AS active',
                [$since, 'skipped'],
            )
            ->selectRaw('MAX(started_at) AS latest_started_at')
            ->groupBy('source_slug')
            ->get();

        foreach ($claims as $row) {
            $slug = (string) $row->getAttribute('source_slug');
            $latestStartedAt = $this->recentTimestamp($row->getAttribute('latest_started_at'), $since);
            $activity[$slug] = [
                'waiting' => (int) $row->getAttribute('waiting'),
                'started' => (int) $row->getAttribute('active'),
                'latest_started_at' => $latestStartedAt,
            ];
        }

        $attempts = DB::table('crawl_fetch_attempts')
            ->leftJoin('crawl_queue', 'crawl_queue.id', '=', 'crawl_fetch_attempts.crawl_queue_id')
            ->when($lane === 'retry', static fn($query) => $query->where('crawl_queue.claimed_lane', 'retry'))
            ->when($lane !== null && $lane !== 'retry', static fn($query) => $query->where(static function ($nested): void {
                $nested->whereNull('crawl_queue.id')->orWhereNull('crawl_queue.claimed_lane')->orWhere('crawl_queue.claimed_lane', '!=', 'retry');
            }))
            ->where('crawl_fetch_attempts.created_at', '>=', $since)
            ->where('crawl_fetch_attempts.started_at', '>=', $since)
            ->where('crawl_fetch_attempts.outcome', '!=', 'skipped')
            ->select('crawl_fetch_attempts.source_slug')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('MAX(crawl_fetch_attempts.started_at) AS latest_started_at')
            ->groupBy('crawl_fetch_attempts.source_slug')
            ->get();

        foreach ($attempts as $row) {
            $slug = (string) $row->source_slug;
            $activity[$slug] ??= ['waiting' => 0, 'started' => 0, 'latest_started_at' => null];
            $activity[$slug]['started'] += (int) $row->total;
            $latestStartedAt = $this->recentTimestamp($row->latest_started_at, $since);
            if ($latestStartedAt !== null && ($activity[$slug]['latest_started_at'] === null || $latestStartedAt->greaterThan($activity[$slug]['latest_started_at']))) {
                $activity[$slug]['latest_started_at'] = $latestStartedAt;
            }
        }

        return $activity;
    }

    /**
     * Sources that already hold `javmeta_queues.per_source_inflight` claimed rows across crawl kinds.
     *
     * @return list<string>
     */
    private function sourcesAtInflightLimit(?string $lane = null): array
    {
        $limit = max(1, (int) config($lane === 'retry' ? 'javmeta_queues.retry.per_source_inflight' : 'javmeta_queues.per_source_inflight', 4));

        $query = CrawlQueue::query()
            ->where('status', CrawlQueue::STATUS_CLAIMED)
            ->when($lane === 'retry', static fn(Builder $query) => $query->where('claimed_lane', 'retry'))
            ->when($lane !== null && $lane !== 'retry', static fn(Builder $query) => $query->where(static function (Builder $nested): void {
                $nested->whereNull('claimed_lane')->orWhere('claimed_lane', '!=', 'retry');
            }))
            ->select('source_slug')
            ->groupBy('source_slug')
            ->havingRaw('COUNT(*) >= ?', [$limit]);

        $atLimit = array_values(array_map('strval', $query->pluck('source_slug')->all()));

        // The browser services (FlareSolverr, Playwright on the Dev VM) serve every Cloudflare source at once: when the rows
        // claimed by those sources reach the shared cap, none of them is claimed until some finish.
        if ($lane === 'retry') {
            return $atLimit;
        }

        $cap = (int) config('javmeta_queues.browser_inflight', 0);
        $browserSources = app(CrawlLaneService::class)->browserSources();
        if ($cap > 0 && $browserSources !== []) {
            $claimed = CrawlQueue::query()->where('status', CrawlQueue::STATUS_CLAIMED)
                ->where(static function ($query) use ($browserSources): void {
                    // Retain browser claims after reclassification, and include escalated/legacy claims.
                    $query->where(static function ($laneQuery): void {
                        $laneQuery->whereNull('claimed_lane')->orWhere('claimed_lane', '!=', 'retry');
                    })->where(static function ($browserQuery) use ($browserSources): void {
                        $browserQuery->where('claimed_lane', 'browser')->orWhereIn('source_slug', $browserSources);
                    });
                })->count();
            if ($claimed >= $cap) {
                $atLimit = array_values(array_unique([...$atLimit, ...$browserSources]));
            }
        }

        return $atLimit;
    }

    private function recentTimestamp(mixed $value, DateTimeInterface $since): ?CarbonImmutable
    {
        if (! is_string($value) && ! $value instanceof DateTimeInterface) {
            return null;
        }

        $timestamp = CarbonImmutable::parse($value);

        return $timestamp->greaterThanOrEqualTo($since) ? $timestamp : null;
    }

    public function enqueue(string $url, string $sourceSlug, string $kind, ?int $maxAttempts = null): CrawlQueue
    {
        $source = Source::query()->find($sourceSlug);
        $configuredMaxAttempts = $maxAttempts ?? (int) config("javmeta_sources.sources.{$sourceSlug}.max_attempts", config('javmeta_sources.defaults.max_attempts', 3));

        return CrawlQueue::query()->firstOrCreate(
            ['source_slug' => $sourceSlug, 'url' => $url],
            [
                'kind' => $kind,
                'status' => CrawlQueue::STATUS_PENDING,
                'attempts' => 0,
                'max_attempts' => $configuredMaxAttempts,
                'next_attempt_at' => $this->nextSourceAttemptAt($source),
                'claimed_at' => null,
                'started_at' => null,
                'locked_by' => null,
                'last_error' => null,
                'error_code' => null,
                'last_failed_at' => null,
            ],
        );
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

        $rows = CrawlQueue::query()->where('source_slug', $sourceSlug)->where('kind', $kind);
        if ((clone $rows)->whereIn('status', [CrawlQueue::STATUS_PENDING, CrawlQueue::STATUS_CLAIMED])->exists()
            || ! (clone $rows)->exists()) {
            return false;
        }

        $pause = max(0, (int) config('javmeta_sources.defaults.loop.pause_seconds', 300));

        DB::transaction(function () use ($rows, $sourceSlug, $kind, $seedUrls, $pause): void {
            (clone $rows)->whereNotIn('url', $seedUrls)->where('status', CrawlQueue::STATUS_DONE)->delete();
            (clone $rows)->whereIn('url', $seedUrls)->update([
                'status' => CrawlQueue::STATUS_PENDING,
                'attempts' => 0,
                'next_attempt_at' => now()->addSeconds($pause),
                'claimed_at' => null,
                'started_at' => null,
                'locked_by' => null,
                'last_error' => null,
                'error_code' => null,
                'last_failed_at' => null,
            ]);

            foreach ($seedUrls as $url) {
                $this->enqueue($url, $sourceSlug, $kind);
            }
        });

        return true;
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

        return DB::transaction(function () use ($sourceSlug, $kind, $seedUrls, $interval): int {
            $rows = CrawlQueue::query()
                ->where('source_slug', $sourceSlug)
                ->where('kind', $kind)
                ->whereIn('url', $seedUrls)
                ->whereIn('status', [CrawlQueue::STATUS_DONE, CrawlQueue::STATUS_FAILED])
                ->lockForUpdate()
                ->get();

            if ($rows->isEmpty()) {
                return 0;
            }

            $source = Source::query()->find($sourceSlug);
            $nextAttemptAt = $this->nextSourceAttemptAt($source);
            $refreshed = 0;

            foreach ($rows as $row) {
                $dueAt = $row->next_attempt_at === null ? null : Carbon::parse($row->next_attempt_at);
                if ($dueAt === null && $row->last_failed_at !== null) {
                    $dueAt = Carbon::parse($row->last_failed_at)->addMinutes($interval);
                }

                if ($dueAt !== null && $dueAt->gt(now())) {
                    continue;
                }

                $row->forceFill([
                    'status' => CrawlQueue::STATUS_PENDING,
                    'attempts' => 0,
                    'next_attempt_at' => $nextAttemptAt,
                    'claimed_at' => null,
                    'locked_by' => null,
                    'last_error' => null,
                    'error_code' => null,
                    'last_failed_at' => null,
                ])->save();
                $refreshed++;
            }

            return $refreshed;
        });
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
            $nextAttemptAt = now()->addSeconds($this->retryRoundDelay($nextRound));
        }

        if (! $isRetryLane && $isRetryable && ! $attemptsRemain) {
            $nextStatus = CrawlQueue::STATUS_RETRY_PENDING;
            $nextRound = 1;
            $nextAttemptAt = now()->addSeconds($this->retryRoundDelay($nextRound));
        }


        $row->forceFill([
            'status' => $nextStatus,
            'retry_round' => $nextRound,
            'attempts' => $nextRound !== $round ? 0 : $row->attempts,
            'claimed_at' => null,
            'started_at' => null,
            'locked_by' => null,
            'next_attempt_at' => $nextAttemptAt,
            'error_code' => $errorCode->value,
            'last_error' => $safeMessage,
            'last_failed_at' => now(),
        ])->save();

        if ($isRetryLane && $nextStatus === CrawlQueue::STATUS_FAILED) {
            app(CrawlRetryMetrics::class)->increment((string) $row->source_slug, max(1, $round), 'failed_permanent', (string) ($row->retry_egress_label ?? 'default'));
        }
    }

    public function startClaimedRow(int $id, string $expectedKind, ?string $claimToken): ?CrawlQueue
    {
        return DB::transaction(function () use ($id, $expectedKind, $claimToken): ?CrawlQueue {
            $query = CrawlQueue::query()
                ->whereKey($id)
                ->where('status', CrawlQueue::STATUS_CLAIMED)
                ->where('kind', $expectedKind);

            if ($claimToken !== null) {
                $query->where('locked_by', $claimToken);
            }

            if (DB::getDriverName() === 'pgsql') {
                $query->lockForUpdate();
            }

            $row = $query->first();
            if (! $row instanceof CrawlQueue || $row->started_at !== null) {
                return null;
            }

            $sourceSlug = (string) $row->source_slug;
            if ($this->sourceGapPolicy->isOverridden($sourceSlug)) {
                $source = Source::query()->find($sourceSlug);
                if ($source instanceof Source) {
                    $retryMultiplier = $row->claimed_lane === 'retry'
                        ? max(1.0, (float) config('javmeta_queues.retry.pacing_gap_multiplier', 2.0))
                        : 1.0;
                    $wait = $this->sourceRequestPacer->reserveStart(
                        $sourceSlug,
                        $this->sourceGapPolicy->adaptiveGap($source) * $retryMultiplier,
                        (string) ($row->claimed_lane ?? 'direct'),
                    );
                    $this->sourceRequestPacer->wait($wait);
                }
            }

            $row->forceFill([
                'started_at' => now(),
                'claimed_at' => now(),
                'attempts' => $row->attempts + 1,
                'retry_egress_label' => $row->claimed_lane === 'retry'
                    ? (string) data_get(config('javmeta_queues.retry.fetch_profile', []), 'egress_label', 'default')
                    : $row->retry_egress_label,
            ])->save();

            return $row->refresh();
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
        $cutoff = now()->subSeconds($timeoutSeconds);
        $lostRows = [];

        $reclaimed = DB::transaction(function () use ($cutoff, &$lostRows): int {
            $query = CrawlQueue::query()
                ->where('status', CrawlQueue::STATUS_CLAIMED)
                ->where('claimed_at', '<', $cutoff)
                ->orderBy('id');

            if (DB::getDriverName() === 'pgsql') {
                $query->lockForUpdate();
            }

            $reclaimed = 0;
            $query->chunkById(100, function (Collection $staleRows) use (&$reclaimed, &$lostRows): void {
                foreach ($staleRows as $row) {
                    if ($row->started_at === null) {
                        CrawlEvent::query()->create([
                            'source_slug' => $row->source_slug,
                            'kind' => CrawlEvent::KIND_QUEUE_RECLAIMED_WITHOUT_START,
                            'url' => null,
                            'detail' => ['crawl_queue_id' => $row->id],
                            'created_at' => now(),
                        ]);

                        $this->returnUnstartedClaimToPending($row);
                        $lostRows[] = [
                            $row->source_slug,
                            $row->kind,
                            'Worker claim expired before the crawl row started.',
                        ];
                    } else {
                        $this->markFailed(
                            $row,
                            CrawlErrorCode::WorkerLost,
                            'Worker claim expired before the crawl row was completed.',
                            true,
                            0,
                        );
                        $lostRows[] = [
                            $row->source_slug,
                            $row->kind,
                            'Worker claim expired before the crawl row was completed.',
                        ];
                    }

                    $reclaimed++;
                }
            });

            return $reclaimed;
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

    private function returnUnstartedClaimToPending(CrawlQueue $row): void
    {
        $row->forceFill([
            'status' => CrawlQueue::STATUS_PENDING,
            'claimed_at' => null,
            'started_at' => null,
            'locked_by' => null,
            'next_attempt_at' => null,
            'error_code' => CrawlErrorCode::WorkerLost->value,
            'last_error' => 'Worker claim expired before the crawl row started.',
            'last_failed_at' => now(),
        ])->save();
    }

    /** @return list<string> */
    private function sourcesHeldByBlockDelay(): array
    {
        return CrawlQueue::query()
            ->where('status', CrawlQueue::STATUS_PENDING)
            ->whereIn('error_code', [CrawlErrorCode::Blocked->value, CrawlErrorCode::Challenge->value])
            ->where('next_attempt_at', '>', now())
            ->distinct()
            ->pluck('source_slug')
            ->all();
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

    public function requeuePermanentFailure(CrawlQueue $row): bool
    {
        $code = CrawlErrorCode::tryFrom((string) $row->error_code);
        if ($row->status !== CrawlQueue::STATUS_FAILED || ! $code instanceof CrawlErrorCode || ! app(CrawlRetryPolicy::class)->isRetryableCode($code)) {
            return false;
        }

        $row->forceFill([
            'status' => CrawlQueue::STATUS_RETRY_PENDING,
            'attempts' => 0,
            'retry_round' => 1,
            'next_attempt_at' => now()->addSeconds($this->retryRoundDelay(1)),
            'claimed_at' => null,
            'started_at' => null,
            'locked_by' => null,
        ])->save();

        return true;
    }

    public function requeuePermanentFailuresForSource(string $source): int
    {
        $rows = CrawlQueue::query()->where('source_slug', $source)->where('status', CrawlQueue::STATUS_FAILED)
            ->orderBy('id')->get();
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
        $eligible = CrawlQueue::query()->where('status', CrawlQueue::STATUS_FAILED)
            ->whereNotNull('last_failed_at')->where('last_failed_at', '<=', $cutoff);
        if ($source !== null) {
            $eligible->where('source_slug', $source);
        }

        $retryableCodes = app(CrawlRetryPolicy::class)->retryableCodeValues();
        $eligible->whereIn('error_code', $retryableCodes);
        if ($dryRun) {
            return $eligible->count();
        }

        $imported = 0;
        $eligible->chunkById($slice, function (Collection $rows) use (&$imported, $cutoff, $retryableCodes): void {
            $ids = $rows->modelKeys();
            $imported += CrawlQueue::query()->whereIn('id', $ids)
                ->where('status', CrawlQueue::STATUS_FAILED)
                ->where('last_failed_at', '<=', $cutoff)
                ->whereIn('error_code', $retryableCodes)
                ->update([
                    'status' => CrawlQueue::STATUS_RETRY_PENDING,
                    'attempts' => 0,
                    'retry_round' => 1,
                    'next_attempt_at' => now(),
                ]);
        });

        return $imported;
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
}
