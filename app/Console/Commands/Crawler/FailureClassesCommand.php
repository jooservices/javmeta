<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Repositories\CrawlQueueRepository;
use App\Services\Crawl\FetchAttemptClassifier;
use Illuminate\Console\Command;

final class FailureClassesCommand extends Command
{
    protected $signature = 'crawler:failure-classes {--hours=72}';

    protected $description = 'Give every source with failed fetch attempts one verdict (expected outcome, real blocker, own fault, defect) and the attempts it rests on.';

    public function handle(CrawlQueueRepository $queue, FetchAttemptClassifier $classifier): int
    {
        $hours = filter_var($this->option('hours'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 87600]]);
        if ($hours === false) {
            $this->error('--hours must be an integer between 1 and 87600.');

            return self::FAILURE;
        }

        $groups = $queue->tableQuery('crawl_fetch_attempts')
            ->where('outcome', 'error')->where('created_at', '>=', now()->subHours($hours))
            ->select('source_slug', 'method', 'http_status', 'error_code')->selectRaw('COUNT(*) AS total')
            ->groupBy('source_slug', 'method', 'http_status', 'error_code')
            ->orderByDesc('total')->orderBy('source_slug')->orderBy('method')->get();
        if ($groups->isEmpty()) {
            $this->info('No failed fetch attempts found in the selected period.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($groups->groupBy('source_slug') as $source => $sourceGroups) {
            $dominant = $sourceGroups->first();
            $rows[] = [
                (string) $source,
                (int) $sourceGroups->sum('total'),
                $classifier->classify($dominant->error_code, $dominant->http_status === null ? null : (int) $dominant->http_status)->value,
                $dominant->method,
                $dominant->http_status === null ? '-' : (int) $dominant->http_status,
                $dominant->error_code ?? '-',
            ];
        }
        usort($rows, static fn(array $a, array $b): int => [$b[1], $a[0]] <=> [$a[1], $b[0]]);
        $this->table(['Source', 'Errors', 'Verdict', 'Method', 'HTTP status', 'Error code'], $rows);

        return self::SUCCESS;
    }
}
