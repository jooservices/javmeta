<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Crawler\CrawlerxClient;
use App\Services\Crawler\CrawlerxFetchResult;
use App\Models\CrawlQueue;

final class FakeCrawlerxClient extends CrawlerxClient
{
    public int $calls = 0;

    public function __construct(
        private readonly CrawlerxFetchResult $listing,
        private readonly CrawlerxFetchResult $detail,
        private readonly ?CrawlerxFetchResult $performerListing = null,
        private readonly ?CrawlerxFetchResult $performerDetail = null,
        private readonly ?CrawlerxFetchResult $gallery = null,
    ) {}

    public function fetchListing(string $sourceSlug, string $url): CrawlerxFetchResult
    {
        $this->calls++;

        return $this->listing;
    }

    public function fetchDetail(string $sourceSlug, string $url): CrawlerxFetchResult
    {
        $this->calls++;

        return $this->detail;
    }

    public function fetchPerformerListing(string $sourceSlug, string $url): CrawlerxFetchResult
    {
        $this->calls++;

        return $this->performerListing ?? $this->listing;
    }

    public function fetchPerformerDetail(string $sourceSlug, string $url): CrawlerxFetchResult
    {
        $this->calls++;

        return $this->performerDetail ?? $this->detail;
    }

    public function fetchGallery(string $sourceSlug, string $url): CrawlerxFetchResult
    {
        $this->calls++;

        return $this->gallery ?? CrawlerxFetchResult::failure(self::ERROR_PARSE_FAILED, 'No gallery fixture.');
    }

    public function fetchQueueRow(CrawlQueue $row): CrawlerxFetchResult
    {
        return match ($row->kind) {
            CrawlQueue::KIND_LISTING => $this->fetchListing($row->source_slug, $row->url),
            CrawlQueue::KIND_DETAIL => $this->fetchDetail($row->source_slug, $row->url),
            CrawlQueue::KIND_PERFORMER_LISTING => $this->fetchPerformerListing($row->source_slug, $row->url),
            CrawlQueue::KIND_PERFORMER_DETAIL => $this->fetchPerformerDetail($row->source_slug, $row->url),
            CrawlQueue::KIND_GALLERY => $this->fetchGallery($row->source_slug, $row->url),
            default => CrawlerxFetchResult::failure(self::ERROR_PARSE_FAILED, 'No fixture for queue row.'),
        };
    }
}
