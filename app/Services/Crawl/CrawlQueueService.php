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

    public function markFailed(
        CrawlQueue $row,
        CrawlErrorCode $errorCode,
        string $message,
        bool $retryable,
        ?int $retryAfterSeconds = null,
    ): void {
        $safeMessage = self::sanitizeErrorMessage($message);
        $willRetry = $retryable && $row->attempts < $row->max_attempts;

        $row->forceFill([
            'status' => $willRetry ? CrawlQueue::STATUS_PENDING : CrawlQueue::STATUS_FAILED,
            'claimed_at' => null,
            'locked_by' => null,
            'next_attempt_at' => $willRetry ? $this->retryAt($row, $retryAfterSeconds) : null,
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
