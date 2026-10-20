<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Events\CrawlSourceUnhealthy;
use App\Events\WorkerHeartbeatStale;
use App\Models\Source;
use App\Services\Alerts\AlertDispatcher;
use App\Services\Alerts\OperationalAlertChecks;
use App\Services\Crawl\WorkerHeartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;

final class WatchdogCommand extends Command
{
    protected $signature = 'crawler:watchdog';

    protected $aliases = ['javmeta:watchdog'];

    protected $description = 'Check worker heartbeat and long-stale sources; alert via Telegram + activity log.';

    public function handle(WorkerHeartbeat $heartbeat, AlertDispatcher $alerts, OperationalAlertChecks $checks): int
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

        $staleSeconds = (int) config('javmeta_alerts.watchdog.source_stale_seconds', 86400);
        $cutoff = now()->subSeconds($staleSeconds);

        $staleSources = Source::query()
            ->where('enabled', true)
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('last_success_at')
                    ->orWhere('last_success_at', '<', $cutoff);
            })
            ->pluck('slug');

        foreach ($staleSources as $slug) {
            Event::dispatch(new CrawlSourceUnhealthy((string) $slug, 'source_stale', [
                'stale_seconds' => $staleSeconds,
            ]));
            $this->warn("Source stale: {$slug}");
        }

        $healthySlugs = Source::query()
            ->where('enabled', true)
            ->where('last_success_at', '>=', $cutoff)
            ->pluck('slug');

        foreach ($healthySlugs as $slug) {
            $alerts->resolve(
                CrawlSourceUnhealthy::keyFor((string) $slug, 'source_stale'),
                "Source recovered: {$slug}",
                'The source succeeded again.',
                ['source_slug' => (string) $slug],
            );
        }

        if ($checks->checkCrawlProgress()) {
            $this->warn('Crawl made no progress.');
        }

        if ($checks->checkQueue()) {
            $this->warn('Crawl queue is stuck.');
        }

        return self::SUCCESS;
    }
}
