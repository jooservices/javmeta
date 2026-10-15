<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Events\CrawlSourceUnhealthy;
use App\Models\Movie;
use App\Models\MovieSource;
use App\Models\ReviewFlag;
use Illuminate\Support\Facades\Event;

/**
 * Tracks consecutive detail failures per source URL and marks linked movies for review (AC-9.3).
 * Soft-404 on a known source URL sets delisted_at without wiping fields (BR-10).
 */
final class TitleFailureTracker
{
    public function recordFailure(string $sourceSlug, string $url, ?string $errorMessage = null, bool $soft404 = false): void
    {
        $movie = $this->movieForUrl($sourceSlug, $url);
        $threshold = max(1, (int) config('javmeta_alerts.title_failure_threshold', 3));
        $isSoft404 = $soft404;

        if ($isSoft404 && $movie instanceof Movie) {
            if ($movie->delisted_at === null) {
                $movie->forceFill(['delisted_at' => now()])->save();
            }

            Event::dispatch(new CrawlSourceUnhealthy($sourceSlug, 'soft404', [
                'url' => $url,
                'movie_id' => $movie->id,
                'code' => $movie->display_code,
            ]));
        }

        $flag = ReviewFlag::query()
            ->where('source_slug', $sourceSlug)
            ->whereNull('resolved_at')
            ->where('reason', 'consecutive_crawl_failure')
            ->when(
                $movie instanceof Movie,
                static fn($q) => $q->where('movie_id', $movie->id),
                fn($q) => $q->whereNull('movie_id')->where('code_normalized', $this->urlKey($url)),
            )
            ->first();

        $failures = $flag instanceof ReviewFlag ? ((int) $flag->consecutive_failures) + 1 : 1;

        if ($flag instanceof ReviewFlag) {
            $flag->forceFill([
                'consecutive_failures' => $failures,
                'flagged_at' => now(),
                'movie_id' => $movie instanceof Movie ? $movie->id : $flag->movie_id,
                'code_normalized' => $movie instanceof Movie ? $movie->code_normalized : $this->urlKey($url),
            ])->save();
        } else {
            ReviewFlag::query()->create([
                'movie_id' => $movie instanceof Movie ? $movie->id : null,
                'code_normalized' => $movie instanceof Movie ? $movie->code_normalized : $this->urlKey($url),
                'source_slug' => $sourceSlug,
                'consecutive_failures' => $failures,
                'reason' => 'consecutive_crawl_failure',
                'flagged_at' => now(),
            ]);
        }

        if ($failures >= $threshold && $movie instanceof Movie) {
            $movie->forceFill(['needs_review' => true])->save();
            Event::dispatch(new CrawlSourceUnhealthy($sourceSlug, 'title_needs_review', [
                'url' => $url,
                'movie_id' => $movie->id,
                'code' => $movie->display_code,
                'consecutive_failures' => $failures,
            ]));
        }
    }

    public function recordSuccess(string $sourceSlug, string $url): void
    {
        $movie = $this->movieForUrl($sourceSlug, $url);
        $urlKey = $this->urlKey($url);

        ReviewFlag::query()
            ->where('source_slug', $sourceSlug)
            ->whereNull('resolved_at')
            ->where('reason', 'consecutive_crawl_failure')
            ->when(
                $movie instanceof Movie,
                static fn($q) => $q->where('movie_id', $movie->id),
                static fn($q) => $q->whereNull('movie_id')->where('code_normalized', $urlKey),
            )
            ->update(['resolved_at' => now()]);
    }

    private function movieForUrl(string $sourceSlug, string $url): ?Movie
    {
        $link = MovieSource::query()
            ->where('site_slug', $sourceSlug)
            ->where('source_url', $url)
            ->first();

        if (! $link instanceof MovieSource) {
            return null;
        }

        $movie = Movie::query()->find($link->movie_id);

        return $movie instanceof Movie ? $movie : null;
    }

    private function urlKey(string $url): string
    {
        return 'url:' . hash('sha256', $url);
    }
}
