<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\FetchListingJob;
use App\Jobs\FetchDetailJob;
use App\Jobs\FetchGalleryJob;
use App\Jobs\FetchPerformerListingJob;
use App\Jobs\FetchPerformerDetailJob;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Models\CrawlQueue;
use App\Models\Source;
use App\Services\Crawler\CrawlerxClient;
use App\Services\Crawler\CrawlerxFetchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use JOOservices\CrawlerX\Dto\CrawlListResultDto;
use JOOservices\CrawlerX\Dto\CrawlPaginationDto;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeCrawlerxClient;
use Tests\TestCase;

final class FetchAttemptTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{class-string, string}> */
    public static function jobs(): iterable
    {
        yield 'listing' => [FetchListingJob::class, CrawlQueue::KIND_LISTING];
        yield 'detail' => [FetchDetailJob::class, CrawlQueue::KIND_DETAIL];
        yield 'performer listing' => [FetchPerformerListingJob::class, CrawlQueue::KIND_PERFORMER_LISTING];
        yield 'performer detail' => [FetchPerformerDetailJob::class, CrawlQueue::KIND_PERFORMER_DETAIL];
        yield 'gallery' => [FetchGalleryJob::class, CrawlQueue::KIND_GALLERY];
    }

    #[DataProvider('jobs')]
    public function test_failed_job_persists_each_attempt_without_raw_url(string $job, string $kind): void
    {
        Source::factory()->create(['slug' => 'onejav']);
        $url = 'https://onejav.com/new?token=private';
        $row = CrawlQueue::factory()->create(['source_slug' => 'onejav', 'url' => $url, 'kind' => $kind, 'status' => CrawlQueue::STATUS_CLAIMED]);
        $result = CrawlerxFetchResult::failure('challenge', 'All methods exhausted', attempts: [
            ['method' => 'http', 'started_at' => now()->toIso8601String(), 'duration_ms' => 32, 'http_status' => 403, 'cf_mitigated' => 'challenge', 'outcome' => 'error', 'error' => "Failed {$url}", 'error_code' => 'challenge'],
            ['method' => 'playwright', 'started_at' => now()->toIso8601String(), 'duration_ms' => 0, 'http_status' => null, 'cf_mitigated' => null, 'outcome' => 'skipped', 'error' => 'fetch budget exhausted'],
        ]);
        $this->app->instance(CrawlerxClient::class, new FakeCrawlerxClient($result, $result, $result, $result, $result));
        $job::dispatchSync($row->id);

        $this->assertDatabaseCount('crawl_fetch_attempts', 2);
        $this->assertDatabaseHas('crawl_fetch_attempts', ['crawl_queue_id' => $row->id, 'url_hash' => hash('sha256', $url), 'method' => 'http', 'http_status' => 403, 'cf_mitigated' => 'challenge', 'outcome' => 'error']);
        $this->assertDatabaseHas('crawl_fetch_attempts', ['method' => 'playwright', 'outcome' => 'skipped']);
        self::assertStringNotContainsString($url, json_encode(DB::table('crawl_fetch_attempts')->get(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('private', json_encode(DB::table('crawl_fetch_attempts')->get(), JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function malformedAttempts(): iterable
    {
        yield 'missing method' => [['method' => null]];
        yield 'invalid method' => [['method' => []]];
        yield 'invalid timestamp' => [['started_at' => 'invalid timestamp']];
        yield 'invalid outcome' => [['outcome' => 'unexpected']];
        yield 'invalid duration' => [['duration_ms' => 'invalid']];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('malformedAttempts')]
    public function test_malformed_diagnostics_do_not_change_failed_job_outcome(array $overrides): void
    {
        Source::factory()->create(['slug' => 'onejav']);
        $row = CrawlQueue::factory()->create(['source_slug' => 'onejav', 'kind' => CrawlQueue::KIND_LISTING, 'status' => CrawlQueue::STATUS_CLAIMED]);
        $attempt = array_replace(['method' => 'http', 'started_at' => now()->toIso8601String(), 'duration_ms' => 1, 'outcome' => 'error'], $overrides);
        $result = CrawlerxFetchResult::failure('network', 'failure', attempts: [$attempt]);
        $this->app->instance(CrawlerxClient::class, new FakeCrawlerxClient($result, $result));
        FetchListingJob::dispatchSync($row->id);
        $this->assertDatabaseCount('crawl_fetch_attempts', 0);
        $this->assertDatabaseHas('crawl_queue', ['id' => $row->id, 'status' => CrawlQueue::STATUS_FAILED, 'error_code' => 'network']);
    }

    public function test_malformed_optional_fields_are_normalized_to_null(): void
    {
        Source::factory()->create(['slug' => 'onejav']);
        $row = CrawlQueue::factory()->create(['source_slug' => 'onejav', 'kind' => CrawlQueue::KIND_LISTING, 'status' => CrawlQueue::STATUS_CLAIMED]);
        $result = CrawlerxFetchResult::failure('network', 'failure', attempts: [
            ['method' => 'http', 'started_at' => now()->toIso8601String(), 'duration_ms' => 1, 'outcome' => 'error', 'http_status' => [], 'cf_mitigated' => [], 'error' => [], 'error_code' => []],
        ]);
        $this->app->instance(CrawlerxClient::class, new FakeCrawlerxClient($result, $result));
        FetchListingJob::dispatchSync($row->id);
        $this->assertDatabaseCount('crawl_fetch_attempts', 1);
        $this->assertDatabaseHas('crawl_fetch_attempts', ['http_status' => null, 'cf_mitigated' => null, 'error' => null, 'error_code' => null]);
    }

    public function test_successful_listing_keeps_attempts_and_truncates_error_text(): void
    {
        Source::factory()->create(['slug' => 'onejav']);
        $row = CrawlQueue::factory()->create(['source_slug' => 'onejav', 'kind' => CrawlQueue::KIND_LISTING, 'status' => CrawlQueue::STATUS_CLAIMED]);
        $attempts = [
            ['method' => 'http', 'started_at' => now()->toIso8601String(), 'duration_ms' => 2, 'http_status' => 403, 'outcome' => 'error', 'error' => str_repeat('あ', 600)],
            ['method' => 'flaresolverr', 'started_at' => now()->toIso8601String(), 'duration_ms' => 5, 'http_status' => 200, 'outcome' => 'ok', 'error' => null],
        ];
        $result = CrawlerxFetchResult::success(new CrawlListResultDto(
            url: $row->url,
            page: 1,
            entityType: 'movie',
            items: [],
            pagination: new CrawlPaginationDto(currentPage: 1, lastPage: 1, nextPage: null, nextUrl: null, hasNextPage: false),
        ))->withAttempts($attempts);
        self::assertTrue($result->ok);
        self::assertSame($attempts, $result->attempts);
        $this->app->instance(CrawlerxClient::class, new FakeCrawlerxClient($result, $result));
        FetchListingJob::dispatchSync($row->id);
        $this->assertDatabaseCount('crawl_fetch_attempts', 2);
        $this->assertDatabaseHas('crawl_fetch_attempts', ['method' => 'flaresolverr', 'outcome' => 'ok', 'error' => null]);
        self::assertSame(500, mb_strlen(DB::table('crawl_fetch_attempts')->where('method', 'http')->value('error')));
    }

    public function test_old_release_without_diagnostic_fields_is_ignored(): void
    {
        Source::factory()->create(['slug' => 'onejav']);
        $row = CrawlQueue::factory()->create(['source_slug' => 'onejav', 'kind' => CrawlQueue::KIND_LISTING, 'status' => CrawlQueue::STATUS_CLAIMED]);
        $result = CrawlerxFetchResult::failure('network', 'failure', attempts: [['method' => 'http', 'status' => 0, 'ok' => false]]);
        $this->app->instance(CrawlerxClient::class, new FakeCrawlerxClient($result, $result));
        FetchListingJob::dispatchSync($row->id);
        $this->assertDatabaseCount('crawl_fetch_attempts', 0);
    }
}
