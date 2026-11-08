<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Events\CrawlSourceUnhealthy;
use App\Repositories\CrawlTitleFailureServiceRepository;
use Illuminate\Support\Facades\Event;

/**
 * Tracks consecutive detail failures per source URL and marks linked movies for review (AC-9.3).
 * Soft-404 on a known source URL sets delisted_at without wiping fields (BR-10).
 */
final class TitleFailureTracker
{
    public function __construct(private readonly ?CrawlTitleFailureServiceRepository $repository = null) {}

    public function recordFailure(string $sourceSlug, string $url, ?string $errorMessage = null, bool $soft404 = false): void
    {
        $movie = $this->repository()->movieForSourceUrl($sourceSlug, $url);
        $threshold = max(1, (int) config('javmeta_alerts.title_failure_threshold', 3));
        $isSoft404 = $soft404;

        if ($isSoft404 && $movie !== null) {
            if ($movie['delisted_at'] === null) {
                $this->repository()->markDelisted($movie['id'], now());
            }

            Event::dispatch(new CrawlSourceUnhealthy($sourceSlug, 'soft404', [
                'url' => $url,
                'movie_id' => $movie['id'],
                'code' => $movie['display_code'],
            ]));
        }

        $urlKey = $this->urlKey($url);
        $flag = $this->repository()->activeFailureFlag($sourceSlug, $movie['id'] ?? null, $urlKey);

        $failures = $flag !== null ? $flag['consecutive_failures'] + 1 : 1;

        if ($flag !== null) {
            $this->repository()->updateFailureFlag($flag['id'], [
                'consecutive_failures' => $failures,
                'flagged_at' => now(),
                'movie_id' => $movie['id'] ?? $flag['movie_id'],
                'code_normalized' => $movie['code_normalized'] ?? $urlKey,
            ]);
        } else {
            $this->repository()->createFailureFlag([
                'movie_id' => $movie['id'] ?? null,
                'code_normalized' => $movie['code_normalized'] ?? $urlKey,
                'source_slug' => $sourceSlug,
                'consecutive_failures' => $failures,
                'reason' => 'consecutive_crawl_failure',
                'flagged_at' => now(),
            ]);
        }

        if ($failures >= $threshold && $movie !== null) {
            $this->repository()->markNeedsReview($movie['id'], now());
            Event::dispatch(new CrawlSourceUnhealthy($sourceSlug, 'title_needs_review', [
                'url' => $url,
                'movie_id' => $movie['id'],
                'code' => $movie['display_code'],
                'consecutive_failures' => $failures,
            ]));
        }
    }

    public function recordSuccess(string $sourceSlug, string $url): void
    {
        $movie = $this->repository()->movieForSourceUrl($sourceSlug, $url);
        $urlKey = $this->urlKey($url);

        $this->repository()->resolveFailureFlags($sourceSlug, $movie['id'] ?? null, $urlKey, now());
    }

    private function urlKey(string $url): string
    {
        return 'url:' . hash('sha256', $url);
    }

    private function repository(): CrawlTitleFailureServiceRepository
    {
        return $this->repository ?? app(CrawlTitleFailureServiceRepository::class);
    }
}
