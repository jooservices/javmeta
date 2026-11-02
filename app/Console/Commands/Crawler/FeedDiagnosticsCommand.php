<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Models\Source;
use App\Services\Crawl\FeedPoolDiagnostics;
use App\Services\Crawl\SourceGapPolicy;
use App\Services\Crawl\SourceThrottle;
use Illuminate\Console\Command;
use Throwable;

final class FeedDiagnosticsCommand extends Command
{
    protected $signature = 'crawler:feed-diagnostics {--minutes=10 : Minutes of retained feed pool diagnostics to display}';

    protected $description = 'Show retained feed pool budgets, dispatches, and stop reasons.';

    public function handle(FeedPoolDiagnostics $diagnostics, SourceGapPolicy $gapPolicy, SourceThrottle $throttle): int
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
            $sources = Source::query()->whereIn('slug', array_keys($report['sources']))->get()->keyBy('slug');
            $rows = [];
            foreach ($report['sources'] as $source => $metrics) {
                $model = $sources->get($source);
                $gap = $gapPolicy->describe($source, $model instanceof Source ? $model : null);
                $rows[] = [
                    $source,
                    $metrics['budget'],
                    $metrics['started'],
                    $metrics['waiting'],
                    $metrics['dispatched'],
                    number_format($gap['effective']['default'], 2),
                    $gap['overridden'] ? 'yes' : 'no',
                    $model instanceof Source ? number_format($throttle->currentGap($model), 2) : '-',
                ];
            }
            $this->table(['Source', 'Budget', 'Started', 'Waiting', 'Dispatched', 'Effective gap (s)', 'Override', 'Adaptive gap (s)'], $rows);
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
