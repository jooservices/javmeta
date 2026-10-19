<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\CrawlErrorCode;
use App\Jobs\FetchListingJob;
use App\Models\CrawlEvent;
use App\Models\CrawlQueue;
use App\Models\Movie;
use App\Models\MovieCode;
use App\Models\MovieMedia;
use App\Models\Source;
use App\Services\Crawler\CrawlerxClient;
use App\Services\Crawler\CrawlerxFetchResult;
use App\Services\Persist\GalleryPersister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use JOOservices\CrawlerX\Dto\CrawlItemResultDto;
use JOOservices\CrawlerX\Dto\CrawlListResultDto;
use JOOservices\CrawlerX\Dto\CrawlPaginationDto;
use JOOservices\CrawlerX\Dto\Entity\GalleryDto;
use JOOservices\CrawlerX\Dto\Entity\PhotoDto;
use Tests\Support\FakeCrawlerxClient;
use Tests\TestCase;

final class FetchListingJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_success_enqueues_detail_and_next_listing_rows_and_marks_done(): void
    {
        $source = $this->source('onejav');
        $row = $this->listingRow($source);

        $this->fakeClient(CrawlerxFetchResult::success(new CrawlListResultDto(
            url: 'https://onejav.com/new',
            page: 1,
            entityType: 'movie',
            items: [
                $this->movieItem('https://onejav.com/torrent/ymds282', 'YMDS-282'),
                $this->movieItem('https://onejav.com/torrent/abc123', 'ABC-123'),
            ],
            pagination: new CrawlPaginationDto(currentPage: 1, lastPage: null, nextPage: 2, nextUrl: 'https://onejav.com/new?page=2', hasNextPage: true),
        )));

        FetchListingJob::dispatch($row->id);

        $this->assertDatabaseHas('crawl_queue', [
            'source_slug' => 'onejav',
            'url' => 'https://onejav.com/torrent/ymds282',
            'kind' => CrawlQueue::KIND_DETAIL,
            'status' => CrawlQueue::STATUS_PENDING,
        ]);
        $this->assertDatabaseHas('crawl_queue', ['source_slug' => 'onejav', 'url' => 'https://onejav.com/torrent/abc123', 'kind' => CrawlQueue::KIND_DETAIL]);
        $this->assertDatabaseHas('crawl_queue', ['source_slug' => 'onejav', 'url' => 'https://onejav.com/new?page=2', 'kind' => CrawlQueue::KIND_LISTING]);
        $this->assertDatabaseHas('crawl_queue', ['id' => $row->id, 'status' => CrawlQueue::STATUS_DONE]);
        $this->assertDatabaseHas('crawl_events', ['source_slug' => 'onejav', 'kind' => 'listing_parsed']);
        $this->assertNotNull(Source::query()->find('onejav')?->last_success_at);
        self::assertNotNull($source->refresh()->last_success_at);
        self::assertLessThan(2.0, (float) $source->refresh()->gap_seconds_current);
    }

    public function test_success_enqueues_items_by_next_crawl_type_hint(): void
    {
        $source = $this->source('onejav');
        $row = $this->listingRow($source);

        $this->fakeClient(CrawlerxFetchResult::success(new CrawlListResultDto(
            url: 'https://onejav.com/new',
            page: 1,
            entityType: 'movie',
            items: [
                (new CrawlItemResultDto(
                    url: 'https://onejav.com/actress/',
                    entityType: 'performer',
                    meta: [],
                    nextCrawlType: 'performer_listing',
                )),
                $this->movieItem('https://onejav.com/torrent/ymds282', 'YMDS-282'),
            ],
            pagination: new CrawlPaginationDto(currentPage: 1, lastPage: null, nextPage: null, nextUrl: null, hasNextPage: false),
        )));

        FetchListingJob::dispatch($row->id);

        $this->assertDatabaseHas('crawl_queue', ['source_slug' => 'onejav', 'url' => 'https://onejav.com/actress/', 'kind' => CrawlQueue::KIND_PERFORMER_LISTING]);
        $this->assertDatabaseHas('crawl_queue', ['source_slug' => 'onejav', 'url' => 'https://onejav.com/torrent/ymds282', 'kind' => CrawlQueue::KIND_DETAIL]);
    }

    public function test_gallery_listing_enqueues_gallery_items_and_next_listing_page(): void
    {
        self::assertSame(CrawlQueue::KIND_GALLERY, CrawlQueue::kindForNextCrawlType(null, 'gallery'));

        $source = $this->source('javphotos');
        $row = $this->listingRow($source);

        $this->fakeClient(CrawlerxFetchResult::success(new CrawlListResultDto(
            url: 'https://jav.photos/free/',
            page: 1,
            entityType: 'gallery',
            items: [
                new CrawlItemResultDto(
                    url: 'https://jav.photos/gallery/ssis-001/',
                    entityType: 'gallery',
                    meta: ['gallery' => ['metadata' => ['movie_code' => 'SSIS-001']]],
                    nextCrawlType: 'gallery',
                ),
            ],
            pagination: new CrawlPaginationDto(currentPage: 1, lastPage: null, nextPage: 2, nextUrl: 'https://jav.photos/free/page/2/', hasNextPage: true),
        )));

        FetchListingJob::dispatch($row->id);

        $this->assertDatabaseHas('crawl_queue', [
            'source_slug' => 'javphotos',
            'url' => 'https://jav.photos/gallery/ssis-001/',
            'kind' => CrawlQueue::KIND_GALLERY,
            'status' => CrawlQueue::STATUS_PENDING,
        ]);
        $this->assertDatabaseHas('crawl_queue', [
            'source_slug' => 'javphotos',
            'url' => 'https://jav.photos/free/page/2/',
            'kind' => CrawlQueue::KIND_LISTING,
            'status' => CrawlQueue::STATUS_PENDING,
        ]);
        $this->assertDatabaseHas('crawl_events', ['source_slug' => 'javphotos', 'kind' => 'gallery_listing_parsed']);
        $this->assertDatabaseHas('crawl_queue', ['id' => $row->id, 'status' => CrawlQueue::STATUS_DONE]);
    }

    public function test_gallery_persister_attaches_media_using_metadata_movie_code(): void
    {
        $movie = Movie::factory()->create([
            'display_code' => 'SSIS-001',
            'code_normalized' => 'SSIS001',
        ]);
        MovieCode::factory()->create([
            'movie_id' => $movie->id,
            'code' => 'SSIS-001',
            'code_normalized' => 'SSIS001',
            'kind' => MovieCode::KIND_DVD,
            'source_slug' => 'javdb',
        ]);

        app(GalleryPersister::class)->persist(
            'javphotos',
            'https://jav.photos/gallery/unhelpful-title/',
            new GalleryDto(
                externalId: 'gallery-1',
                title: 'No usable title code here',
                performers: [],
                photos: [
                    new PhotoDto(
                        id: '1',
                        url: 'https://jav.photos/photo/1',
                        imageUrl: 'https://jav.photos/full/1.jpg',
                        thumbnailUrl: 'https://jav.photos/thumb/1.jpg',
                        position: 1,
                    ),
                ],
                metadata: ['movie_code' => 'ssis-1'],
            ),
        );

        $this->assertDatabaseHas('movie_media', [
            'movie_id' => $movie->id,
            'kind' => MovieMedia::KIND_GALLERY,
            'url' => 'https://jav.photos/full/1.jpg',
            'source_slug' => 'javphotos',
        ]);
    }

    public function test_blocked_failure_records_blocked_event_and_retries_after_a_delay(): void
    {
        $source = $this->source('onejav');
        $row = $this->listingRow($source);

        $this->fakeClient(CrawlerxFetchResult::failure(CrawlerxClient::ERROR_BLOCKED, 'Blocked by site.'));

        FetchListingJob::dispatch($row->id);

        $this->assertDatabaseHas('crawl_events', ['source_slug' => 'onejav', 'kind' => CrawlEvent::KIND_BLOCKED]);
        $this->assertDatabaseHas('crawl_queue', [
            'id' => $row->id,
            'status' => CrawlQueue::STATUS_PENDING,
            'attempts' => 1,
            'last_error' => 'Blocked by site.',
            'error_code' => CrawlErrorCode::Blocked->value,
        ]);
        $this->assertDatabaseHas('sources', ['slug' => 'onejav', 'last_error' => 'Blocked by site.']);
        self::assertSame(4.0, (float) $source->refresh()->gap_seconds_current);
    }

    public function test_challenge_failure_records_challenge_event(): void
    {
        $source = $this->source('onejav');
        $row = $this->listingRow($source);

        $this->fakeClient(CrawlerxFetchResult::failure(CrawlerxClient::ERROR_CHALLENGE, 'Challenge wall detected.'));

        FetchListingJob::dispatch($row->id);

        $this->assertDatabaseHas('crawl_events', ['source_slug' => 'onejav', 'kind' => CrawlEvent::KIND_CHALLENGE]);
        $this->assertDatabaseHas('crawl_queue', ['id' => $row->id, 'status' => CrawlQueue::STATUS_PENDING, 'error_code' => CrawlErrorCode::Challenge->value]);
    }

    public function test_parse_failure_records_parse_drift_event(): void
    {
        $source = $this->source('onejav');
        $row = $this->listingRow($source);

        $this->fakeClient(CrawlerxFetchResult::failure(CrawlerxClient::ERROR_PARSE_FAILED, 'Could not parse listing.'));

        FetchListingJob::dispatch($row->id);

        $this->assertDatabaseHas('crawl_events', ['source_slug' => 'onejav', 'kind' => CrawlEvent::KIND_PARSE_DRIFT]);
        $this->assertDatabaseHas('crawl_queue', ['id' => $row->id, 'status' => CrawlQueue::STATUS_FAILED, 'error_code' => CrawlErrorCode::ParseFailed->value]);
    }

    public function test_non_movie_listing_records_parse_drift_and_marks_row_done_without_detail_rows(): void
    {
        $source = $this->source('onejav');
        $row = $this->listingRow($source);

        $this->fakeClient(CrawlerxFetchResult::success(new CrawlListResultDto(
            url: 'https://onejav.com/actress/',
            page: 1,
            entityType: 'performer',
            items: [],
            pagination: new CrawlPaginationDto(currentPage: 1, lastPage: null, nextPage: null, nextUrl: null, hasNextPage: false),
        )));

        FetchListingJob::dispatch($row->id);

        $this->assertDatabaseHas('crawl_events', ['source_slug' => 'onejav', 'kind' => CrawlEvent::KIND_PARSE_DRIFT]);
        $this->assertDatabaseHas('crawl_queue', ['id' => $row->id, 'status' => CrawlQueue::STATUS_DONE]);
        $this->assertSame(0, CrawlQueue::query()->where('kind', CrawlQueue::KIND_DETAIL)->count());
    }

    public function test_unknown_source_slug_fails_row_without_touching_throttle_or_circuit(): void
    {
        $row = CrawlQueue::factory()->create([
            'source_slug' => 'ghost',
            'url' => 'https://ghost.test/new',
            'kind' => CrawlQueue::KIND_LISTING,
            'status' => CrawlQueue::STATUS_CLAIMED,
        ]);

        $this->fakeClient(CrawlerxFetchResult::failure(CrawlerxClient::ERROR_PARSE_FAILED, 'unused'));

        FetchListingJob::dispatch($row->id);

        $this->assertDatabaseHas('crawl_events', ['source_slug' => 'ghost', 'kind' => CrawlEvent::KIND_PARSE_DRIFT]);
        $this->assertDatabaseHas('crawl_queue', ['id' => $row->id, 'status' => CrawlQueue::STATUS_FAILED, 'error_code' => CrawlErrorCode::UnknownSource->value]);
        $this->assertDatabaseMissing('sources', ['slug' => 'ghost']);
    }

    public function test_dispatch_pushes_job_to_the_queue(): void
    {
        $source = $this->source('onejav');
        $row = $this->listingRow($source);

        Queue::fake();

        FetchListingJob::dispatch($row->id);

        Queue::assertPushedOn('listing', FetchListingJob::class);
    }

    private function source(string $slug): Source
    {
        return Source::factory()->create([
            'slug' => $slug,
            'base_url' => 'https://onejav.com',
            'gap_seconds_default' => 2.0,
            'gap_seconds_min' => 0.5,
            'gap_seconds_max' => 15.0,
            'gap_seconds_current' => 2.0,
        ]);
    }

    private function listingRow(Source $source): CrawlQueue
    {
        return CrawlQueue::factory()->create([
            'source_slug' => $source->slug,
            'url' => 'https://onejav.com/new',
            'kind' => CrawlQueue::KIND_LISTING,
            'status' => CrawlQueue::STATUS_CLAIMED,
            'attempts' => 0,
            'max_attempts' => 3,
        ]);
    }

    private function movieItem(string $url, string $code): CrawlItemResultDto
    {
        return new CrawlItemResultDto(
            url: $url,
            entityType: 'movie',
            meta: ['movie' => ['external_id' => strtolower($code), 'title' => $code, 'code' => $code]],
        );
    }

    private function fakeClient(CrawlerxFetchResult $listing): void
    {
        $this->app->instance(
            CrawlerxClient::class,
            new FakeCrawlerxClient($listing, CrawlerxFetchResult::failure(CrawlerxClient::ERROR_PARSE_FAILED, 'unused')),
        );
    }
}
