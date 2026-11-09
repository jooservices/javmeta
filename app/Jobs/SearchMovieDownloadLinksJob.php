<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Data\Crawl\MovieDraft;
use App\Models\Movie;
use App\Models\Source;
use App\Repositories\MovieRepository;
use App\Repositories\SourceRepository;
use App\Services\Crawl\MovieDraftSink;
use App\Services\Crawl\SourceActivityRecorder;
use App\Services\Crawl\SourceThrottle;
use App\Services\Crawler\CrawlerxClient;
use App\Services\Downloads\DownloadLinkSearchService;
use App\Services\Normalize\NormalizationFailedException;
use App\Services\Normalize\SourceNormalizerRegistry;
use App\Support\Downloads\DownloadLinkUrlResolver;
use App\Support\Downloads\DownloadSearchPacer;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * One on-demand download-link fetch for a single (movie, source) pair on the
 * dedicated low-priority download_search lane.
 */
final class SearchMovieDownloadLinksJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(
        public readonly int $movieId,
        public readonly string $sourceSlug,
    ) {
        $this->onQueue((string) config('javmeta_queues.download_search_queue', 'download_search'));
        $this->timeout = max(30, (int) config('javmeta_downloads.link_search_timeout_seconds', 45) + 30);
    }

    public function handle(
        MovieRepository $movies,
        SourceRepository $sources,
        CrawlerxClient $client,
        SourceNormalizerRegistry $registry,
        DownloadLinkUrlResolver $urls,
        DownloadLinkSearchService $searches,
        SourceThrottle $throttle,
        SourceActivityRecorder $activity,
        DownloadSearchPacer $pacer,
        MovieDraftSink $sink,
    ): void {
        $movie = $movies->modelQuery(Movie::class)->with('codes')->find($this->movieId);
        if (! $movie instanceof Movie) {
            return;
        }

        $source = $sources->findSource($this->sourceSlug);
        if (! $source instanceof Source || ! $source->enabled) {
            $searches->markSourceDone($this->movieId, $this->sourceSlug, 0, 'source_unavailable');

            return;
        }

        $url = $urls->resolve((int) $movie->id, (string) $movie->display_code, $this->sourceSlug);
        if ($url === null) {
            $searches->markSourceDone($this->movieId, $this->sourceSlug, 0);

            return;
        }

        $before = $searches->magnetCount($this->movieId);

        try {
            if (! app()->runningUnitTests() || (bool) config('javmeta_downloads.link_search_force_pacer', false)) {
                $busy = $pacer->beforeFetch(
                    $source,
                    $this->sourceSlug,
                    'download_search',
                    max(30, (int) config('javmeta_downloads.link_search_timeout_seconds', 45)),
                );
                if ($busy !== null) {
                    $searches->markSourceDone($this->movieId, $this->sourceSlug, 0, $busy);

                    return;
                }
            }

            $result = $client->fetchDetail($this->sourceSlug, $url);
            if (! $result->ok || $result->movie === null) {
                $activity->recordFailure($source, (string) ($result->errorMessage ?? $result->errorCode ?? 'fetch_failed'));
                $throttle->onFailure($source, $result->errorCode);
                $searches->markSourceDone($this->movieId, $this->sourceSlug, 0, $result->errorCode);

                return;
            }

            $normalizer = $registry->forSlug($this->sourceSlug);
            if ($normalizer === null) {
                $searches->markSourceDone($this->movieId, $this->sourceSlug, 0, 'no_normalizer');

                return;
            }

            try {
                $draft = $normalizer->normalizeMovie(
                    $result->movie,
                    $result->movie->metadata,
                    $this->sourceSlug,
                    $url,
                    CarbonImmutable::now(),
                );
            } catch (NormalizationFailedException $exception) {
                $activity->recordFailure($source, $exception->getMessage());
                $throttle->onFailure($source, 'normalization_failed');
                $searches->markSourceDone($this->movieId, $this->sourceSlug, 0, 'normalization_failed');

                return;
            }

            $this->persistMagnetsOnly($sink, $draft);
            $activity->recordSuccess($source);
            $throttle->onSuccess($source);

            $added = max(0, $searches->magnetCount($this->movieId) - $before);
            $searches->markSourceDone($this->movieId, $this->sourceSlug, $added);
        } catch (Throwable $exception) {
            $searches->markSourceDone($this->movieId, $this->sourceSlug, 0, mb_substr($exception->getMessage(), 0, 200));
        }
    }

    private function persistMagnetsOnly(MovieDraftSink $sink, MovieDraft $draft): void
    {
        // Reuse the persister path so magnets dedupe via updateOrCreate on (movie_id, kind, url).
        $sink->accept($draft, isRecrawl: true);
    }
}
