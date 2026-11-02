<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\CrawlErrorCode;
use App\Jobs\Concerns\HandlesCrawlQueueRow;
use App\Jobs\Middleware\TraceCrawlJob;
use App\Models\CrawlEvent;
use App\Models\CrawlQueue;
use App\Models\Source;
use App\Services\Crawl\FetchAttemptRecorder;
use App\Services\Crawl\SourceActivityRecorder;
use App\Services\Crawl\SourceThrottle;
use App\Services\Crawler\CrawlerxClient;
use App\Services\Persist\GalleryPersister;
use App\Support\Crawl\LaravelCrawlQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class FetchGalleryJob implements ShouldQueue
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
        $this->onQueue(LaravelCrawlQueue::forGallery());
    }

    /** @return list<class-string> */
    public function middleware(): array
    {
        return [TraceCrawlJob::class];
    }

    public function handle(
        CrawlerxClient $client,
        GalleryPersister $persister,
        SourceActivityRecorder $activity,
        SourceThrottle $throttle,
    ): void {
        $row = $this->queueRow($this->crawlQueueId, CrawlQueue::KIND_GALLERY);
        if (! $row instanceof CrawlQueue || $row->kind !== CrawlQueue::KIND_GALLERY) {
            return;
        }

        $source = Source::query()->find($row->source_slug);
        if (! $source instanceof Source) {
            $this->recordEvent($row->source_slug, CrawlEvent::KIND_PARSE_DRIFT, $row->url, ['reason' => 'unknown_source']);
            $this->markFailed($row, CrawlErrorCode::UnknownSource, 'Unknown source slug.');

            return;
        }

        $result = $client->fetchGallery($row->source_slug, $row->url);
        app(FetchAttemptRecorder::class)->record($row, $result);
        if (! $result->ok || $result->gallery === null) {
            $this->onCrawlFailure($row, $source, $result, $activity, $throttle);

            return;
        }

        $persister->persist($row->source_slug, $row->url, $result->gallery);

        $this->recordEvent($row->source_slug, 'gallery_parsed', $row->url, [
            'photo_count' => $result->gallery->photoCount ?? count($result->gallery->photos),
            'title' => $result->gallery->title,
        ]);

        $activity->recordSuccess($source);
        $throttle->onSuccess($source);
        $this->markDone($row);
    }
}
