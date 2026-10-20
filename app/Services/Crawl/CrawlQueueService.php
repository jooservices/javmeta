<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Enums\CrawlErrorCode;
use App\Models\CrawlEvent;
use App\Models\CrawlQueue;
use App\Models\Source;
use App\Observability\Sentry\CrawlFailureReporter;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CrawlQueueService
{
    /** Retry delay by attempt number when crawlerx gives no Retry-After hint. */
    private const RETRY_BACKOFF_SECONDS = [60, 300, 1800];

    public function claimNext(string $workerId, ?string $kind = null): ?CrawlQueue
    {
        return DB::transaction(function () use ($workerId, $kind): ?CrawlQueue {
            $query = CrawlQueue::query()
                ->where('status', CrawlQueue::STATUS_PENDING)
                ->where(function ($q): void {
                    $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
                })
                ->orderBy('id');

            if ($kind !== null) {
                $query->where('kind', $kind);
            }

            // Rows of a disabled source stay queued until it is enabled again.
            $query->whereNotIn('source_slug', Source::query()->where('enabled', false)->pluck('slug')->all());

            // A site that is waiting out a block delay gets no new claims until the delay is over.
            $query->whereNotIn('source_slug', $this->sourcesHeldByBlockDelay());

            if (DB::getDriverName() === 'pgsql') {
                $query->lockForUpdate();
            }

            $row = $query->first();
            if (! $row instanceof CrawlQueue) {
                return null;
            }

            $row->forceFill([
                'status' => CrawlQueue::STATUS_CLAIMED,
                'claimed_at' => now(),
                'started_at' => null,
                'locked_by' => $workerId,
            ])->save();

            return $row->refresh();
        });
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
                'next_attempt_at' => now()->addSeconds((int) ($source->gap_seconds_current ?? 0)),
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
            $nextAttemptAt = now()->addSeconds((int) ($source->gap_seconds_current ?? 0));
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
        $blockDelay = $this->blockDelay($errorCode, $row);
        $willRetry = $blockDelay !== null
            ? $blockDelay > 0
            : $retryable && $row->attempts < $row->max_attempts;

        $row->forceFill([
            'status' => $willRetry ? CrawlQueue::STATUS_PENDING : CrawlQueue::STATUS_FAILED,
            'claimed_at' => null,
            'started_at' => null,
            'locked_by' => null,
            'next_attempt_at' => $willRetry ? ($blockDelay > 0 ? now()->addSeconds(max($blockDelay, (int) $retryAfterSeconds)) : $this->retryAt($row, $retryAfterSeconds)) : null,
            'error_code' => $errorCode->value,
            'last_error' => $safeMessage,
            'last_failed_at' => now(),
        ])->save();
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

            $row->forceFill([
                'started_at' => now(),
                'claimed_at' => now(),
                'attempts' => $row->attempts + 1,
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
                            $row->attempts < $row->max_attempts,
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

    /**
     * Seconds to wait before retrying a blocked or challenged crawl: the configured delay for this retry
     * (60, 120, 180 by default). 0 means the delays are used up and the row fails; null means the error is not a block.
     */
    private function blockDelay(CrawlErrorCode $errorCode, CrawlQueue $row): ?int
    {
        if (! in_array($errorCode, [CrawlErrorCode::Blocked, CrawlErrorCode::Challenge], true)) {
            return null;
        }

        /** @var list<int> $delays */
        $delays = array_values((array) config('javmeta_sources.defaults.queue.block_delays_seconds', [60, 120, 180]));

        return (int) ($delays[max($row->attempts, 1) - 1] ?? 0);
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

    private function retryAt(CrawlQueue $row, ?int $retryAfterSeconds): Carbon
    {
        if ($retryAfterSeconds === 0) {
            return now();
        }

        if ($retryAfterSeconds !== null && $retryAfterSeconds > 0) {
            return now()->addSeconds($retryAfterSeconds);
        }

        $index = min(max($row->attempts, 1), count(self::RETRY_BACKOFF_SECONDS)) - 1;

        return now()->addSeconds(self::RETRY_BACKOFF_SECONDS[$index]);
    }
}
