<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Models\CrawlQueue;
use App\Repositories\CrawlQueueRepository;
use App\Repositories\SourceRepository;
use App\Services\Crawl\CrawlQueueService;
use Illuminate\Console\Command;

final class RetryRequeueCommand extends Command
{
    protected $signature = 'crawler:retry-requeue {id?} {--source=} {--ssrf-blocked : Select failed SSRF-blocked rows only} {--batch-size=100 : Maximum SSRF rows to requeue in one run} {--apply : Apply the SSRF requeue instead of previewing it}';

    protected $description = 'Move a retryable permanent crawl failure back to retry round one.';

    public function handle(CrawlQueueService $queue, SourceRepository $sources): int
    {
        $id = $this->argument('id');
        $source = $this->option('source');

        if ($this->option('ssrf-blocked')) {
            return $this->requeueSsrfFailures($queue, $sources, $id, $source);
        }
        if ($this->option('apply') || $this->input->hasParameterOption('--batch-size')) {
            $this->error('Use --apply and --batch-size only with --ssrf-blocked.');

            return self::FAILURE;
        }

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

    private function requeueSsrfFailures(
        CrawlQueueService $queue,
        SourceRepository $sources,
        mixed $id,
        mixed $source,
    ): int {
        if ($id !== null) {
            $this->error('Use --ssrf-blocked without a row ID.');

            return self::FAILURE;
        }

        $batchSize = filter_var($this->option('batch-size'), FILTER_VALIDATE_INT);
        if (! is_int($batchSize) || $batchSize < 1 || $batchSize > CrawlQueueService::MAX_SSRF_REQUEUE_BATCH_SIZE) {
            $this->error('Batch size must be between 1 and ' . CrawlQueueService::MAX_SSRF_REQUEUE_BATCH_SIZE . '.');

            return self::FAILURE;
        }

        if ($source !== null && (! is_string($source) || $source === '' || ! $sources->findSource($source))) {
            $this->error('The source filter is invalid.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $counts = $queue->requeueSsrfBlockedFailures($apply, $source, $batchSize);
        $this->info("Eligible SSRF-blocked failed rows: {$counts['eligible']}.");
        if ($apply) {
            $this->info("Requeued SSRF-blocked failed rows: {$counts['requeued']} (batch limit: {$batchSize}).");
        } else {
            $this->info("Dry run only; no rows changed (batch limit: {$batchSize}).");
        }

        return self::SUCCESS;
    }
}
