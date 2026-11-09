<?php

declare(strict_types=1);

namespace App\Services\Downloads;

use App\Data\Downloads\DownloadSearchStartData;
use App\Data\Downloads\DownloadSummaryData;
use App\Events\DownloadLinkSearchUpdated;
use App\Exceptions\MovieNotFoundException;
use App\Jobs\SearchMovieDownloadLinksJob;
use App\Repositories\MovieDownloadSearchRepository;
use App\Repositories\MovieRepository;
use App\Repositories\SourceRepository;
use App\Support\Downloads\DownloadCapableSources;
use App\Support\Downloads\DownloadLinkUrlResolver;
use App\Support\Downloads\DownloadSearchState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

final class DownloadLinkSearchService
{
    public function __construct(
        private readonly MovieRepository $movies,
        private readonly MovieDownloadSearchRepository $searches,
        private readonly SourceRepository $sources,
        private readonly DownloadCapableSources $capable,
        private readonly DownloadLinkUrlResolver $urls,
    ) {}

    public function searchEnabled(): bool
    {
        return (bool) config('javmeta_downloads.link_search_enabled', true);
    }

    public function summarize(int $movieId): DownloadSummaryData
    {
        $count = $this->magnetCount($movieId);
        $search = $this->searches->findForMovie($movieId);
        $state = $search?->state;
        if ($state === null || $state === DownloadSearchState::IDLE->value) {
            $state = $count > 0 ? DownloadSearchState::FOUND->value : DownloadSearchState::IDLE->value;
        }

        return new DownloadSummaryData(
            available: $count > 0,
            count: $count,
            last_checked_at: $search?->last_checked_at,
            state: $state,
            cooldown_until: $search?->cooldown_until,
            search_enabled: $this->searchEnabled(),
        );
    }

    /** Start an on-demand search. Repeated requests share one movie search. */
    public function start(string $movieCode, string $clientKey): DownloadSearchStartData
    {
        $movieId = $this->movies->downloadSearchIdByCode($movieCode);
        if ($movieId === null) {
            throw new MovieNotFoundException($movieCode);
        }

        $blocked = $this->startBlockReason($movieId, $clientKey);
        if ($blocked !== null) {
            return $this->startResult($movieId, false, $blocked);
        }

        $sources = $this->sourcesForMovie($movieId);
        if ($sources === []) {
            return $this->finishWithoutSources($movieId);
        }

        $lock = Cache::lock('javmeta:download-search:movie:' . $movieId, 30);
        if (! $lock->get()) {
            return $this->startResult($movieId, false, 'already_running');
        }

        try {
            return $this->queueSearch($movieId, $clientKey, $sources);
        } finally {
            $lock->release();
        }
    }

    private function startResult(int $movieId, bool $queued, ?string $reason): DownloadSearchStartData
    {
        return new DownloadSearchStartData(
            summary: $this->summarize($movieId),
            queued: $queued,
            reason: $reason,
        );
    }

    private function startBlockReason(int $movieId, string $clientKey): ?string
    {
        if (! $this->searchEnabled()) {
            return 'disabled';
        }

        $existing = $this->searches->findForMovie($movieId);
        if ($existing?->state === DownloadSearchState::SEARCHING->value) {
            return 'already_running';
        }

        if ($existing?->state === DownloadSearchState::NOT_FOUND->value
            && $existing->cooldown_until !== null
            && CarbonImmutable::parse($existing->cooldown_until)->isFuture()
        ) {
            return 'cooldown';
        }

        if ($this->budgetExhausted($clientKey)) {
            return 'budget';
        }

        return null;
    }

    private function finishWithoutSources(int $movieId): DownloadSearchStartData
    {
        $now = CarbonImmutable::now();
        $search = $this->searches->firstOrNewForMovie($movieId);
        $updated = $this->searches->saveSearch($search->with(
            state: DownloadSearchState::NOT_FOUND->value,
            found_count: $this->magnetCount($movieId),
            sources_queued: [],
            sources_done: [],
            last_checked_at: $now->toIso8601String(),
            cooldown_until: $now->addHours($this->cooldownHours())->toIso8601String(),
            started_at: null,
            last_error: null,
        ));
        DownloadLinkSearchUpdated::dispatch($movieId, $updated);

        return $this->startResult($movieId, false, 'no_sources');
    }

    /** @param list<string> $sources */
    private function queueSearch(int $movieId, string $clientKey, array $sources): DownloadSearchStartData
    {
        if (! $this->reserveSourceBudgets($sources)) {
            return $this->startResult($movieId, false, 'budget');
        }
        RateLimiter::hit($this->globalBudgetKey(), 3600);
        RateLimiter::hit($this->clientBudgetKey($clientKey), 3600);

        $search = $this->searches->firstOrNewForMovie($movieId);
        $updated = $this->searches->saveSearch($search->with(
            state: DownloadSearchState::SEARCHING->value,
            found_count: $this->magnetCount($movieId),
            sources_queued: $sources,
            sources_done: [],
            last_checked_at: null,
            cooldown_until: null,
            started_at: CarbonImmutable::now()->toIso8601String(),
            last_error: null,
        ));

        SearchMovieDownloadLinksJob::dispatch($movieId, $sources[0])
            ->onQueue((string) config('javmeta_queues.download_search_queue', 'download_search'));
        DownloadLinkSearchUpdated::dispatch($movieId, $updated, $sources[0]);

        return $this->startResult($movieId, true, null);
    }

    public function markSourceDone(int $movieId, string $sourceSlug, int $magnetsAdded, ?string $error = null): void
    {
        $search = $this->searches->findForMovie($movieId);
        if ($search === null) {
            return;
        }

        $done = $search->sources_done;
        if (! in_array($sourceSlug, $done, true)) {
            $done[] = $sourceSlug;
        }
        $remaining = array_values(array_filter(
            $search->sources_queued,
            static fn(string $slug): bool => ! in_array($slug, $done, true),
        ));
        $count = $this->magnetCount($movieId);
        $changes = [
            'sources_done' => $done,
            'found_count' => $count,
            'last_error' => $error,
        ];

        if ($remaining === []) {
            $now = CarbonImmutable::now();
            $changes += [
                'state' => $count > 0 ? DownloadSearchState::FOUND->value : DownloadSearchState::NOT_FOUND->value,
                'last_checked_at' => $now->toIso8601String(),
                'started_at' => null,
                'cooldown_until' => $count === 0 ? $now->addHours($this->cooldownHours())->toIso8601String() : null,
            ];
            $updated = $this->searches->saveSearch($search->with(...$changes));
            DownloadLinkSearchUpdated::dispatch($movieId, $updated, $sourceSlug, $magnetsAdded);

            return;
        }

        $updated = $this->searches->saveSearch($search->with(...$changes));
        SearchMovieDownloadLinksJob::dispatch($movieId, $remaining[0])
            ->onQueue((string) config('javmeta_queues.download_search_queue', 'download_search'));
        DownloadLinkSearchUpdated::dispatch($movieId, $updated, $sourceSlug, $magnetsAdded);
    }

    public function markFailed(int $movieId, string $message): void
    {
        $search = $this->searches->findForMovie($movieId);
        if ($search === null) {
            return;
        }

        $now = CarbonImmutable::now();
        $updated = $this->searches->saveSearch($search->with(
            state: DownloadSearchState::FAILED->value,
            last_error: mb_substr($message, 0, 500),
            last_checked_at: $now->toIso8601String(),
            started_at: null,
            cooldown_until: $now->addHours($this->cooldownHours())->toIso8601String(),
        ));
        DownloadLinkSearchUpdated::dispatch($movieId, $updated);
    }

    /** @return list<string> */
    private function sourcesForMovie(int $movieId): array
    {
        $displayCode = $this->movies->downloadSearchDisplayCode($movieId);
        if ($displayCode === null) {
            return [];
        }

        $max = max(1, (int) config('javmeta_downloads.link_search_max_sources_per_run', 3));
        $slugs = [];
        foreach ($this->capable->slugs() as $slug) {
            if (! $this->sources->isEnabled($slug)) {
                continue;
            }
            if ($this->urls->resolve($movieId, $displayCode, $slug) === null) {
                continue;
            }
            $slugs[] = $slug;
            if (count($slugs) >= $max) {
                break;
            }
        }

        return $slugs;
    }

    public function magnetCount(int $movieId): int
    {
        return $this->movies->downloadSearchMagnetCount($movieId);
    }

    private function cooldownHours(): int
    {
        return max(1, (int) config('javmeta_downloads.link_search_cooldown_hours', 6));
    }

    private function budgetExhausted(string $clientKey): bool
    {
        $global = (int) config('javmeta_downloads.link_search_global_budget_per_hour', 30);
        $client = (int) config('javmeta_downloads.link_search_client_budget_per_hour', 10);

        return RateLimiter::tooManyAttempts($this->globalBudgetKey(), $global)
            || RateLimiter::tooManyAttempts($this->clientBudgetKey($clientKey), $client);
    }

    /** @param list<string> $sources */
    private function reserveSourceBudgets(array $sources): bool
    {
        $perSource = (int) config('javmeta_downloads.link_search_per_source_budget_per_hour', 10);
        foreach ($sources as $slug) {
            if (RateLimiter::tooManyAttempts('javmeta:download-search:source:' . $slug, $perSource)) {
                return false;
            }
        }
        foreach ($sources as $slug) {
            RateLimiter::hit('javmeta:download-search:source:' . $slug, 3600);
        }

        return true;
    }

    private function globalBudgetKey(): string
    {
        return 'javmeta:download-search:global';
    }

    private function clientBudgetKey(string $clientKey): string
    {
        return 'javmeta:download-search:client:' . sha1($clientKey);
    }
}
