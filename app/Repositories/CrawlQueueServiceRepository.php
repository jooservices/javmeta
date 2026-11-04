<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\CrawlErrorCode;
use App\Models\CrawlEvent;
use App\Models\CrawlQueue;
use App\Services\Crawl\CrawlLaneService;
use App\Services\Crawl\FeedPoolStopReason;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use JOOservices\LaravelRepository\Contracts\RepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasCrud;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;

/** Persistence operations for crawl queue services. */
final class CrawlQueueServiceRepository extends EloquentRepository implements RepositoryInterface
{
    use HasCrud;
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;

    public function __construct(CrawlQueue $model)
    {
        parent::__construct($model);
    }

    /**
     * @param list<string> $disabledSources
     * @param list<string> $excludedSources
     * @param array{rows_examined?: int, stop_reason?: ?string}|null $diagnostics
     * @param-out array{rows_examined: int, stop_reason: ?string} $diagnostics
     */
    public function claimNext(
        string $workerId,
        ?string $kind,
        array $excludedSources,
        ?string $lane,
        ?array &$diagnostics,
        array $disabledSources,
        CrawlLaneService $lanes,
    ): ?CrawlQueue {
        $collectDiagnostics = $diagnostics !== null;
        $diagnostics = ['rows_examined' => 0, 'stop_reason' => null];

        return DB::transaction(function () use ($workerId, $kind, $excludedSources, $lane, $collectDiagnostics, &$diagnostics, $disabledSources, $lanes): ?CrawlQueue {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(?)', [281]);
            }

            /** @var Builder<CrawlQueue> $query */
            $query = $this->newQuery()
                ->where('status', $lane === 'retry' ? CrawlQueue::STATUS_RETRY_PENDING : CrawlQueue::STATUS_PENDING)
                ->where(static function (Builder $q): void {
                    $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
                })
                ->orderBy('id');

            if ($kind !== null) {
                $query->where('kind', $kind);
            }

            if ($lane !== 'retry' && $lane !== null && config('javmeta_queues.lanes_enabled', true)) {
                $browser = $lanes->browserSources();
                if ($lane === 'browser') {
                    $query->whereIn('source_slug', $browser);
                } else {
                    $query->whereNotIn('source_slug', $browser);
                }
            }

            $query->whereNotIn('source_slug', $disabledSources);
            $query->whereNotIn('source_slug', $this->sourcesHeldByBlockDelay());
            $sourcesAtInflightLimit = $this->sourcesAtInflightLimit($lane, $lanes);
            $eligibleQuery = clone $query;
            if ($excludedSources !== []) {
                $eligibleQuery->whereNotIn('source_slug', $excludedSources);
            }
            $eligibleQuery->whereNotIn('source_slug', $sourcesAtInflightLimit);

            if ($collectDiagnostics) {
                $row = $this->claimWithDiagnostics($query, $eligibleQuery, $excludedSources, $sourcesAtInflightLimit);
                if (! $row instanceof CrawlQueue || $row->getAttribute('id') === null) {
                    if ($row instanceof CrawlQueue) {
                        $hasBudgetCandidate = $row->getAttribute('budget_candidate') !== null;
                        $hasInflightCandidate = $row->getAttribute('inflight_candidate') !== null;
                        $diagnostics['rows_examined'] = $hasBudgetCandidate || $hasInflightCandidate ? 1 : 0;
                        $diagnostics['stop_reason'] = $this->stopReason($hasBudgetCandidate, $hasInflightCandidate);
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
                'claimed_lane' => $lane ?? $lanes->lane((string) $row->source_slug),
                'started_at' => null,
                'locked_by' => $workerId,
            ])->save();

            return $row->refresh();
        });
    }

    /** @param Builder<CrawlQueue> $baseQuery @param Builder<CrawlQueue> $eligibleQuery @param list<string> $excludedSources @param list<string> $sourcesAtInflightLimit */
    private function claimWithDiagnostics(Builder $baseQuery, Builder $eligibleQuery, array $excludedSources, array $sourcesAtInflightLimit): ?CrawlQueue
    {
        $candidateQuery = clone $eligibleQuery;
        $candidateQuery->select(['id', 'source_slug', 'kind'])->limit(1);
        if (DB::getDriverName() === 'pgsql') {
            $candidateQuery->lockForUpdate();
        }

        $budgetCandidates = clone $baseQuery;
        if ($excludedSources === []) {
            $budgetCandidates->whereRaw('1 = 0');
        } else {
            $budgetCandidates->whereIn('source_slug', $excludedSources);
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

        $row = $this->newQuery()
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

    private function stopReason(bool $hasBudgetCandidate, bool $hasInflightCandidate): string
    {
        return FeedPoolStopReason::forClaim($hasBudgetCandidate, $hasInflightCandidate);
    }

    /** @return array<string, int> */
    public function claimedWaitingBySource(): array
    {
        $counts = [];
        foreach ($this->newQuery()
            ->where('status', CrawlQueue::STATUS_CLAIMED)
            ->whereNull('started_at')
            ->select('source_slug', DB::raw('COUNT(*) AS total'))
            ->groupBy('source_slug')
            ->get() as $row) {
            $counts[(string) $row->getAttribute('source_slug')] = (int) $row->getAttribute('total');
        }

        return $counts;
    }

    /** @return array<string, array{waiting: int, started: int, latest_started_at: ?CarbonImmutable}> */
    public function pacingActivityBySource(DateTimeInterface $since, ?string $lane = null): array
    {
        $activity = [];
        $claims = $this->newQuery()
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
            $activity[$slug] = [
                'waiting' => (int) $row->getAttribute('waiting'),
                'started' => (int) $row->getAttribute('active'),
                'latest_started_at' => $this->recentTimestamp($row->getAttribute('latest_started_at'), $since),
            ];
        }

        $attempts = DB::table('crawl_fetch_attempts')
            ->when($lane === 'retry', static fn($query) => $query->where('crawl_fetch_attempts.lane', 'retry'))
            ->when($lane !== null && $lane !== 'retry', static fn($query) => $query->where(static function ($nested): void {
                $nested->whereNull('crawl_fetch_attempts.lane')->orWhere('crawl_fetch_attempts.lane', '!=', 'retry');
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
            $latest = $this->recentTimestamp($row->latest_started_at, $since);
            if ($latest !== null && ($activity[$slug]['latest_started_at'] === null || $latest->greaterThan($activity[$slug]['latest_started_at']))) {
                $activity[$slug]['latest_started_at'] = $latest;
            }
        }

        return $activity;
    }

    public function enqueue(string $url, string $sourceSlug, string $kind, int $maxAttempts, DateTimeInterface $nextAttemptAt): CrawlQueue
    {
        /** @var CrawlQueue $row */
        $row = $this->newQuery()->firstOrCreate(
            ['source_slug' => $sourceSlug, 'url' => $url],
            [
                'kind' => $kind,
                'status' => CrawlQueue::STATUS_PENDING,
                'attempts' => 0,
                'max_attempts' => $maxAttempts,
                'next_attempt_at' => $nextAttemptAt,
                'claimed_at' => null,
                'started_at' => null,
                'locked_by' => null,
                'last_error' => null,
                'error_code' => null,
                'last_failed_at' => null,
            ],
        );

        return $row;
    }

    /** @param list<string> $seedUrls @param callable(string): array<string, mixed> $seedDefaults */
    public function restartListingLoop(string $sourceSlug, string $kind, array $seedUrls, int $pauseSeconds, callable $seedDefaults): bool
    {
        $rows = $this->newQuery()->where('source_slug', $sourceSlug)->where('kind', $kind);
        if ((clone $rows)->whereIn('status', [CrawlQueue::STATUS_PENDING, CrawlQueue::STATUS_CLAIMED, CrawlQueue::STATUS_RETRY_PENDING])->exists()
            || ! (clone $rows)->exists()) {
            return false;
        }

        DB::transaction(function () use ($rows, $sourceSlug, $seedUrls, $pauseSeconds, $seedDefaults): void {
            (clone $rows)->whereNotIn('url', $seedUrls)->where('status', CrawlQueue::STATUS_DONE)->delete();
            (clone $rows)->whereIn('url', $seedUrls)
                ->whereNotIn('status', [CrawlQueue::STATUS_PENDING, CrawlQueue::STATUS_CLAIMED, CrawlQueue::STATUS_RETRY_PENDING])
                ->update([
                    'status' => CrawlQueue::STATUS_PENDING,
                    'attempts' => 0,
                    'next_attempt_at' => now()->addSeconds($pauseSeconds),
                    'claimed_at' => null,
                    'started_at' => null,
                    'locked_by' => null,
                    'last_error' => null,
                    'error_code' => null,
                    'last_failed_at' => null,
                ]);

            foreach ($seedUrls as $url) {
                $this->newQuery()->firstOrCreate(['source_slug' => $sourceSlug, 'url' => $url], $seedDefaults($url));
            }
        });

        return true;
    }

    /** @param list<string> $seedUrls */
    public function refreshListingSeeds(string $sourceSlug, string $kind, array $seedUrls, int $intervalMinutes, callable $nextAttemptAt): int
    {
        return DB::transaction(function () use ($sourceSlug, $kind, $seedUrls, $intervalMinutes, $nextAttemptAt): int {
            /** @var Collection<int, CrawlQueue> $rows */
            $rows = $this->newQuery()
                ->where('source_slug', $sourceSlug)
                ->where('kind', $kind)
                ->whereIn('url', $seedUrls)
                ->whereIn('status', [CrawlQueue::STATUS_DONE, CrawlQueue::STATUS_FAILED])
                ->lockForUpdate()
                ->get();
            if ($rows->isEmpty()) {
                return 0;
            }
            $nextAttemptAt = $nextAttemptAt();
            $refreshed = 0;

            foreach ($rows as $row) {
                $nextAttempt = $row->getAttribute('next_attempt_at');
                $lastFailed = $row->getAttribute('last_failed_at');
                $dueAt = $nextAttempt === null ? null : Carbon::parse($nextAttempt);
                if ($dueAt === null && $lastFailed !== null) {
                    $dueAt = Carbon::parse($lastFailed)->addMinutes($intervalMinutes);
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

    public function claimedForStart(int $id, string $expectedKind, ?string $claimToken): ?CrawlQueue
    {
        $query = $this->newQuery()->whereKey($id)->where('status', CrawlQueue::STATUS_CLAIMED)->where('kind', $expectedKind);
        if ($claimToken !== null) {
            $query->where('locked_by', $claimToken);
        }
        if (DB::getDriverName() === 'pgsql') {
            $query->lockForUpdate();
        }
        $row = $query->first();

        return $row instanceof CrawlQueue ? $row : null;
    }

    /** @param callable(CrawlQueue): (array<string, mixed>|null) $prepare */
    public function startClaimedRow(int $id, string $expectedKind, ?string $claimToken, callable $prepare): ?CrawlQueue
    {
        return DB::transaction(function () use ($id, $expectedKind, $claimToken, $prepare): ?CrawlQueue {
            $row = $this->claimedForStart($id, $expectedKind, $claimToken);
            if (! $row instanceof CrawlQueue || $row->started_at !== null) {
                return null;
            }

            $attributes = $prepare($row);
            if ($attributes === null) {
                $this->updateQueue($row, [
                    'status' => $row->claimed_lane === 'retry' ? CrawlQueue::STATUS_RETRY_PENDING : CrawlQueue::STATUS_PENDING,
                    'claimed_at' => null,
                    'started_at' => null,
                    'locked_by' => null,
                ]);

                return null;
            }

            $this->updateQueue($row, $attributes);

            return $row->refresh();
        });
    }

    /** @param array<string, mixed> $attributes */
    public function updateQueue(CrawlQueue $row, array $attributes): void
    {
        $row->forceFill($attributes)->save();
    }

    /** @param callable(CrawlQueue): void $handle */
    public function forEachStaleClaimed(DateTimeInterface $cutoff, callable $handle): int
    {
        return DB::transaction(function () use ($cutoff, $handle): int {
            /** @var Builder<CrawlQueue> $query */
            $query = $this->newQuery()->where('status', CrawlQueue::STATUS_CLAIMED)->where('claimed_at', '<', $cutoff)->orderBy('id');
            if (DB::getDriverName() === 'pgsql') {
                $query->lockForUpdate();
            }
            $total = 0;
            $query->chunkById(100, function (Collection $rows) use ($handle, &$total): void {
                foreach ($rows as $row) {
                    $handle($row);
                    $total++;
                }
            });

            return $total;
        });
    }

    public function recordReclaimedEvent(string $sourceSlug, int $queueId): void
    {
        CrawlEvent::query()->create([
            'source_slug' => $sourceSlug,
            'kind' => CrawlEvent::KIND_QUEUE_RECLAIMED_WITHOUT_START,
            'url' => null,
            'detail' => ['crawl_queue_id' => $queueId],
            'created_at' => now(),
        ]);
    }

    public function sourcesHeldByBlockDelay(): array
    {
        return $this->newQuery()
            ->where('status', CrawlQueue::STATUS_PENDING)
            ->whereIn('error_code', [CrawlErrorCode::Blocked->value, CrawlErrorCode::Challenge->value])
            ->where('next_attempt_at', '>', now())
            ->distinct()
            ->pluck('source_slug')
            ->all();
    }

    public function updateRetryableFailure(CrawlQueue $row, string $code, bool $requiresRetryableFlag, array $attributes): bool
    {
        $query = $this->newQuery()->whereKey($row->getKey())->where('status', CrawlQueue::STATUS_FAILED)->where('error_code', $code);
        if ($requiresRetryableFlag) {
            $query->where('retryable', true);
        }

        return $query->update($attributes) === 1;
    }

    /** @return Collection<int, CrawlQueue> */
    public function failedForSource(string $source): Collection
    {
        /** @var Collection<int, CrawlQueue> $rows */
        $rows = $this->newQuery()->where('source_slug', $source)->where('status', CrawlQueue::STATUS_FAILED)->orderBy('id')->get();

        return $rows;
    }

    /** @param list<string> $retryableCodes @return int number of eligible rows or rows imported */
    public function importHistoricalFailures(bool $dryRun, ?string $source, DateTimeInterface $cutoff, int $slice, array $retryableCodes): int
    {
        /** @var Builder<CrawlQueue> $eligible */
        $eligible = $this->newQuery()->where('status', CrawlQueue::STATUS_FAILED)->whereNotNull('last_failed_at')->where('last_failed_at', '<=', $cutoff);
        if ($source !== null) {
            $eligible->where('source_slug', $source);
        }
        $this->whereHistoricallyRetryable($eligible, $retryableCodes);
        if ($dryRun) {
            return $eligible->count();
        }

        $imported = 0;
        $eligible->chunkById($slice, function (Collection $rows) use (&$imported, $cutoff, $retryableCodes): void {
            $ids = $rows->modelKeys();
            /** @var Builder<CrawlQueue> $query */
            $query = $this->newQuery()->whereIn('id', $ids)->where('status', CrawlQueue::STATUS_FAILED)->where('last_failed_at', '<=', $cutoff);
            $this->whereHistoricallyRetryable($query, $retryableCodes);
            $imported += $query->update([
                'status' => CrawlQueue::STATUS_RETRY_PENDING,
                'attempts' => 0,
                'retry_round' => 1,
                'next_attempt_at' => now(),
            ]);
        });

        return $imported;
    }

    /** @param Builder<CrawlQueue> $query @param list<string> $retryableCodes */
    private function whereHistoricallyRetryable(Builder $query, array $retryableCodes): void
    {
        $requiresExplicitFlag = [CrawlErrorCode::Network->value, CrawlErrorCode::RateLimited->value];
        $inferredCodes = array_values(array_diff($retryableCodes, $requiresExplicitFlag));
        $query->where(static function (Builder $nested) use ($inferredCodes, $requiresExplicitFlag): void {
            $nested->whereIn('error_code', $inferredCodes)
                ->orWhere(static function (Builder $explicit) use ($requiresExplicitFlag): void {
                    $explicit->whereIn('error_code', $requiresExplicitFlag)->where('retryable', true);
                });
        });
    }

    /** @return list<string> */
    private function sourcesAtInflightLimit(?string $lane, CrawlLaneService $lanes): array
    {
        $limit = max(1, (int) config($lane === 'retry' ? 'javmeta_queues.retry.per_source_inflight' : 'javmeta_queues.per_source_inflight', 4));
        $query = $this->newQuery()
            ->where('status', CrawlQueue::STATUS_CLAIMED)
            ->when($lane === 'retry', static fn(Builder $query) => $query->where('claimed_lane', 'retry'))
            ->when($lane !== null && $lane !== 'retry', static fn(Builder $query) => $query->where(static function (Builder $nested): void {
                $nested->whereNull('claimed_lane')->orWhere('claimed_lane', '!=', 'retry');
            }))
            ->select('source_slug')
            ->groupBy('source_slug')
            ->havingRaw('COUNT(*) >= ?', [$limit]);
        $atLimit = array_values(array_map('strval', $query->pluck('source_slug')->all()));

        if ($lane === 'retry') {
            return $atLimit;
        }

        $cap = (int) config('javmeta_queues.browser_inflight', 0);
        $browserSources = $lanes->browserSources();
        if ($cap > 0 && $browserSources !== []) {
            $claimed = $this->newQuery()->where('status', CrawlQueue::STATUS_CLAIMED)
                ->where(static function (Builder $query) use ($browserSources): void {
                    $query->where(static function (Builder $laneQuery): void {
                        $laneQuery->whereNull('claimed_lane')->orWhere('claimed_lane', '!=', 'retry');
                    })->where(static function (Builder $browserQuery) use ($browserSources): void {
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
}
