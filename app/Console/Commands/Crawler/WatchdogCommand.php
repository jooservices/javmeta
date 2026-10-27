<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Events\CrawlSourceUnhealthy;
use App\Events\WorkerHeartbeatStale;
use App\Models\Source;
use App\Services\Alerts\AlertDispatcher;
use App\Services\Alerts\OperationalAlertChecks;
use App\Services\Crawl\WorkerHeartbeat;
use App\Services\Health\SourceScheduleFreshness;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

final class WatchdogCommand extends Command
{
    private const STALE_SOURCES_CACHE_KEY = 'javmeta:watchdog:stale-sources';

    protected $signature = 'crawler:watchdog';

    protected $aliases = ['javmeta:watchdog'];

    protected $description = 'Check worker heartbeat and long-stale sources; alert via Telegram + activity log.';

    /**
     * Check worker, source, crawl-progress, and due-queue health, sending alerts on transitions.
     */
    public function handle(WorkerHeartbeat $heartbeat, AlertDispatcher $alerts, OperationalAlertChecks $checks, SourceScheduleFreshness $sourceFreshness): int
    {
        if ($heartbeat->isStale()) {
            Event::dispatch(new WorkerHeartbeatStale([
                'last_heartbeat_at' => $heartbeat->lastBeatAt()?->toIso8601String(),
                'stale_seconds' => (int) config('javmeta_alerts.watchdog.heartbeat_stale_seconds', 600),
            ]));
            $this->warn('Worker heartbeat stale.');
        } else {
            $alerts->resolve('watchdog:worker_stale', 'Worker heartbeat recovered', 'A crawl worker heartbeat is back within the configured window.');
            $this->info('Worker heartbeat ok.');
        }

        $defaultStaleSeconds = (int) config('javmeta_alerts.watchdog.source_stale_seconds', 86400);
        $enabledSources = Source::query()->where('enabled', true)->get(['slug', 'last_success_at']);
        $staleSources = $enabledSources
            ->filter(fn(Source $source): bool => $sourceFreshness->isPastDue(
                $source->last_success_at,
                (string) $source->slug,
                $defaultStaleSeconds,
            ))
            ->values();

        foreach ($staleSources as $source) {
            $slug = (string) $source->slug;
            Event::dispatch(new CrawlSourceUnhealthy($slug, 'source_stale', [
                'stale_seconds' => $sourceFreshness->maximumSuccessAgeSeconds($slug, $defaultStaleSeconds),
            ]));
            $this->warn("Source stale: {$slug}");
        }

        // Resolve every source that was stale on the previous run but is not any more:
        // recovered, disabled or deleted.
        $current = $staleSources->map(static fn(Source $source): string => (string) $source->slug)->values()->all();
        $previous = (array) Cache::get(self::STALE_SOURCES_CACHE_KEY, []);

        foreach (array_diff($previous, $current) as $slug) {
            $alerts->resolve(
                CrawlSourceUnhealthy::keyFor((string) $slug, 'source_stale'),
                "Source recovered: {$slug}",
                'The source succeeded again or is no longer monitored.',
                ['source_slug' => (string) $slug],
            );
        }

        Cache::put(self::STALE_SOURCES_CACHE_KEY, $current, now()->addDays(7));

        if ($checks->checkCrawlProgress()) {
            $this->warn('Crawl made no progress.');
        }

        if ($checks->checkQueue()) {
            $this->warn('Crawl queue is stuck.');
        }

        if ($checks->checkPendingBacklog()) {
            $this->warn('Pending crawl backlog is high.');
        }

        return self::SUCCESS;
    }
}
