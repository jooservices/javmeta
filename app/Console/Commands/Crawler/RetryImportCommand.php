<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Services\Crawl\CrawlQueueService;
use Illuminate\Console\Command;

final class RetryImportCommand extends Command
{
    protected $signature = 'crawler:retry-import {--dry-run} {--source=}';

    protected $description = 'Import eligible historical transient crawl failures into retry round one.';

    public function handle(CrawlQueueService $queue): int
    {
        $source = is_string($this->option('source')) && $this->option('source') !== '' ? (string) $this->option('source') : null;
        $count = $queue->importHistoricalFailures((bool) $this->option('dry-run'), $source);
        $mode = $this->option('dry-run') ? 'Eligible historical failures' : 'Imported historical failures';
        $this->info("{$mode}: {$count}.");

        return self::SUCCESS;
    }
}
