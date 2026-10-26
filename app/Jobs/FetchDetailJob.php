<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\CrawlErrorCode;
use App\Data\Crawl\MovieDraft;
use App\Events\MovieDraftCaptured;
use App\Jobs\Concerns\HandlesCrawlQueueRow;
use App\Jobs\Middleware\TraceCrawlJob;
use App\Models\CrawlEvent;
use App\Models\CrawlQueue;
use App\Models\Source;
use App\Services\Crawl\SourceActivityRecorder;
use App\Services\Crawl\CrawlQueueService;
use App\Services\Crawl\FetchAttemptRecorder;
use App\Services\Crawl\SourceThrottle;
use App\Services\Crawl\MovieDraftSink;
use App\Services\Crawler\CrawlerxClient;
use App\Services\Normalize\JavbtcCodeMissingException;
use App\Services\Normalize\NormalizationFailedException;
use App\Services\Normalize\SourceNormalizerRegistry;
use App\Support\Crawl\LaravelCrawlQueue;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

final class FetchDetailJob implements ShouldQueue
{
    use Dispatchable;
    use HandlesCrawlQueueRow;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $crawlQueueId,
        public readonly ?string $traceparent = null,
        public readonly ?string $claimToken = null,
    ) {
        $this->onQueue(LaravelCrawlQueue::forDetail());
    }

    /** @return list<class-string> */
    public function middleware(): array
    {
        return [TraceCrawlJob::class];
    }

    public function handle(
        CrawlerxClient $client,
        SourceNormalizerRegistry $registry,
        SourceActivityRecorder $activity,
        SourceThrottle $throttle,
    ): void {
        $row = $this->queueRow($this->crawlQueueId, CrawlQueue::KIND_DETAIL);
        if (! $row instanceof CrawlQueue || $row->kind !== CrawlQueue::KIND_DETAIL) {
            return;
        }

        $source = Source::query()->find($row->source_slug);
        if (! $source instanceof Source) {
            $this->recordEvent($row->source_slug, CrawlEvent::KIND_PARSE_DRIFT, $row->url, ['reason' => 'unknown_source']);
            $this->markFailed($row, CrawlErrorCode::UnknownSource, 'Unknown source slug.');

            return;
        }

        $result = $client->fetchDetail($row->source_slug, $row->url);
        app(FetchAttemptRecorder::class)->record($row, $result);
        if (! $result->ok || $result->movie === null) {
            $this->onCrawlFailure($row, $source, $result, $activity, $throttle);

            return;
        }

        $normalizer = $registry->forSlug($row->source_slug);
        if ($normalizer === null) {
            $this->recordEvent($row->source_slug, CrawlEvent::KIND_PARSE_DRIFT, $row->url, ['reason' => 'no_normalizer']);
            $this->markFailed($row, CrawlErrorCode::NormalizerMissing, 'No normalizer for source slug.');
            $activity->recordFailure($source, 'No normalizer for source slug.');
            $throttle->onFailure($source);

            return;
        }

        try {
            $draft = $normalizer->normalizeMovie(
                $result->movie,
                $result->movie->metadata,
                $row->source_slug,
                $row->url,
                CarbonImmutable::now(),
            );
        } catch (JavbtcCodeMissingException $exception) {
            $message = CrawlQueueService::sanitizeErrorMessage($exception->getMessage());
            $this->recordEvent($row->source_slug, CrawlEvent::KIND_PARSE_DRIFT, $row->url, ['reason' => $message]);
            $this->markFailed($row, CrawlErrorCode::ParseFailed, $message);
            $activity->recordFailure($source, $message);
            $throttle->onFailure($source);

            return;
        } catch (NormalizationFailedException $exception) {
            $message = CrawlQueueService::sanitizeErrorMessage($exception->getMessage());
            $this->recordEvent($row->source_slug, CrawlEvent::KIND_PARSE_DRIFT, $row->url, ['reason' => $message]);
            $this->markFailed($row, CrawlErrorCode::NormalizationFailed, $message);
            $activity->recordFailure($source, $message);
            $throttle->onFailure($source);

            return;
        }

        $this->deliverDraft($draft, $row->is_recrawl);

        $activity->recordSuccess($source);
        $throttle->onSuccess($source);
        $this->onCrawlSuccess($row);
        $this->markDone($row);
    }

    private function deliverDraft(MovieDraft $draft, bool $isRecrawl): void
    {
        $sink = app()->bound(MovieDraftSink::class) ? app(MovieDraftSink::class) : null;
        if ($sink instanceof MovieDraftSink) {
            $sink->accept($draft, $isRecrawl);

            return;
        }

        MovieDraftCaptured::dispatch($draft, $isRecrawl);
    }
}
