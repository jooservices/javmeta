<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Services\Crawl\FeedPoolDiagnostics;
use Illuminate\Console\Command;
use Throwable;

final class FeedDiagnosticsCommand extends Command
{
    protected $signature = 'crawler:feed-diagnostics {--minutes=10 : Minutes of retained feed pool diagnostics to display}';

    protected $description = 'Show retained feed pool budgets, dispatches, and stop reasons.';

    public function handle(FeedPoolDiagnostics $diagnostics): int
    {
        $requestedMinutes = filter_var($this->option('minutes'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1440]]);
        if ($requestedMinutes === false) {
            $this->error('--minutes must be an integer between 1 and 1440.');

            return self::FAILURE;
        }

        if (! (bool) config('javmeta_queues.feed_diagnostics.enabled', true)) {
            $this->info('Feed pool diagnostics are disabled.');

            return self::SUCCESS;
        }

        try {
            $report = $diagnostics->report($requestedMinutes);
        } catch (Throwable) {
            $this->error('Unable to read feed diagnostics from Redis.');

            return self::FAILURE;
        }
        $this->info('Feed pool diagnostics for the last ' . $report['window_minutes'] . ' minute(s).');
        if ($report['sources'] === []) {
            $this->info('No per-source feed data was recorded in this window.');
        } else {
            $rows = [];
            foreach ($report['sources'] as $source => $metrics) {
                $rows[] = [$source, $metrics['budget'], $metrics['started'], $metrics['waiting'], $metrics['dispatched']];
            }
            $this->table(['Source', 'Budget', 'Started', 'Waiting', 'Dispatched'], $rows);
        }

        if ($report['stop_reasons'] === []) {
            $this->info('No queue stop reasons were recorded in this window.');
        } else {
            $this->table(
                ['Queue', 'Lane', 'Stop reason', 'Runs'],
                array_map(static fn(array $stop): array => [$stop['queue'], $stop['lane'], $stop['reason'], $stop['count']], $report['stop_reasons']),
            );
        }

        $this->line('Feed runs: ' . $report['run_count'] . '; total run time: ' . $report['run_ms_total'] . ' ms; claimNext candidate rows examined: ' . $report['claim_rows_examined'] . '.');

        return self::SUCCESS;
    }
}
