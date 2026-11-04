<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Repositories\CrawlQueueServiceRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RetryStatsCommand extends Command
{
    protected $signature = 'crawler:retry-stats {--source=}';

    protected $description = 'Show pending and cumulative crawl retry statistics.';

    public function handle(): int
    {
        $stats = [];
        $pending = app(CrawlQueueServiceRepository::class)->tableQuery('crawl_queue')->where('status', 'retry_pending')
            ->select('source_slug', 'retry_round', 'retry_egress_label', DB::raw('COUNT(*) AS pending'))
            ->when(is_string($this->option('source')) && $this->option('source') !== '', fn($query) => $query->where('source_slug', (string) $this->option('source')))
            ->groupBy('source_slug', 'retry_round', 'retry_egress_label')->get();
        $defaultEgress = (string) data_get(config('javmeta_queues.retry.fetch_profile', []), 'egress_label', 'default');

        foreach ($pending as $row) {
            $key = $this->key((string) $row->source_slug, (int) $row->retry_round, (string) ($row->retry_egress_label ?: $defaultEgress));
            $stats[$key] = [
                'source' => (string) $row->source_slug,
                'round' => (int) $row->retry_round,
                'egress' => (string) ($row->retry_egress_label ?: $defaultEgress),
                'pending' => (int) $row->pending,
                'success' => 0,
                'permanent_failed' => 0,
            ];
        }

        $metrics = app(CrawlQueueServiceRepository::class)->tableQuery('crawl_retry_metrics')->when(
            is_string($this->option('source')) && $this->option('source') !== '',
            fn($query) => $query->where('source_slug', (string) $this->option('source')),
        )->orderBy('source_slug')->orderBy('round')->orderBy('egress_label')->get();
        foreach ($metrics as $row) {
            $key = $this->key((string) $row->source_slug, (int) $row->round, (string) $row->egress_label);
            $stats[$key] ??= [
                'source' => (string) $row->source_slug,
                'round' => (int) $row->round,
                'egress' => (string) $row->egress_label,
                'pending' => 0,
                'success' => 0,
                'permanent_failed' => 0,
            ];
            if ($row->outcome === 'success') {
                $stats[$key]['success'] += (int) $row->value;
            } elseif ($row->outcome === 'failed_permanent') {
                $stats[$key]['permanent_failed'] += (int) $row->value;
            }
        }

        if ($stats === []) {
            $this->info('No retry rows or metrics found.');

            return self::SUCCESS;
        }

        ksort($stats);
        $this->table(
            ['Source', 'Round', 'Egress', 'Pending', 'Succeeded', 'Failed permanently'],
            array_values($stats),
        );

        return self::SUCCESS;
    }

    private function key(string $source, int $round, string $egress): string
    {
        return $source . "\0" . $round . "\0" . $egress;
    }
}
