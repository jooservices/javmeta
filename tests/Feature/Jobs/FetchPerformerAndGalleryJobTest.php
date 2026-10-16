<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\CrawlErrorCode;
use App\Jobs\FetchGalleryJob;
use App\Jobs\FetchPerformerDetailJob;
use App\Jobs\FetchPerformerListingJob;
use App\Models\CrawlQueue;
use App\Models\Performer;
use App\Models\Source;
use App\Models\Movie;
use App\Models\MovieCode;
use App\Models\MovieMedia;
use App\Models\PerformerMedia;
use App\Services\Crawler\CrawlerxClient;
use App\Services\Crawler\CrawlerxFetchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use JOOservices\CrawlerX\Dto\CrawlItemResultDto;
use JOOservices\CrawlerX\Dto\CrawlListResultDto;
use JOOservices\CrawlerX\Dto\CrawlPaginationDto;
use JOOservices\CrawlerX\Dto\Entity\GalleryDto;
use JOOservices\CrawlerX\Dto\Entity\PerformerDto;
use JOOservices\CrawlerX\Dto\Entity\PhotoDto;
use Tests\Support\FakeCrawlerxClient;
use Tests\TestCase;

final class FetchPerformerAndGalleryJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_performer_listing_enqueues_detail_rows(): void
    {
        $source = $this->source('warashi');
        $row = CrawlQueue::factory()->create([
            'source_slug' => $source->slug,
            'url' => 'https://warashi-asian-pornstars.fr/en/s-2-2/female-pornstars/toutes/all/page/1',
            'kind' => CrawlQueue::KIND_PERFORMER_LISTING,
            'status' => CrawlQueue::STATUS_CLAIMED,
        ]);

        $this->fakeClient(
            listing: CrawlerxFetchResult::failure(CrawlerxClient::ERROR_PARSE_FAILED, 'unused'),
            detail: CrawlerxFetchResult::failure(CrawlerxClient::ERROR_PARSE_FAILED, 'unused'),
            performerListing: CrawlerxFetchResult::success(new CrawlListResultDto(
                url: $row->url,
                page: 1,
                entityType: 'performer',
                items: [
                    new CrawlItemResultDto(url: 'https://warashi.test/p/1', entityType: 'performer', meta: []),
                    new CrawlItemResultDto(url: 'https://warashi.test/p/2', entityType: 'performer', meta: []),
                ],
                pagination: new CrawlPaginationDto(currentPage: 1, lastPage: null, nextPage: 2, nextUrl: 'https://warashi.test/page/2', hasNextPage: true),
            )),
        );

        FetchPerformerListingJob::dispatch($row->id);

        $this->assertDatabaseHas('crawl_queue', [
            'url' => 'https://warashi.test/p/1',
            'kind' => CrawlQueue::KIND_PERFORMER_DETAIL,
        ]);
        $this->assertDatabaseHas('crawl_queue', [
            'url' => 'https://warashi.test/page/2',
            'kind' => CrawlQueue::KIND_PERFORMER_LISTING,
        ]);
        $this->assertDatabaseHas('crawl_queue', ['id' => $row->id, 'status' => CrawlQueue::STATUS_DONE]);
    }

    public function test_performer_listing_enqueues_items_by_next_crawl_type_hint(): void
    {
        $source = $this->source('xcity');
        $row = CrawlQueue::factory()->create([
            'source_slug' => $source->slug,
            'url' => 'https://xxx.xcity.jp/idol/',
            'kind' => CrawlQueue::KIND_PERFORMER_LISTING,
            'status' => CrawlQueue::STATUS_CLAIMED,
        ]);

        $this->fakeClient(
            listing: CrawlerxFetchResult::failure(CrawlerxClient::ERROR_PARSE_FAILED, 'unused'),
            detail: CrawlerxFetchResult::failure(CrawlerxClient::ERROR_PARSE_FAILED, 'unused'),
            performerListing: CrawlerxFetchResult::success(new CrawlListResultDto(
                url: $row->url,
                page: 1,
                entityType: 'performer',
                items: [
                    new CrawlItemResultDto(
                        url: 'https://xxx.xcity.jp/idol/?kana=%E3%81%82',
                        entityType: 'performer',
                        meta: [],
                        nextCrawlType: 'performer_listing',
                    ),
                    new CrawlItemResultDto(
                        url: 'https://xxx.xcity.jp/idol/detail/5517/',
                        entityType: 'performer',
                        meta: [],
                        nextCrawlType: 'performer_detail',
                    ),
                ],
                pagination: new CrawlPaginationDto(currentPage: 1, lastPage: null, nextPage: null, nextUrl: null, hasNextPage: false),
            )),
        );

        FetchPerformerListingJob::dispatch($row->id);

        $this->assertDatabaseHas('crawl_queue', ['url' => 'https://xxx.xcity.jp/idol/?kana=%E3%81%82', 'kind' => CrawlQueue::KIND_PERFORMER_LISTING]);
        $this->assertDatabaseHas('crawl_queue', ['url' => 'https://xxx.xcity.jp/idol/detail/5517/', 'kind' => CrawlQueue::KIND_PERFORMER_DETAIL]);
    }

    public function test_performer_detail_persists_rich_profile(): void
    {
        $source = $this->source('javdatabase');
        $row = CrawlQueue::factory()->create([
            'source_slug' => $source->slug,
            'url' => 'https://www.javdatabase.com/idols/airi-suzumura/',
            'kind' => CrawlQueue::KIND_PERFORMER_DETAIL,
            'status' => CrawlQueue::STATUS_CLAIMED,
        ]);

        $this->fakeClient(
            listing: CrawlerxFetchResult::failure(CrawlerxClient::ERROR_PARSE_FAILED, 'unused'),
            detail: CrawlerxFetchResult::failure(CrawlerxClient::ERROR_PARSE_FAILED, 'unused'),
            performerDetail: CrawlerxFetchResult::performer(PerformerDto::fromParsed(
                'airi-suzumura',
                'Airi Suzumura',
                [
                    'name_japanese' => '涼村あいり',
                    'profile_image_url' => 'https://cdn.test/airi.jpg',
                    'birth_date_raw' => '1992-09-04',
                    'height_raw' => '158 cm',
                    'size_raw' => 'B88(E) W58 H86',
                    'blood_type' => 'A',
                    'birthplace' => 'Tokyo',
                    'aliases' => ['AiriS'],
                    'tags' => ['slender'],
                    'raw_profile' => 'Bio text here',
                ],
                $row->url,
            )),
        );

        FetchPerformerDetailJob::dispatch($row->id);

        $this->assertDatabaseHas('performers', [
            'source_slug' => 'javdatabase',
            'external_id' => 'airi-suzumura',
            'name_romaji' => 'Airi Suzumura',
            'name_kanji' => '涼村あいり',
            'height_cm' => 158,
            'cup' => 'E',
            'blood_type' => 'A',
            'location' => 'Tokyo',
        ]);
        $this->assertDatabaseHas('performer_media', [
            'kind' => PerformerMedia::KIND_IMAGE,
            'url' => 'https://cdn.test/airi.jpg',
            'source_slug' => 'javdatabase',
        ]);
        $this->assertDatabaseHas('performer_aliases', ['alias' => 'AiriS']);
        $this->assertDatabaseHas('crawl_queue', ['id' => $row->id, 'status' => CrawlQueue::STATUS_DONE]);
    }

    public function test_performer_detail_normalization_failure_records_reason(): void
    {
        $source = $this->source('javdatabase');
        $row = CrawlQueue::factory()->create([
            'source_slug' => $source->slug,
            'url' => 'https://www.javdatabase.com/idols/unknown/',
            'kind' => CrawlQueue::KIND_PERFORMER_DETAIL,
            'status' => CrawlQueue::STATUS_CLAIMED,
        ]);

        $this->fakeClient(
            listing: CrawlerxFetchResult::failure(CrawlerxClient::ERROR_PARSE_FAILED, 'unused'),
            detail: CrawlerxFetchResult::failure(CrawlerxClient::ERROR_PARSE_FAILED, 'unused'),
            performerDetail: CrawlerxFetchResult::performer(PerformerDto::fromParsed(null, null, [], $row->url)),
        );

        FetchPerformerDetailJob::dispatch($row->id);

        $this->assertDatabaseHas('crawl_queue', [
            'id' => $row->id,
            'status' => CrawlQueue::STATUS_FAILED,
            'error_code' => CrawlErrorCode::NormalizationFailed->value,
            'last_error' => 'Performer normalization failed.',
        ]);
    }

    #[DataProvider('unknownSourceJobs')]
    public function test_unknown_source_failure_paths_record_reason(string $jobClass, string $kind): void
    {
        $row = CrawlQueue::factory()->create([
            'source_slug' => 'missing-source',
            'url' => 'https://missing-source.test/' . $kind,
            'kind' => $kind,
            'status' => CrawlQueue::STATUS_CLAIMED,
        ]);

        $this->fakeClient(
            listing: CrawlerxFetchResult::failure(CrawlerxClient::ERROR_PARSE_FAILED, 'unused'),
            detail: CrawlerxFetchResult::failure(CrawlerxClient::ERROR_PARSE_FAILED, 'unused'),
        );

        $jobClass::dispatch($row->id);

        $this->assertDatabaseHas('crawl_queue', [
            'id' => $row->id,
            'status' => CrawlQueue::STATUS_FAILED,
            'error_code' => CrawlErrorCode::UnknownSource->value,
            'last_error' => 'Unknown source slug.',
        ]);
    }

    /** @return array<string, array{class-string, string}> */
    public static function unknownSourceJobs(): array
    {
        return [
            'performer listing' => [FetchPerformerListingJob::class, CrawlQueue::KIND_PERFORMER_LISTING],
            'performer detail' => [FetchPerformerDetailJob::class, CrawlQueue::KIND_PERFORMER_DETAIL],
            'gallery' => [FetchGalleryJob::class, CrawlQueue::KIND_GALLERY],
        ];
    }

    public function test_gallery_attaches_media_when_movie_code_matches(): void
    {
        $source = $this->source('eporner');
        $movie = Movie::factory()->create([
            'display_code' => 'STAR-836',
            'code_normalized' => 'STAR836',
        ]);
        MovieCode::factory()->create([
            'movie_id' => $movie->id,
            'code' => 'STAR-836',
            'code_normalized' => 'STAR836',
            'kind' => MovieCode::KIND_DVD,
            'source_slug' => 'javdb',
        ]);

        $row = CrawlQueue::factory()->create([
            'source_slug' => $source->slug,
            'url' => 'https://www.eporner.com/gallery/xKeoFe7VHmO/Iori-Kogawa-STAR-836/',
            'kind' => CrawlQueue::KIND_GALLERY,
            'status' => CrawlQueue::STATUS_CLAIMED,
        ]);

        $this->fakeClient(
            listing: CrawlerxFetchResult::failure(CrawlerxClient::ERROR_PARSE_FAILED, 'unused'),
            detail: CrawlerxFetchResult::failure(CrawlerxClient::ERROR_PARSE_FAILED, 'unused'),
            gallery: CrawlerxFetchResult::gallery(new GalleryDto(
                externalId: 'xKeoFe7VHmO',
                title: 'Iori Kogawa STAR-836 Uncensored Leak',
                photoCount: 1,
                performers: ['Iori Kogawa'],
                photos: [
                    new PhotoDto(
                        id: '1',
                        url: 'https://eporner.test/photo/1',
                        imageUrl: 'https://eporner.test/full/1.jpg',
                        thumbnailUrl: 'https://eporner.test/thumb/1.jpg',
                        position: 1,
                    ),
                ],
            )),
        );

        FetchGalleryJob::dispatch($row->id);

        $this->assertDatabaseHas('movie_media', [
            'movie_id' => $movie->id,
            'kind' => MovieMedia::KIND_GALLERY,
            'url' => 'https://eporner.test/full/1.jpg',
            'source_slug' => 'eporner',
        ]);
        $this->assertSame(1, Performer::query()->where('source_slug', 'eporner')->where('name_romaji', 'Iori Kogawa')->count());
        $this->assertDatabaseHas('crawl_events', ['source_slug' => 'eporner', 'kind' => 'gallery_parsed']);
        $this->assertDatabaseHas('crawl_queue', ['id' => $row->id, 'status' => CrawlQueue::STATUS_DONE]);
    }

    private function source(string $slug): Source
    {
        return Source::factory()->create(['slug' => $slug]);
    }

    private function fakeClient(
        CrawlerxFetchResult $listing,
        CrawlerxFetchResult $detail,
        ?CrawlerxFetchResult $performerListing = null,
        ?CrawlerxFetchResult $performerDetail = null,
        ?CrawlerxFetchResult $gallery = null,
    ): void {
        $this->app->instance(CrawlerxClient::class, new FakeCrawlerxClient(
            $listing,
            $detail,
            $performerListing,
            $performerDetail,
            $gallery,
        ));
    }
}
