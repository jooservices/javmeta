<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Repositories\CrawlQueueRepository;
use App\Models\CrawlQueue;
use App\Services\Crawl\CrawlQueueService;
use Illuminate\Console\Command;

final class RetryRequeueCommand extends Command
{
    protected $signature = 'crawler:retry-requeue {id?} {--source=}';

    protected $description = 'Move a retryable permanent crawl failure back to retry round one.';

    public function handle(CrawlQueueService $queue): int
    {
        $id = $this->argument('id');
        $source = $this->option('source');
        if ($id !== null && $source !== null) {
            $this->error('Use either an ID or --source.');

            return self::FAILURE;
        }
        if (is_string($source) && $source !== '') {
            $count = $queue->requeuePermanentFailuresForSource($source);
            $this->info("Requeued {$count} retryable failure(s) for {$source}.");

            return self::SUCCESS;
        }
        if (! is_numeric($id)) {
            $this->error('Provide a row ID or --source.');

            return self::FAILURE;
        }

        $row = app(CrawlQueueRepository::class)->modelQuery(CrawlQueue::class)->find((int) $id);
        if (! $row instanceof CrawlQueue || ! $queue->requeuePermanentFailure($row)) {
            $this->error('The row is missing or its error is not retryable.');

            return self::FAILURE;
        }
        $this->info("Requeued crawl row {$id} into round one.");

        return self::SUCCESS;
    }
}
