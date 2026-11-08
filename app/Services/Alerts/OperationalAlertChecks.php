<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Repositories\AlertOperationsServiceRepository;

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

    public function __construct(
        private readonly AlertDispatcher $alerts,
        private readonly AlertOperationsServiceRepository $repository,
    ) {}

    /**
     * Stalled = work is due, yet no enabled source recorded a success within the window.
     */
    public function checkCrawlProgress(): bool
    {
        $window = max(1, (int) config('javmeta_alerts.watchdog.crawl_stall_seconds', 1800));
        $cutoff = now()->subSeconds($window);

        $hasDueWork = $this->repository->hasDueQueueWork();
        $progressed = $this->repository->hasSourceProgressSince($cutoff);

        if ($hasDueWork && ! $progressed) {
            $slugs = $this->repository->dueQueueSourceList();

            $this->alerts->sendTemplate(
                debounceKey: self::CRAWL_STALLED_KEY,
                templateKey: 'crawl.stalled',
                context: ['stall_seconds' => $window, 'source_slugs' => $slugs],
                debounceSeconds: $this->throttle(),
            );

            return true;
        }

        $this->alerts->resolveTemplate(self::CRAWL_STALLED_KEY, 'crawl.stalled_recovered');

        return false;
    }

    /**
     * Alert when a large number of pending rows are due now. Future retries do not count.
     */
    /**
     * Alert on due pending work at its configured limit and resolve once it falls below the limit.
     */
    public function checkPendingBacklog(): bool
    {
        $limit = max(1, (int) config('javmeta_alerts.watchdog.pending_queue_limit', 1000));
        $pending = $this->repository->dueQueueBacklog();
        $count = $pending['count'];

        if ($count >= $limit) {
            $this->alerts->sendTemplate(
                debounceKey: self::QUEUE_PENDING_BACKLOG_KEY,
                templateKey: 'crawl.backlog_high',
                context: ['value' => $count, 'threshold' => $limit, 'pending_due' => $count, 'pending_limit' => $limit, 'source_slugs' => $pending['slugs']],
                debounceSeconds: $this->throttle(),
            );

            return true;
        }

        $this->alerts->resolveTemplate(self::QUEUE_PENDING_BACKLOG_KEY, 'crawl.backlog_recovered', ['value' => $count, 'threshold' => $limit, 'pending_due' => $count, 'pending_limit' => $limit]);

        return false;
    }

    /**
     * Stuck = claimed rows older than the window (the reclaimer should have freed them).
     */
    public function checkQueue(): bool
    {
        $window = max(1, (int) config('javmeta_alerts.watchdog.queue_stuck_seconds', 1800));

        $stuck = $this->repository->stuckQueue(now()->subSeconds($window));

        if ($stuck['count'] > 0) {
            $this->alerts->sendTemplate(
                debounceKey: self::QUEUE_STUCK_KEY,
                templateKey: 'crawl.queue_stuck',
                context: ['stuck_claimed' => $stuck['count'], 'stuck_seconds' => $window, 'source_slugs' => $stuck['slugs']],
                debounceSeconds: $this->throttle(),
            );

            return true;
        }

        $this->alerts->resolveTemplate(self::QUEUE_STUCK_KEY, 'crawl.queue_recovered');

        return false;
    }

    private function throttle(): int
    {
        return (int) config('javmeta_alerts.throttle_seconds', 3600);
    }
}
