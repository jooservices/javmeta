<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\CrawlErrorCode;
use App\Jobs\Concerns\HandlesCrawlQueueRow;
use App\Jobs\Middleware\TraceCrawlJob;
use App\Models\CrawlEvent;
use App\Models\CrawlQueue;
use App\Models\Source;
use App\Services\Crawl\CrawlQueueService;
use App\Services\Crawl\FetchAttemptRecorder;
use App\Services\Crawl\SourceActivityRecorder;
use App\Services\Crawl\SourceThrottle;
use App\Services\Crawler\CrawlerxClient;
use App\Support\Crawl\LaravelCrawlQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class FetchPerformerListingJob implements ShouldQueue
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
        $this->onQueue(LaravelCrawlQueue::forPerformerListing());
    }

    /** @return list<class-string> */
    public function middleware(): array
    {
        return [TraceCrawlJob::class];
    }

    public function handle(CrawlerxClient $client, CrawlQueueService $queue, SourceActivityRecorder $activity, SourceThrottle $throttle): void
    {
        $row = $this->queueRow($this->crawlQueueId, CrawlQueue::KIND_PERFORMER_LISTING);
        if (! $row instanceof CrawlQueue || $row->kind !== CrawlQueue::KIND_PERFORMER_LISTING) {
            return;
        }

        $source = Source::query()->find($row->source_slug);
        if (! $source instanceof Source) {
            $this->recordEvent($row->source_slug, CrawlEvent::KIND_PARSE_DRIFT, $row->url, ['reason' => 'unknown_source']);
            $this->markFailed($row, CrawlErrorCode::UnknownSource, 'Unknown source slug.');

            return;
        }

        $result = $client->fetchPerformerListing($row->source_slug, $row->url);
        app(FetchAttemptRecorder::class)->record($row, $result);
        if (! $result->ok || $result->list === null) {
            $this->onCrawlFailure($row, $source, $result, $activity, $throttle);

            return;
        }

        $list = $result->list;

        if ($list->entityType === 'performer') {
            $enqueued = 0;
            foreach ($list->items as $item) {
                $queue->enqueue($item->url, $row->source_slug, CrawlQueue::kindForNextCrawlType($item->nextCrawlType, $list->entityType));
                $enqueued++;
            }

            if ($list->pagination->nextUrl !== null) {
                $queue->enqueue($list->pagination->nextUrl, $row->source_slug, CrawlQueue::KIND_PERFORMER_LISTING);
            }

            $this->recordEvent($row->source_slug, 'performer_listing_parsed', $row->url, [
                'items' => count($list->items),
                'enqueued' => $enqueued,
            ]);
        } else {
            $this->recordEvent($row->source_slug, CrawlEvent::KIND_PARSE_DRIFT, $row->url, [
                'entity_type' => $list->entityType,
                'reason' => 'non_performer_listing',
            ]);
        }

        $activity->recordSuccess($source);
        $throttle->onSuccess($source);
        $this->markDone($row);
    }
}
