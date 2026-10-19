<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\CrawlErrorCode;
use App\Jobs\FetchDetailJob;
use App\Models\CrawlEvent;
use App\Models\CrawlQueue;
use App\Models\Source;
use App\Models\Movie;
use App\Services\Crawl\MovieDraftSink;
use App\Services\Crawler\CrawlerxClient;
use App\Services\Crawler\CrawlerxFetchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use JOOservices\CrawlerX\Dto\Entity\MovieDto;
use Tests\Support\FakeCrawlerxClient;
use Tests\Support\FakeMovieDraftSink;
use Tests\TestCase;

final class FetchDetailJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_success_delivers_draft_to_sink_and_marks_row_done(): void
    {
        $source = $this->source('onejav');
        $row = $this->detailRow($source);
        $row->forceFill([
            'error_code' => CrawlErrorCode::Network->value,
            'last_error' => 'Previous attempt failed.',
            'last_failed_at' => now()->subMinute(),
        ])->save();
        $sink = new FakeMovieDraftSink();
        $this->app->instance(MovieDraftSink::class, $sink);

        $this->fakeClient(CrawlerxFetchResult::movie($this->movie()));

        FetchDetailJob::dispatch($row->id);

        self::assertCount(1, $sink->drafts);
        $draft = $sink->drafts[0];
        self::assertSame('onejav', $draft->sourceSlug);
        self::assertSame('https://onejav.com/torrent/ymds282', $draft->sourceUrl);
        self::assertSame('YMDS-282', $draft->code);
        self::assertSame('Sample Title', $draft->titleJp);
        self::assertSame(120, $draft->runtimeMinutes);
        self::assertSame([['url' => 'https://onejav.com/dl/ymds282']], $draft->extras['magnets']);
        self::assertNotNull($draft->crawledAt);

        $this->assertDatabaseHas('crawl_queue', ['id' => $row->id, 'status' => CrawlQueue::STATUS_DONE]);
        self::assertNull($row->refresh()->error_code);
        self::assertNull($row->last_error);
        self::assertNull($row->last_failed_at);
        $this->assertNotNull(Source::query()->find('onejav')?->last_success_at);
        self::assertNotNull($source->refresh()->last_success_at);
        $this->assertSame(0, Movie::query()->count());
    }

    public function test_a_job_from_an_older_feed_is_skipped_after_the_row_was_claimed_again(): void
    {
        $source = $this->source('onejav');
        $row = $this->detailRow($source);
        $row->forceFill(['locked_by' => 'host:newer-feed'])->save();
        $sink = new FakeMovieDraftSink();
        $this->app->instance(MovieDraftSink::class, $sink);
        $this->fakeClient(CrawlerxFetchResult::movie($this->movie()));

        FetchDetailJob::dispatch($row->id, null, 'host:older-feed');

        self::assertSame([], $sink->drafts);
        $this->assertDatabaseHas('crawl_queue', ['id' => $row->id, 'status' => CrawlQueue::STATUS_CLAIMED, 'locked_by' => 'host:newer-feed']);
    }

    public function test_the_job_of_the_current_feed_runs_and_restarts_the_claim_clock(): void
    {
        $source = $this->source('onejav');
        $row = $this->detailRow($source);
        $row->forceFill(['locked_by' => 'host:feed', 'claimed_at' => now()->subMinutes(30)])->save();
        $sink = new FakeMovieDraftSink();
        $this->app->instance(MovieDraftSink::class, $sink);
        $this->fakeClient(CrawlerxFetchResult::movie($this->movie()));

        FetchDetailJob::dispatch($row->id, null, 'host:feed');

        self::assertCount(1, $sink->drafts);
        $this->assertDatabaseHas('crawl_queue', ['id' => $row->id, 'status' => CrawlQueue::STATUS_DONE]);
    }

    public function test_success_persists_movie_through_container_bound_sink(): void
    {
        $source = $this->source('onejav');
        $row = $this->detailRow($source);

        $this->fakeClient(CrawlerxFetchResult::movie($this->movie()));

        FetchDetailJob::dispatch($row->id);

        $this->assertSame(1, Movie::query()->count());
        $movie = Movie::query()->firstOrFail();
        $this->assertSame('YMDS282', $movie->code_normalized);
        $this->assertDatabaseHas('movie_observations', ['movie_id' => $movie->id, 'field' => 'title_jp', 'value' => 'Sample Title']);
        $this->assertDatabaseHas('movie_observations', ['movie_id' => $movie->id, 'field' => 'runtime', 'value' => '120']);
        $this->assertDatabaseHas('movie_codes', ['movie_id' => $movie->id, 'code_normalized' => 'YMDS282', 'kind' => 'dvd']);
        $this->assertDatabaseHas('movie_media', ['movie_id' => $movie->id, 'kind' => 'magnet', 'url' => 'https://onejav.com/dl/ymds282']);
        $this->assertDatabaseHas('crawl_queue', ['id' => $row->id, 'status' => CrawlQueue::STATUS_DONE]);
    }

    public function test_crawl_failure_never_creates_movie_and_retries_a_block_after_a_delay(): void
    {
        $source = $this->source('onejav');
        $row = $this->detailRow($source);
        $sink = new FakeMovieDraftSink();
        $this->app->instance(MovieDraftSink::class, $sink);

        $this->fakeClient(CrawlerxFetchResult::failure(CrawlerxClient::ERROR_BLOCKED, 'Blocked by site.'));

        FetchDetailJob::dispatch($row->id);

        self::assertSame([], $sink->drafts);
        $this->assertSame(0, Movie::query()->count());
        $this->assertDatabaseHas('crawl_events', ['source_slug' => 'onejav', 'kind' => CrawlEvent::KIND_BLOCKED]);
        $this->assertDatabaseHas('crawl_queue', [
            'id' => $row->id,
            'status' => CrawlQueue::STATUS_PENDING,
            'attempts' => 1,
            'error_code' => CrawlErrorCode::Blocked->value,
            'last_error' => 'Blocked by site.',
        ]);
        self::assertSame(now()->addSeconds(60)->getTimestamp(), $row->refresh()->next_attempt_at?->getTimestamp());
        $this->assertNotNull(Source::query()->find('onejav')?->last_error_at);
    }

    public function test_normalization_failure_records_parse_drift_and_never_delivers_draft(): void
    {
        $source = $this->source('onejav');
        $row = CrawlQueue::factory()->create([
            'source_slug' => $source->slug,
            'url' => 'https://onejav.com/torrent/no-extractable-code',
            'kind' => CrawlQueue::KIND_DETAIL,
            'status' => CrawlQueue::STATUS_CLAIMED,
            'max_attempts' => 3,
        ]);
        $sink = new FakeMovieDraftSink();
        $this->app->instance(MovieDraftSink::class, $sink);

        $movie = new MovieDto(title: 'No Code Here');
        $this->fakeClient(CrawlerxFetchResult::movie($movie));

        FetchDetailJob::dispatch($row->id);

        self::assertSame([], $sink->drafts);
        $this->assertSame(0, Movie::query()->count());
        $this->assertDatabaseHas('crawl_events', ['source_slug' => 'onejav', 'kind' => CrawlEvent::KIND_PARSE_DRIFT]);
        $this->assertDatabaseHas('crawl_queue', ['id' => $row->id, 'status' => CrawlQueue::STATUS_FAILED, 'error_code' => CrawlErrorCode::NormalizationFailed->value]);
        $this->assertNotNull(Source::query()->find('onejav')?->last_error_at);
    }

    public function test_missing_normalizer_fails_row_with_parse_drift(): void
    {
        $source = Source::factory()->create(['slug' => 'mystery', 'base_url' => 'https://mystery.test']);
        $row = CrawlQueue::factory()->create([
            'source_slug' => 'mystery',
            'url' => 'https://mystery.test/v/1',
            'kind' => CrawlQueue::KIND_DETAIL,
            'status' => CrawlQueue::STATUS_CLAIMED,
            'max_attempts' => 3,
        ]);

        $this->fakeClient(CrawlerxFetchResult::movie($this->movie()));

        FetchDetailJob::dispatch($row->id);

        $this->assertDatabaseHas('crawl_events', ['source_slug' => 'mystery', 'kind' => CrawlEvent::KIND_PARSE_DRIFT]);
        $this->assertDatabaseHas('crawl_queue', ['id' => $row->id, 'status' => CrawlQueue::STATUS_FAILED, 'error_code' => CrawlErrorCode::NormalizerMissing->value]);
        $this->assertNotNull(Source::query()->find('mystery')?->last_error_at);
    }

    public function test_unknown_source_slug_fails_row_without_throttle_or_circuit_touch(): void
    {
        $row = CrawlQueue::factory()->create([
            'source_slug' => 'ghost',
            'url' => 'https://ghost.test/v/1',
            'kind' => CrawlQueue::KIND_DETAIL,
            'status' => CrawlQueue::STATUS_CLAIMED,
        ]);

        $this->fakeClient(CrawlerxFetchResult::movie($this->movie()));

        FetchDetailJob::dispatch($row->id);

        $this->assertDatabaseHas('crawl_events', ['source_slug' => 'ghost', 'kind' => CrawlEvent::KIND_PARSE_DRIFT]);
        $this->assertDatabaseHas('crawl_queue', ['id' => $row->id, 'status' => CrawlQueue::STATUS_FAILED, 'error_code' => CrawlErrorCode::UnknownSource->value]);
        $this->assertDatabaseMissing('sources', ['slug' => 'ghost']);
    }

    public function test_rate_limited_with_retry_after_reschedules_the_row(): void
    {
        $this->freezeSecond();
        $row = $this->detailRow($this->source('onejav'));
        $message = fake()->sentence();

        $this->fakeClient(CrawlerxFetchResult::failure(CrawlerxClient::ERROR_RATE_LIMITED, $message, retryable: true, retryAfterSeconds: 60));

        FetchDetailJob::dispatch($row->id);

        $row->refresh();
        self::assertSame(CrawlQueue::STATUS_PENDING, $row->status);
        self::assertSame(1, $row->attempts);
        self::assertSame($message, $row->last_error);
        self::assertSame(CrawlErrorCode::RateLimited->value, $row->error_code);
        self::assertNotNull($row->last_failed_at);
        self::assertNull($row->claimed_at);
        self::assertSame(now()->addSeconds(60)->getTimestamp(), $row->next_attempt_at?->getTimestamp());
        $this->assertDatabaseHas('crawl_events', ['source_slug' => 'onejav', 'kind' => CrawlEvent::KIND_BLOCKED]);
    }

    public function test_retryable_failure_without_hint_uses_backoff_by_attempt(): void
    {
        $this->freezeSecond();
        $row = $this->detailRow($this->source('onejav'));
        $row->forceFill(['attempts' => 2])->save();

        $this->fakeClient(CrawlerxFetchResult::failure(CrawlerxClient::ERROR_NETWORK, fake()->sentence(), retryable: true));

        FetchDetailJob::dispatch($row->id);

        $row->refresh();
        self::assertSame(CrawlQueue::STATUS_PENDING, $row->status);
        self::assertSame(2, $row->attempts);
        self::assertSame(CrawlErrorCode::Network->value, $row->error_code);
        self::assertSame(now()->addSeconds(300)->getTimestamp(), $row->next_attempt_at?->getTimestamp());
    }

    public function test_retryable_failure_at_max_attempts_fails_the_row(): void
    {
        $row = $this->detailRow($this->source('onejav'));
        $row->forceFill(['attempts' => 3])->save();

        $this->fakeClient(CrawlerxFetchResult::failure(CrawlerxClient::ERROR_NETWORK, fake()->sentence(), retryable: true));

        FetchDetailJob::dispatch($row->id);

        $this->assertDatabaseHas('crawl_queue', ['id' => $row->id, 'status' => CrawlQueue::STATUS_FAILED, 'attempts' => 3, 'error_code' => CrawlErrorCode::Network->value]);
    }

    public function test_not_found_fails_the_row_without_retry(): void
    {
        $row = $this->detailRow($this->source('onejav'));

        $this->fakeClient(CrawlerxFetchResult::failure(CrawlerxClient::ERROR_NOT_FOUND, fake()->sentence()));

        FetchDetailJob::dispatch($row->id);

        $this->assertDatabaseHas('crawl_queue', ['id' => $row->id, 'status' => CrawlQueue::STATUS_FAILED, 'attempts' => 1, 'next_attempt_at' => null, 'error_code' => CrawlErrorCode::NotFound->value]);
        $this->assertDatabaseHas('crawl_events', ['source_slug' => 'onejav', 'kind' => CrawlEvent::KIND_SOFT404]);
    }

    public function test_ssrf_blocked_fails_the_row_with_a_blocked_event(): void
    {
        $row = $this->detailRow($this->source('onejav'));

        $this->fakeClient(CrawlerxFetchResult::failure(CrawlerxClient::ERROR_SSRF_BLOCKED, fake()->sentence()));

        FetchDetailJob::dispatch($row->id);

        $this->assertDatabaseHas('crawl_queue', ['id' => $row->id, 'status' => CrawlQueue::STATUS_FAILED, 'error_code' => CrawlErrorCode::SsrfBlocked->value]);
        $event = CrawlEvent::query()->where('source_slug', 'onejav')->sole();
        self::assertSame(CrawlEvent::KIND_BLOCKED, $event->kind);
        self::assertSame(CrawlerxClient::ERROR_SSRF_BLOCKED, $event->detail['error_code'] ?? null);
    }

    public function test_auth_required_records_an_auth_event(): void
    {
        $row = $this->detailRow($this->source('onejav'));

        $this->fakeClient(CrawlerxFetchResult::failure(CrawlerxClient::ERROR_AUTH_REQUIRED, fake()->sentence()));

        FetchDetailJob::dispatch($row->id);

        $this->assertDatabaseHas('crawl_queue', ['id' => $row->id, 'status' => CrawlQueue::STATUS_FAILED]);
        $this->assertDatabaseHas('crawl_events', ['source_slug' => 'onejav', 'kind' => CrawlEvent::KIND_AUTH]);
    }

    public function test_unknown_error_code_is_parse_drift(): void
    {
        $row = $this->detailRow($this->source('onejav'));

        $this->fakeClient(CrawlerxFetchResult::failure(CrawlerxClient::ERROR_UNKNOWN, fake()->sentence()));

        FetchDetailJob::dispatch($row->id);

        $this->assertDatabaseHas('crawl_events', ['source_slug' => 'onejav', 'kind' => CrawlEvent::KIND_PARSE_DRIFT]);
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

    private function detailRow(Source $source): CrawlQueue
    {
        return CrawlQueue::factory()->create([
            'source_slug' => $source->slug,
            'url' => 'https://onejav.com/torrent/ymds282',
            'kind' => CrawlQueue::KIND_DETAIL,
            'status' => CrawlQueue::STATUS_CLAIMED,
            'attempts' => 1,
            'max_attempts' => 3,
        ]);
    }

    private function movie(): MovieDto
    {
        return new MovieDto(
            externalId: 'ymds282',
            title: 'Sample Title',
            code: 'YMDS-282',
            coverUrl: 'https://onejav.com/cover.jpg',
            date: '2024-05-15',
            duration: 120,
            performers: [],
            tags: ['Drama'],
            metadata: ['download_url' => 'https://onejav.com/dl/ymds282'],
        );
    }

    private function fakeClient(CrawlerxFetchResult $detail): void
    {
        $this->app->instance(
            CrawlerxClient::class,
            new FakeCrawlerxClient(CrawlerxFetchResult::failure(CrawlerxClient::ERROR_PARSE_FAILED, 'unused'), $detail),
        );
    }
}
