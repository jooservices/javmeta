<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Repositories\CrawlQueueRepository;
use Illuminate\Console\Command;

final class PruneFetchAttemptsCommand extends Command
{
    protected $signature = 'crawler:prune-fetch-attempts';

    protected $description = 'Delete fetch attempts older than the configured retention period.';

    public function handle(): int
    {
        $days = max(1, (int) config('javmeta_observability.fetch_attempts_retention_days', 14));
        $deleted = app(CrawlQueueRepository::class)->tableQuery('crawl_fetch_attempts')->where('created_at', '<', now()->subDays($days))->delete();
        $this->info("Deleted {$deleted} fetch attempts.");

        return self::SUCCESS;
    }
}
