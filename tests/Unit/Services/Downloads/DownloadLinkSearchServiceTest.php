<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Downloads;

use App\Jobs\SearchMovieDownloadLinksJob;
use App\Models\Movie;
use App\Models\MovieCode;
use App\Models\MovieDownloadSearch;
use App\Models\MovieMedia;
use App\Models\Source;
use App\Services\Downloads\DownloadLinkSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

final class DownloadLinkSearchServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'javmeta_downloads.link_search_enabled' => true,
            'javmeta_downloads.link_search_cooldown_hours' => 6,
            'javmeta_downloads.link_search_global_budget_per_hour' => 30,
            'javmeta_downloads.link_search_per_source_budget_per_hour' => 10,
            'javmeta_downloads.link_search_client_budget_per_hour' => 10,
            'javmeta_downloads.link_search_max_sources_per_run' => 3,
        ]);
        RateLimiter::clear('javmeta:download-search:global');
    }

    public function test_summary_reports_existing_magnets_without_a_search_row(): void
    {
        $movie = $this->movie('SUM-001');
        MovieMedia::factory()->create([
            'movie_id' => $movie->id,
            'kind' => MovieMedia::KIND_MAGNET,
            'url' => 'magnet:?xt=urn:btih:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        ]);

        $summary = app(DownloadLinkSearchService::class)->summarize((int) $movie->id);

        self::assertTrue($summary->available);
        self::assertSame(1, $summary->count);
        self::assertSame(MovieDownloadSearch::STATE_FOUND, $summary->state);
    }

    public function test_start_is_idempotent_while_searching(): void
    {
        Bus::fake();
        $movie = $this->movie('IDL-001');
        Source::factory()->create(['slug' => 'onejav', 'enabled' => true, 'base_url' => 'https://onejav.com']);

        $service = app(DownloadLinkSearchService::class);
        $first = $service->start((string) $movie->display_code, 'client-a');
        $second = $service->start((string) $movie->display_code, 'client-a');

        self::assertTrue($first->queued);
        self::assertFalse($second->queued);
        self::assertSame('already_running', $second->reason);
        Bus::assertDispatchedTimes(SearchMovieDownloadLinksJob::class, 1);
    }

    public function test_start_respects_cooldown_after_not_found(): void
    {
        Bus::fake();
        $movie = $this->movie('CDW-001');
        MovieDownloadSearch::query()->create([
            'movie_id' => $movie->id,
            'state' => MovieDownloadSearch::STATE_NOT_FOUND,
            'found_count' => 0,
            'last_checked_at' => now()->subHour(),
            'cooldown_until' => now()->addHours(5),
        ]);

        $result = app(DownloadLinkSearchService::class)->start((string) $movie->display_code, 'client-b');

        self::assertFalse($result->queued);
        self::assertSame('cooldown', $result->reason);
        Bus::assertNothingDispatched();
    }

    public function test_start_queues_download_capable_sources_only(): void
    {
        Bus::fake();
        $movie = $this->movie('SRC-001');
        Source::factory()->create(['slug' => 'onejav', 'enabled' => true, 'base_url' => 'https://onejav.com', 'priority' => 1]);
        Source::factory()->create(['slug' => 'missav', 'enabled' => true, 'base_url' => 'https://missav.ws', 'priority' => 2]);
        Source::factory()->create(['slug' => 'javbtc', 'enabled' => true, 'base_url' => 'https://javbtc.com', 'priority' => 3]);

        $result = app(DownloadLinkSearchService::class)->start((string) $movie->display_code, 'client-c');

        self::assertTrue($result->queued);
        $search = MovieDownloadSearch::query()->where('movie_id', $movie->id)->first();
        self::assertSame(['onejav'], $search?->sources_queued);
        Bus::assertDispatched(SearchMovieDownloadLinksJob::class, static function (SearchMovieDownloadLinksJob $job) use ($movie): bool {
            return $job->movieId === (int) $movie->id && $job->sourceSlug === 'onejav';
        });
    }

    public function test_start_rejects_when_global_budget_is_exhausted(): void
    {
        Bus::fake();
        $movie = $this->movie('BDG-001');
        Source::factory()->create(['slug' => 'onejav', 'enabled' => true, 'base_url' => 'https://onejav.com']);
        config(['javmeta_downloads.link_search_global_budget_per_hour' => 1]);
        RateLimiter::hit('javmeta:download-search:global', 3600);

        $result = app(DownloadLinkSearchService::class)->start((string) $movie->display_code, 'client-budget');

        self::assertFalse($result->queued);
        self::assertSame('budget', $result->reason);
        Bus::assertNothingDispatched();
    }

    public function test_start_returns_disabled_when_feature_flag_is_off(): void
    {
        Bus::fake();
        config(['javmeta_downloads.link_search_enabled' => false]);
        $movie = $this->movie('OFF-001');

        $result = app(DownloadLinkSearchService::class)->start((string) $movie->display_code, 'client-off');

        self::assertFalse($result->queued);
        self::assertSame('disabled', $result->reason);
        Bus::assertNothingDispatched();
    }

    public function test_mark_source_done_chains_next_source_then_finishes_not_found(): void
    {
        Bus::fake();
        $movie = $this->movie('CHN-001');
        MovieDownloadSearch::query()->create([
            'movie_id' => $movie->id,
            'state' => MovieDownloadSearch::STATE_SEARCHING,
            'found_count' => 0,
            'sources_queued' => ['onejav', '141jav'],
            'sources_done' => [],
            'started_at' => now(),
        ]);

        $service = app(DownloadLinkSearchService::class);
        $service->markSourceDone((int) $movie->id, 'onejav', 0);
        Bus::assertDispatched(SearchMovieDownloadLinksJob::class, static function (SearchMovieDownloadLinksJob $job) use ($movie): bool {
            return $job->movieId === (int) $movie->id && $job->sourceSlug === '141jav';
        });

        $service->markSourceDone((int) $movie->id, '141jav', 0);
        $search = MovieDownloadSearch::query()->where('movie_id', $movie->id)->first();
        self::assertSame(MovieDownloadSearch::STATE_NOT_FOUND, $search?->state);
        self::assertNotNull($search?->cooldown_until);
    }

    public function test_mark_failed_sets_failed_state_and_cooldown(): void
    {
        $movie = $this->movie('FLD-001');
        MovieDownloadSearch::query()->create([
            'movie_id' => $movie->id,
            'state' => MovieDownloadSearch::STATE_SEARCHING,
            'found_count' => 0,
            'sources_queued' => ['onejav'],
            'sources_done' => [],
            'started_at' => now(),
        ]);

        app(DownloadLinkSearchService::class)->markFailed((int) $movie->id, 'boom');

        $search = MovieDownloadSearch::query()->where('movie_id', $movie->id)->first();
        self::assertSame(MovieDownloadSearch::STATE_FAILED, $search?->state);
        self::assertSame('boom', $search?->last_error);
        self::assertNotNull($search?->cooldown_until);
    }

    public function test_start_marks_not_found_when_no_download_sources_resolve(): void
    {
        Bus::fake();
        $movie = $this->movie('NOS-001');
        // Capable slug with no buildable detail URL and no movie_sources row.
        Source::factory()->create(['slug' => 'javdb', 'enabled' => true, 'base_url' => 'https://javdb.com']);

        $result = app(DownloadLinkSearchService::class)->start((string) $movie->display_code, 'client-nos');

        self::assertFalse($result->queued);
        self::assertSame('no_sources', $result->reason);
        self::assertSame(MovieDownloadSearch::STATE_NOT_FOUND, $result->summary->state);
        Bus::assertNothingDispatched();
    }

    public function test_mark_source_done_finishes_found_when_magnets_exist(): void
    {
        Bus::fake();
        $movie = $this->movie('FND-001');
        MovieMedia::factory()->create([
            'movie_id' => $movie->id,
            'kind' => MovieMedia::KIND_MAGNET,
            'url' => 'magnet:?xt=urn:btih:cccccccccccccccccccccccccccccccccccccccc',
        ]);
        MovieDownloadSearch::query()->create([
            'movie_id' => $movie->id,
            'state' => MovieDownloadSearch::STATE_SEARCHING,
            'found_count' => 0,
            'sources_queued' => ['onejav'],
            'sources_done' => [],
            'started_at' => now(),
        ]);

        app(DownloadLinkSearchService::class)->markSourceDone((int) $movie->id, 'onejav', 1);

        $search = MovieDownloadSearch::query()->where('movie_id', $movie->id)->first();
        self::assertSame(MovieDownloadSearch::STATE_FOUND, $search?->state);
        self::assertSame(1, $search?->found_count);
        self::assertNull($search?->cooldown_until);
    }

    public function test_summary_data_idle_helper_and_to_array(): void
    {
        $idle = \App\Data\Downloads\DownloadSummaryData::idle(0);
        $found = \App\Data\Downloads\DownloadSummaryData::idle(2, false);

        self::assertSame(MovieDownloadSearch::STATE_IDLE, $idle->state);
        self::assertSame(MovieDownloadSearch::STATE_FOUND, $found->state);
        self::assertFalse($found->search_enabled);
        self::assertSame([
            'available' => true,
            'count' => 2,
            'last_checked_at' => null,
            'state' => MovieDownloadSearch::STATE_FOUND,
            'cooldown_until' => null,
            'search_enabled' => false,
        ], $found->toArray());
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
