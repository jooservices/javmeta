<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Enums\CrawlErrorCode;
use App\Models\CrawlQueue;
use App\Models\Source;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

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
                'locked_by' => $workerId,
                'attempts' => $row->attempts + 1,
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
            'locked_by' => null,
            'next_attempt_at' => $willRetry ? ($blockDelay > 0 ? now()->addSeconds(max($blockDelay, (int) $retryAfterSeconds)) : $this->retryAt($row, $retryAfterSeconds)) : null,
            'error_code' => $errorCode->value,
            'last_error' => $safeMessage,
            'last_failed_at' => now(),
        ])->save();
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

        return DB::transaction(function () use ($cutoff): int {
            $query = CrawlQueue::query()
                ->where('status', CrawlQueue::STATUS_CLAIMED)
                ->where('claimed_at', '<', $cutoff)
                ->orderBy('id');

            if (DB::getDriverName() === 'pgsql') {
                $query->lockForUpdate();
            }

            $reclaimed = 0;
            $query->chunkById(100, function (Collection $staleRows) use (&$reclaimed): void {
                foreach ($staleRows as $row) {
                    $this->markFailed(
                        $row,
                        CrawlErrorCode::WorkerLost,
                        'Worker claim expired before the crawl row was completed.',
                        $row->attempts < $row->max_attempts,
                        0,
                    );
                    $reclaimed++;
                }
            });

            return $reclaimed;
        });
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
