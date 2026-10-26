<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class FetchReportCommand extends Command
{
    protected $signature = 'crawler:fetch-report {source} {--hours=24}';

    protected $description = 'Summarize fetch attempts and the five most frequent errors for a source.';

    public function handle(): int
    {
        $hours = filter_var($this->option('hours'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 87600]]);
        if ($hours === false) {
            $this->error('--hours must be an integer between 1 and 87600.');

            return self::FAILURE;
        }
        $query = DB::table('crawl_fetch_attempts')->where('source_slug', (string) $this->argument('source'))
            ->where('created_at', '>=', now()->subHours($hours));
        $counts = (clone $query)->select('method', 'outcome')->selectRaw('COUNT(*) AS total')
            ->groupBy('method', 'outcome')->orderBy('method')->orderBy('outcome')->get();
        if ($counts->isEmpty()) {
            $this->info('No fetch attempts found for the selected source and period.');

            return self::SUCCESS;
        }
        $this->table(['Method', 'Outcome', 'Count'], $counts->map(static fn(object $row): array => [$row->method, $row->outcome, $row->total])->all());
        $errors = (clone $query)->whereNotNull('error')->where('error', '<>', '')->select('error')->selectRaw('COUNT(*) AS total')
            ->groupBy('error')->orderByDesc('total')->orderBy('error')->limit(5)->get();
        $this->table(['Error', 'Count'], $errors->map(static fn(object $row): array => [$row->error, $row->total])->all());

        return self::SUCCESS;
    }
}
