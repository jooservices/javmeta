<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Observability\ObservabilityEmitter;
use App\Services\Crawl\CrawlQueueService;
use Illuminate\Console\Command;

final class ReclaimCommand extends Command
{
    protected $signature = 'crawler:reclaim';

    protected $description = 'Reclaim stale claimed crawl queue rows.';

    public function handle(CrawlQueueService $queue, ObservabilityEmitter $observability): int
    {
        $reclaimed = $queue->reclaimStaleClaimed((int) config('javmeta_sources.defaults.queue.stale_claim_timeout_seconds', 900));
        if ($reclaimed > 0) {
            $this->components->info("Reclaimed {$reclaimed} stale queue rows.");
            $observability->emitOps('queue_reclaim', ['reclaimed' => $reclaimed]);
        }

        return self::SUCCESS;
    }
}
