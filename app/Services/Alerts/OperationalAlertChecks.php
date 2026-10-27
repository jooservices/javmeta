<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Models\CrawlQueue;
use App\Models\Source;

/**
 * Crawl-level alert rules run by crawler:watchdog: a crawl that made no progress
 * and a queue with claims nobody finishes. Each rule alerts once per throttle
 * window and sends one recovery message when the condition clears.
 */
final class OperationalAlertChecks
{
    public const CRAWL_STALLED_KEY = 'crawl:stalled';
    public const QUEUE_STUCK_KEY = 'queue:stuck';
    public const QUEUE_PENDING_BACKLOG_KEY = 'queue:pending_backlog';

    public function __construct(private readonly AlertDispatcher $alerts) {}

    /**
     * Stalled = work is due, yet no enabled source recorded a success within the window.
     */
    public function checkCrawlProgress(): bool
    {
        $window = max(1, (int) config('javmeta_alerts.watchdog.crawl_stall_seconds', 1800));
        $cutoff = now()->subSeconds($window);

        $hasDueWork = CrawlQueue::query()
            ->where('status', CrawlQueue::STATUS_PENDING)
            ->where(function ($query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->exists();

        $progressed = Source::query()
            ->where('enabled', true)
            ->where('last_success_at', '>=', $cutoff)
            ->exists();

        if ($hasDueWork && ! $progressed) {
            $slugs = $this->slugList(CrawlQueue::query()
                ->where('status', CrawlQueue::STATUS_PENDING)
                ->where(function ($query): void {
                    $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
                }));

            $this->alerts->send(
                debounceKey: self::CRAWL_STALLED_KEY,
                subject: 'Crawl made no progress',
                body: "Crawl work is waiting but no source succeeded in the last {$window} seconds. Sources: {$slugs}.",
                context: ['stall_seconds' => $window, 'source_slugs' => $slugs],
                debounceSeconds: $this->throttle(),
            );

            return true;
        }

        $this->alerts->resolve(
            self::CRAWL_STALLED_KEY,
            'Crawl progress recovered',
            'The crawl is making progress again.',
        );

        return false;
    }

    /**
     * Alert when a large number of pending rows are due now. Future retries do not count.
     */
    public function checkPendingBacklog(): bool
    {
        $limit = max(1, (int) config('javmeta_alerts.watchdog.pending_queue_limit', 1000));
        $pending = CrawlQueue::query()
            ->where('status', CrawlQueue::STATUS_PENDING)
            ->where(function ($query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            });
        $count = (clone $pending)->count();

        if ($count >= $limit) {
            $slugs = (clone $pending)->distinct()->orderBy('source_slug')->limit(20)->pluck('source_slug')->all();
            $sourceSlugs = $slugs === [] ? 'unknown' : implode(', ', array_map('strval', $slugs));

            $this->alerts->send(
                debounceKey: self::QUEUE_PENDING_BACKLOG_KEY,
                subject: 'Pending crawl backlog is high',
                body: "{$count} due crawl item(s) are pending (alert limit {$limit}). Sources: {$sourceSlugs}.",
                context: ['pending_due' => $count, 'pending_limit' => $limit, 'source_slugs' => $sourceSlugs],
                debounceSeconds: $this->throttle(),
            );

            return true;
        }

        $this->alerts->resolve(
            self::QUEUE_PENDING_BACKLOG_KEY,
            'Pending crawl backlog recovered',
            'The number of due pending crawl items is below the configured limit.',
            ['pending_due' => $count, 'pending_limit' => $limit],
        );

        return false;
    }

    /**
     * Stuck = claimed rows older than the window (the reclaimer should have freed them).
     */
    public function checkQueue(): bool
    {
        $window = max(1, (int) config('javmeta_alerts.watchdog.queue_stuck_seconds', 1800));

        $stuckQuery = CrawlQueue::query()
            ->where('status', CrawlQueue::STATUS_CLAIMED)
            ->where('claimed_at', '<', now()->subSeconds($window));
        $stuck = (clone $stuckQuery)->count();

        if ($stuck > 0) {
            $slugs = $this->slugList($stuckQuery);

            $this->alerts->send(
                debounceKey: self::QUEUE_STUCK_KEY,
                subject: 'Crawl queue is stuck',
                body: "{$stuck} claimed queue item(s) have not finished for over {$window} seconds. Sources: {$slugs}.",
                context: ['stuck_claimed' => $stuck, 'stuck_seconds' => $window, 'source_slugs' => $slugs],
                debounceSeconds: $this->throttle(),
            );

            return true;
        }

        $this->alerts->resolve(
            self::QUEUE_STUCK_KEY,
            'Crawl queue recovered',
            'No claimed queue items are stuck any more.',
        );

        return false;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<CrawlQueue>  $query
     */
    private function slugList(\Illuminate\Database\Eloquent\Builder $query): string
    {
        $slugs = $query->distinct()->orderBy('source_slug')->limit(20)->pluck('source_slug')->all();

        return $slugs === [] ? 'unknown' : implode(', ', array_map('strval', $slugs));
    }

    private function throttle(): int
    {
        return (int) config('javmeta_alerts.throttle_seconds', 3600);
    }
}
