<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\CrawlErrorCode;
use App\Jobs\Concerns\HandlesCrawlQueueRow;
use App\Jobs\Middleware\TraceCrawlJob;
use App\Models\CrawlEvent;
use App\Models\CrawlQueue;
use App\Models\Source;
use App\Services\Crawl\PerformerDraftSink;
use App\Services\Crawl\SourceActivityRecorder;
use App\Services\Crawl\SourceThrottle;
use App\Services\Crawler\CrawlerxClient;
use App\Services\Normalize\PerformerNormalizer;
use App\Support\Crawl\LaravelCrawlQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class FetchPerformerDetailJob implements ShouldQueue
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
        $this->onQueue(LaravelCrawlQueue::forPerformerDetail());
    }

    /** @return list<class-string> */
    public function middleware(): array
    {
        return [TraceCrawlJob::class];
    }

    public function handle(
        CrawlerxClient $client,
        PerformerNormalizer $normalizer,
        SourceActivityRecorder $activity,
        SourceThrottle $throttle,
    ): void {
        $row = $this->queueRow($this->crawlQueueId, CrawlQueue::KIND_PERFORMER_DETAIL);
        if (! $row instanceof CrawlQueue || $row->kind !== CrawlQueue::KIND_PERFORMER_DETAIL) {
            return;
        }

        $source = Source::query()->find($row->source_slug);
        if (! $source instanceof Source) {
            $this->recordEvent($row->source_slug, CrawlEvent::KIND_PARSE_DRIFT, $row->url, ['reason' => 'unknown_source']);
            $this->markFailed($row, CrawlErrorCode::UnknownSource, 'Unknown source slug.');

            return;
        }

        $result = $client->fetchPerformerDetail($row->source_slug, $row->url);
        if (! $result->ok || $result->performer === null) {
            $this->onCrawlFailure($row, $source, $result, $activity, $throttle);

            return;
        }

        $draft = $normalizer->normalize($result->performer, $row->source_slug, $row->url);
        if ($draft === null) {
            $this->recordEvent($row->source_slug, CrawlEvent::KIND_PARSE_DRIFT, $row->url, ['reason' => 'performer_normalize_failed']);
            $this->markFailed($row, CrawlErrorCode::NormalizationFailed, 'Performer normalization failed.');
            $activity->recordFailure($source, 'Performer normalization failed.');
            $throttle->onFailure($source);

            return;
        }

        $sink = app()->bound(PerformerDraftSink::class) ? app(PerformerDraftSink::class) : null;
        if ($sink instanceof PerformerDraftSink) {
            $sink->accept($draft);
        }

        $activity->recordSuccess($source);
        $throttle->onSuccess($source);
        $this->markDone($row);
    }
}
