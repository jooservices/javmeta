<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Enums\CrawlErrorCode;
use App\Events\CrawlIncidentOccurred;
use App\Models\CrawlEvent;
use App\Models\CrawlQueue;
use App\Observability\Sentry\CrawlFailureReporter;
use App\Observability\ObservabilityEmitter;
use App\Models\Source;
use App\Services\Crawl\CrawlQueueService;
use App\Services\Crawl\SourceActivityRecorder;
use App\Services\Crawl\SourceThrottle;
use App\Services\Crawl\TitleFailureTracker;
use App\Services\Crawler\CrawlerxClient;
use App\Services\Crawler\CrawlerxFetchResult;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Throwable;

/**
 * Shared crawl_queue row bookkeeping for the fetch jobs: row lookup, terminal
 * state transitions, crawlerx-driven retry scheduling, crawl event recording,
 * and the per-source bookkeeping (throttle, last success / error).
 *
 * attempts is incremented once when the worker starts the claimed row.
 */
trait HandlesCrawlQueueRow
{
    private function queueRow(int $id, string $expectedKind): ?CrawlQueue
    {
        $row = app(CrawlQueueService::class)->startClaimedRow($id, $expectedKind, $this->claimToken ?? null);
        if (! $row instanceof CrawlQueue) {
            $current = CrawlQueue::query()->find($id);
            $kind = $expectedKind;
            $status = 'missing';
            if ($current instanceof CrawlQueue) {
                $kind = $current->kind;
                $status = $current->status;
            }

            app(ObservabilityEmitter::class)->emitOps('crawl_job_skipped', [
                'crawl_queue_id' => $id,
                'kind' => $kind,
                'status' => $status,
                'job' => static::class,
            ]);

            return null;
        }

        return $row;
    }

    private function markDone(CrawlQueue $row): void
    {
        $values = [
            'status' => CrawlQueue::STATUS_DONE,
            'claimed_at' => null,
            'started_at' => null,
            'locked_by' => null,
            'last_error' => null,
            'error_code' => null,
            'last_failed_at' => null,
            'is_recrawl' => false,
        ];

        if (in_array($row->kind, [CrawlQueue::KIND_LISTING, CrawlQueue::KIND_PERFORMER_LISTING], true)) {
            $interval = max(1, (int) config('javmeta_sources.defaults.loop.fresh_interval_minutes', 60));
            $values['next_attempt_at'] = now()->addMinutes($interval);
        }

        $row->forceFill($values)->save();
    }

    private function markFailed(
        CrawlQueue $row,
        CrawlErrorCode $errorCode,
        string $message,
        bool $retryable = false,
        ?int $retryAfterSeconds = null,
        ?Throwable $exception = null,
    ): void {
        app(CrawlQueueService::class)->markFailed($row, $errorCode, $message, $retryable, $retryAfterSeconds);
        app(CrawlFailureReporter::class)->report(
            $exception ?? new RuntimeException(CrawlQueueService::sanitizeErrorMessage($message)),
            $row->source_slug,
            $row->kind,
            $errorCode->value,
        );
    }

    public function failed(Throwable $exception): void
    {
        $row = CrawlQueue::query()->find($this->crawlQueueId);
        if (! $row instanceof CrawlQueue
            || $row->status !== CrawlQueue::STATUS_CLAIMED
            || $row->started_at === null
            || (($this->claimToken ?? null) !== null && $row->locked_by !== $this->claimToken)) {
            return;
        }

        $timedOut = $exception instanceof TimeoutExceededException;
        $errorCode = $timedOut ? CrawlErrorCode::Timeout : CrawlErrorCode::WorkerFailed;
        $this->markFailed(
            $row,
            $errorCode,
            $timedOut ? 'Worker timed out while processing the crawl row.' : $exception->getMessage(),
            $row->attempts < $row->max_attempts,
            exception: $exception,
        );
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function recordEvent(string $sourceSlug, string $kind, ?string $url, array $detail): void
    {
        Event::dispatch(new CrawlIncidentOccurred($sourceSlug, $kind, $url, $detail));
    }

    private function onCrawlFailure(
        CrawlQueue $row,
        Source $source,
        CrawlerxFetchResult $result,
        SourceActivityRecorder $activity,
        SourceThrottle $throttle,
    ): void {
        $errorCode = $result->errorCode ?? CrawlerxClient::ERROR_PARSE_FAILED;
        $message = CrawlQueueService::sanitizeErrorMessage($result->errorMessage ?? 'Crawl failed.');
        $soft404 = in_array($errorCode, [CrawlerxClient::ERROR_SOFT404, CrawlerxClient::ERROR_NOT_FOUND, CrawlerxClient::ERROR_GONE], true);

        $this->recordEvent($row->source_slug, $this->eventKind($errorCode, $soft404), $row->url, [
            'error_code' => $errorCode,
            'error' => $message,
            'soft404' => $soft404,
            'retryable' => $result->retryable,
        ]);

        $this->markFailed($row, CrawlErrorCode::fromCrawlerx($errorCode), $message, $result->retryable, $result->retryAfterSeconds);

        $activity->recordFailure($source, $message);
        $throttle->onFailure($source, $errorCode);

        if (in_array($row->kind, [CrawlQueue::KIND_DETAIL, CrawlQueue::KIND_PERFORMER_DETAIL], true)) {
            app(TitleFailureTracker::class)->recordFailure(
                $row->source_slug,
                $row->url,
                $message,
                $soft404,
            );
        }
    }

    private function onCrawlSuccess(CrawlQueue $row): void
    {
        if ($row->kind === CrawlQueue::KIND_DETAIL) {
            app(TitleFailureTracker::class)->recordSuccess($row->source_slug, $row->url);
        }
    }

    private function eventKind(string $errorCode, bool $soft404 = false): string
    {
        if ($soft404) {
            return CrawlEvent::KIND_SOFT404;
        }

        return match ($errorCode) {
            CrawlerxClient::ERROR_BLOCKED,
            CrawlerxClient::ERROR_RATE_LIMITED,
            CrawlerxClient::ERROR_SSRF_BLOCKED,
            CrawlerxClient::ERROR_TIMEOUT,
            CrawlerxClient::ERROR_NETWORK => CrawlEvent::KIND_BLOCKED,
            CrawlerxClient::ERROR_CHALLENGE => CrawlEvent::KIND_CHALLENGE,
            CrawlerxClient::ERROR_SOFT404,
            CrawlerxClient::ERROR_NOT_FOUND,
            CrawlerxClient::ERROR_GONE => CrawlEvent::KIND_SOFT404,
            CrawlerxClient::ERROR_AUTH_REQUIRED => CrawlEvent::KIND_AUTH,
            default => CrawlEvent::KIND_PARSE_DRIFT,
        };
    }
}
