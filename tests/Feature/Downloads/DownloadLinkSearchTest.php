<?php

declare(strict_types=1);

namespace Tests\Feature\Downloads;

use App\Jobs\SearchMovieDownloadLinksJob;
use App\Models\Movie;
use App\Models\MovieCode;
use App\Models\MovieDownloadSearch;
use App\Models\MovieMedia;
use App\Models\Source;
use App\Services\Crawler\CrawlerxClient;
use App\Services\Crawler\CrawlerxFetchResult;
use App\Support\Downloads\DownloadSearchPacer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use JOOservices\CrawlerX\Dto\Entity\MovieDto;
use Tests\Support\FakeCrawlerxClient;
use Tests\TestCase;

final class DownloadLinkSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'javmeta.ui_login_required' => false,
            'javmeta_downloads.link_search_enabled' => true,
            'javmeta_downloads.link_search_max_sources_per_run' => 2,
            'javmeta_queues.download_search_queue' => 'download_search',
        ]);
    }

    public function test_guest_can_start_on_demand_search_and_payload_exposes_downloads_summary(): void
    {
        Bus::fake();
        $movie = $this->movie('GDL-001');
        Source::factory()->create(['slug' => 'onejav', 'enabled' => true, 'base_url' => 'https://onejav.com']);

        $detail = $this->getJson('/ui-api/movies/GDL-001')->assertOk();
        $detail->assertJsonPath('data.downloads.available', false);
        $detail->assertJsonPath('data.downloads.state', 'idle');
        $detail->assertJsonPath('data.downloads.count', 0);

        $this->postJson('/ui-api/movies/GDL-001/downloads/search')
            ->assertStatus(202)
            ->assertJsonPath('data.queued', true)
            ->assertJsonPath('data.downloads.state', 'searching');

        Bus::assertDispatched(SearchMovieDownloadLinksJob::class);
        self::assertSame(
            MovieDownloadSearch::STATE_SEARCHING,
            MovieDownloadSearch::query()->where('movie_id', $movie->id)->value('state'),
        );
    }

    public function test_job_persists_magnet_from_fake_source_without_duplicates(): void
    {
        Queue::fake();
        $movie = $this->movie('JOB-001');
        Source::factory()->create(['slug' => 'onejav', 'enabled' => true, 'base_url' => 'https://onejav.com']);
        MovieDownloadSearch::query()->create([
            'movie_id' => $movie->id,
            'state' => MovieDownloadSearch::STATE_SEARCHING,
            'found_count' => 0,
            'sources_queued' => ['onejav'],
            'sources_done' => [],
            'started_at' => now(),
        ]);

        $magnet = 'magnet:?xt=urn:btih:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        $client = new FakeCrawlerxClient(
            CrawlerxFetchResult::failure(CrawlerxClient::ERROR_PARSE_FAILED, 'unused'),
            CrawlerxFetchResult::movie(new MovieDto(
                externalId: 'job001',
                title: 'Job Fixture',
                code: 'JOB-001',
                metadata: [
                    'magnets' => [['url' => $magnet]],
                ],
            )),
        );
        $this->app->instance(CrawlerxClient::class, $client);

        $run = static function () use ($movie, $client): void {
            (new SearchMovieDownloadLinksJob((int) $movie->id, 'onejav'))->handle(
                app(\App\Repositories\MovieRepository::class),
                app(\App\Repositories\SourceRepository::class),
                $client,
                app(\App\Services\Normalize\SourceNormalizerRegistry::class),
                app(\App\Support\Downloads\DownloadLinkUrlResolver::class),
                app(\App\Services\Downloads\DownloadLinkSearchService::class),
                app(\App\Services\Crawl\SourceThrottle::class),
                app(\App\Services\Crawl\SourceActivityRecorder::class),
                app(DownloadSearchPacer::class),
                app(\App\Services\Crawl\MovieDraftSink::class),
            );
        };

        $run();

        self::assertSame(1, MovieMedia::query()->where('movie_id', $movie->id)->where('kind', MovieMedia::KIND_MAGNET)->count());
        self::assertSame($magnet, MovieMedia::query()->where('movie_id', $movie->id)->where('kind', MovieMedia::KIND_MAGNET)->value('url'));
        self::assertSame(MovieDownloadSearch::STATE_FOUND, MovieDownloadSearch::query()->where('movie_id', $movie->id)->value('state'));

        // Second run must not duplicate the magnet URL.
        MovieDownloadSearch::query()->where('movie_id', $movie->id)->update([
            'state' => MovieDownloadSearch::STATE_SEARCHING,
            'sources_queued' => ['onejav'],
            'sources_done' => [],
        ]);
        $run();
        self::assertSame(1, MovieMedia::query()->where('movie_id', $movie->id)->where('kind', MovieMedia::KIND_MAGNET)->count());
    }

    public function test_job_marks_source_done_when_fake_fetch_fails(): void
    {
        Queue::fake();
        $movie = $this->movie('JOB-002');
        Source::factory()->create(['slug' => 'onejav', 'enabled' => true, 'base_url' => 'https://onejav.com']);
        MovieDownloadSearch::query()->create([
            'movie_id' => $movie->id,
            'state' => MovieDownloadSearch::STATE_SEARCHING,
            'found_count' => 0,
            'sources_queued' => ['onejav'],
            'sources_done' => [],
            'started_at' => now(),
        ]);

        $client = new FakeCrawlerxClient(
            CrawlerxFetchResult::failure(CrawlerxClient::ERROR_TIMEOUT, 'timed out'),
            CrawlerxFetchResult::failure(CrawlerxClient::ERROR_TIMEOUT, 'timed out'),
        );
        $this->app->instance(CrawlerxClient::class, $client);

        (new SearchMovieDownloadLinksJob((int) $movie->id, 'onejav'))->handle(
            app(\App\Repositories\MovieRepository::class),
            app(\App\Repositories\SourceRepository::class),
            $client,
            app(\App\Services\Normalize\SourceNormalizerRegistry::class),
            app(\App\Support\Downloads\DownloadLinkUrlResolver::class),
            app(\App\Services\Downloads\DownloadLinkSearchService::class),
            app(\App\Services\Crawl\SourceThrottle::class),
            app(\App\Services\Crawl\SourceActivityRecorder::class),
            app(DownloadSearchPacer::class),
            app(\App\Services\Crawl\MovieDraftSink::class),
        );

        $search = MovieDownloadSearch::query()->where('movie_id', $movie->id)->first();
        self::assertSame(MovieDownloadSearch::STATE_NOT_FOUND, $search?->state);
        self::assertContains('onejav', $search?->sources_done ?? []);
        self::assertSame(0, MovieMedia::query()->where('movie_id', $movie->id)->where('kind', MovieMedia::KIND_MAGNET)->count());
    }

    public function test_job_exits_quietly_for_missing_movie_or_disabled_source(): void
    {
        Queue::fake();
        $movie = $this->movie('JOB-003');
        Source::factory()->create(['slug' => 'onejav', 'enabled' => false, 'base_url' => 'https://onejav.com']);
        MovieDownloadSearch::query()->create([
            'movie_id' => $movie->id,
            'state' => MovieDownloadSearch::STATE_SEARCHING,
            'found_count' => 0,
            'sources_queued' => ['onejav'],
            'sources_done' => [],
            'started_at' => now(),
        ]);

        $client = new FakeCrawlerxClient(
            CrawlerxFetchResult::failure(CrawlerxClient::ERROR_PARSE_FAILED, 'unused'),
            CrawlerxFetchResult::failure(CrawlerxClient::ERROR_PARSE_FAILED, 'unused'),
        );

        (new SearchMovieDownloadLinksJob(999999, 'onejav'))->handle(
            app(\App\Repositories\MovieRepository::class),
            app(\App\Repositories\SourceRepository::class),
            $client,
            app(\App\Services\Normalize\SourceNormalizerRegistry::class),
            app(\App\Support\Downloads\DownloadLinkUrlResolver::class),
            app(\App\Services\Downloads\DownloadLinkSearchService::class),
            app(\App\Services\Crawl\SourceThrottle::class),
            app(\App\Services\Crawl\SourceActivityRecorder::class),
            app(DownloadSearchPacer::class),
            app(\App\Services\Crawl\MovieDraftSink::class),
        );

        (new SearchMovieDownloadLinksJob((int) $movie->id, 'onejav'))->handle(
            app(\App\Repositories\MovieRepository::class),
            app(\App\Repositories\SourceRepository::class),
            $client,
            app(\App\Services\Normalize\SourceNormalizerRegistry::class),
            app(\App\Support\Downloads\DownloadLinkUrlResolver::class),
            app(\App\Services\Downloads\DownloadLinkSearchService::class),
            app(\App\Services\Crawl\SourceThrottle::class),
            app(\App\Services\Crawl\SourceActivityRecorder::class),
            app(DownloadSearchPacer::class),
            app(\App\Services\Crawl\MovieDraftSink::class),
        );

        self::assertContains('onejav', MovieDownloadSearch::query()->where('movie_id', $movie->id)->value('sources_done') ?? []);
    }

    public function test_job_skips_when_detail_url_cannot_be_resolved(): void
    {
        Queue::fake();
        $movie = $this->movie('JOB-004');
        Source::factory()->create(['slug' => 'javdb', 'enabled' => true, 'base_url' => 'https://javdb.com']);
        MovieDownloadSearch::query()->create([
            'movie_id' => $movie->id,
            'state' => MovieDownloadSearch::STATE_SEARCHING,
            'found_count' => 0,
            'sources_queued' => ['javdb'],
            'sources_done' => [],
            'started_at' => now(),
        ]);

        $client = new FakeCrawlerxClient(
            CrawlerxFetchResult::failure(CrawlerxClient::ERROR_PARSE_FAILED, 'unused'),
            CrawlerxFetchResult::failure(CrawlerxClient::ERROR_PARSE_FAILED, 'unused'),
        );

        (new SearchMovieDownloadLinksJob((int) $movie->id, 'javdb'))->handle(
            app(\App\Repositories\MovieRepository::class),
            app(\App\Repositories\SourceRepository::class),
            $client,
            app(\App\Services\Normalize\SourceNormalizerRegistry::class),
            app(\App\Support\Downloads\DownloadLinkUrlResolver::class),
            app(\App\Services\Downloads\DownloadLinkSearchService::class),
            app(\App\Services\Crawl\SourceThrottle::class),
            app(\App\Services\Crawl\SourceActivityRecorder::class),
            app(DownloadSearchPacer::class),
            app(\App\Services\Crawl\MovieDraftSink::class),
        );

        self::assertContains('javdb', MovieDownloadSearch::query()->where('movie_id', $movie->id)->value('sources_done') ?? []);
        self::assertSame(0, $client->calls);
    }

    public function test_search_endpoint_returns_cooldown_and_disabled_statuses(): void
    {
        $movie = $this->movie('API-001');
        MovieDownloadSearch::query()->create([
            'movie_id' => $movie->id,
            'state' => MovieDownloadSearch::STATE_NOT_FOUND,
            'found_count' => 0,
            'last_checked_at' => now()->subHour(),
            'cooldown_until' => now()->addHours(3),
        ]);

        $this->postJson('/ui-api/movies/API-001/downloads/search')
            ->assertStatus(429)
            ->assertJsonPath('data.reason', 'cooldown');

        config(['javmeta_downloads.link_search_enabled' => false]);
        $this->postJson('/ui-api/movies/API-001/downloads/search')
            ->assertStatus(403)
            ->assertJsonPath('data.reason', 'disabled');
    }

    public function test_job_handles_missing_normalizer_and_normalization_failure(): void
    {
        Queue::fake();
        $movie = $this->movie('JOB-005');
        Source::factory()->create(['slug' => 'onejav', 'enabled' => true, 'base_url' => 'https://onejav.com']);
        MovieDownloadSearch::query()->create([
            'movie_id' => $movie->id,
            'state' => MovieDownloadSearch::STATE_SEARCHING,
            'found_count' => 0,
            'sources_queued' => ['onejav'],
            'sources_done' => [],
            'started_at' => now(),
        ]);

        $dto = new MovieDto(externalId: 'job005', title: 'Norm', code: 'JOB-005', metadata: []);
        $client = new FakeCrawlerxClient(
            CrawlerxFetchResult::failure(CrawlerxClient::ERROR_PARSE_FAILED, 'unused'),
            CrawlerxFetchResult::movie($dto),
        );

        $emptyRegistry = new \App\Services\Normalize\SourceNormalizerRegistry([]);
        (new SearchMovieDownloadLinksJob((int) $movie->id, 'onejav'))->handle(
            app(\App\Repositories\MovieRepository::class),
            app(\App\Repositories\SourceRepository::class),
            $client,
            $emptyRegistry,
            app(\App\Support\Downloads\DownloadLinkUrlResolver::class),
            app(\App\Services\Downloads\DownloadLinkSearchService::class),
            app(\App\Services\Crawl\SourceThrottle::class),
            app(\App\Services\Crawl\SourceActivityRecorder::class),
            app(DownloadSearchPacer::class),
            app(\App\Services\Crawl\MovieDraftSink::class),
        );
        self::assertContains('onejav', MovieDownloadSearch::query()->where('movie_id', $movie->id)->value('sources_done') ?? []);

        MovieDownloadSearch::query()->where('movie_id', $movie->id)->update([
            'state' => MovieDownloadSearch::STATE_SEARCHING,
            'sources_queued' => ['onejav'],
            'sources_done' => [],
        ]);

        $failing = \Mockery::mock(\App\Services\Normalize\SourceNormalizer::class);
        $failing->shouldReceive('supports')->andReturn(true);
        $failing->shouldReceive('normalizeMovie')->andThrow(new \App\Services\Normalize\NormalizationFailedException('bad draft'));
        $registry = new \App\Services\Normalize\SourceNormalizerRegistry([$failing]);

        (new SearchMovieDownloadLinksJob((int) $movie->id, 'onejav'))->handle(
            app(\App\Repositories\MovieRepository::class),
            app(\App\Repositories\SourceRepository::class),
            $client,
            $registry,
            app(\App\Support\Downloads\DownloadLinkUrlResolver::class),
            app(\App\Services\Downloads\DownloadLinkSearchService::class),
            app(\App\Services\Crawl\SourceThrottle::class),
            app(\App\Services\Crawl\SourceActivityRecorder::class),
            app(DownloadSearchPacer::class),
            app(\App\Services\Crawl\MovieDraftSink::class),
        );

        $search = MovieDownloadSearch::query()->where('movie_id', $movie->id)->first();
        self::assertSame(MovieDownloadSearch::STATE_NOT_FOUND, $search?->state);
        self::assertContains('onejav', $search?->sources_done ?? []);
    }

    public function test_job_honours_pacer_busy_and_wait_when_forced(): void
    {
        Queue::fake();
        config(['javmeta_downloads.link_search_force_pacer' => true]);
        $movie = $this->movie('JOB-007');
        Source::factory()->create(['slug' => 'onejav', 'enabled' => true, 'base_url' => 'https://onejav.com']);
        MovieDownloadSearch::query()->create([
            'movie_id' => $movie->id,
            'state' => MovieDownloadSearch::STATE_SEARCHING,
            'found_count' => 0,
            'sources_queued' => ['onejav'],
            'sources_done' => [],
            'started_at' => now(),
        ]);

        $client = new FakeCrawlerxClient(
            CrawlerxFetchResult::failure(CrawlerxClient::ERROR_PARSE_FAILED, 'unused'),
            CrawlerxFetchResult::failure(CrawlerxClient::ERROR_PARSE_FAILED, 'unused'),
        );
        $busy = \Mockery::mock(DownloadSearchPacer::class);
        $busy->shouldReceive('beforeFetch')->once()->andReturn('source_busy');

        (new SearchMovieDownloadLinksJob((int) $movie->id, 'onejav'))->handle(
            app(\App\Repositories\MovieRepository::class),
            app(\App\Repositories\SourceRepository::class),
            $client,
            app(\App\Services\Normalize\SourceNormalizerRegistry::class),
            app(\App\Support\Downloads\DownloadLinkUrlResolver::class),
            app(\App\Services\Downloads\DownloadLinkSearchService::class),
            app(\App\Services\Crawl\SourceThrottle::class),
            app(\App\Services\Crawl\SourceActivityRecorder::class),
            $busy,
            app(\App\Services\Crawl\MovieDraftSink::class),
        );
        self::assertContains('onejav', MovieDownloadSearch::query()->where('movie_id', $movie->id)->value('sources_done') ?? []);

        MovieDownloadSearch::query()->where('movie_id', $movie->id)->update([
            'state' => MovieDownloadSearch::STATE_SEARCHING,
            'sources_queued' => ['onejav'],
            'sources_done' => [],
        ]);
        $ready = \Mockery::mock(DownloadSearchPacer::class);
        $ready->shouldReceive('beforeFetch')->once()->andReturn(null);

        (new SearchMovieDownloadLinksJob((int) $movie->id, 'onejav'))->handle(
            app(\App\Repositories\MovieRepository::class),
            app(\App\Repositories\SourceRepository::class),
            $client,
            app(\App\Services\Normalize\SourceNormalizerRegistry::class),
            app(\App\Support\Downloads\DownloadLinkUrlResolver::class),
            app(\App\Services\Downloads\DownloadLinkSearchService::class),
            app(\App\Services\Crawl\SourceThrottle::class),
            app(\App\Services\Crawl\SourceActivityRecorder::class),
            $ready,
            app(\App\Services\Crawl\MovieDraftSink::class),
        );
        self::assertContains('onejav', MovieDownloadSearch::query()->where('movie_id', $movie->id)->value('sources_done') ?? []);
    }

    public function test_job_records_source_error_when_client_throws(): void
    {
        Queue::fake();
        $movie = $this->movie('JOB-006');
        Source::factory()->create(['slug' => 'onejav', 'enabled' => true, 'base_url' => 'https://onejav.com']);
        MovieDownloadSearch::query()->create([
            'movie_id' => $movie->id,
            'state' => MovieDownloadSearch::STATE_SEARCHING,
            'found_count' => 0,
            'sources_queued' => ['onejav'],
            'sources_done' => [],
            'started_at' => now(),
        ]);

        $client = \Mockery::mock(CrawlerxClient::class);
        $client->shouldReceive('fetchDetail')->andThrow(new \RuntimeException('egress blocked'));

        (new SearchMovieDownloadLinksJob((int) $movie->id, 'onejav'))->handle(
            app(\App\Repositories\MovieRepository::class),
            app(\App\Repositories\SourceRepository::class),
            $client,
            app(\App\Services\Normalize\SourceNormalizerRegistry::class),
            app(\App\Support\Downloads\DownloadLinkUrlResolver::class),
            app(\App\Services\Downloads\DownloadLinkSearchService::class),
            app(\App\Services\Crawl\SourceThrottle::class),
            app(\App\Services\Crawl\SourceActivityRecorder::class),
            app(DownloadSearchPacer::class),
            app(\App\Services\Crawl\MovieDraftSink::class),
        );

        self::assertContains('onejav', MovieDownloadSearch::query()->where('movie_id', $movie->id)->value('sources_done') ?? []);
    }

    private function movie(string $code): Movie
    {
        $movie = Movie::factory()->create(['display_code' => $code]);
        MovieCode::factory()->create([
            'movie_id' => $movie->id,
            'code' => $code,
            'code_normalized' => strtoupper(str_replace('-', '', $code)),
            'kind' => MovieCode::KIND_DVD,
        ]);

        return $movie;
    }
}
