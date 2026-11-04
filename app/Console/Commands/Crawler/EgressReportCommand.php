<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Repositories\CrawlQueueServiceRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

final class EgressReportCommand extends Command
{
    protected $signature = 'crawler:egress-report {--hours=24} {--by=egress : Group by source, egress or node}';

    protected $description = 'Summarize crawl success, blocks and latency by source and egress IP.';

    public function handle(): int
    {
        $hours = filter_var($this->option('hours'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 87600]]);
        $by = (string) $this->option('by');
        if ($hours === false) {
            $this->error('--hours must be an integer between 1 and 87600.');

            return self::FAILURE;
        }
        if (! in_array($by, ['source', 'egress', 'node'], true)) {
            $this->error('--by must be source, egress or node.');

            return self::FAILURE;
        }
        if (! $this->hasEgressSchema()) {
            $this->error('Egress attempt metadata is unavailable; apply the crawl fetch attempt migration first.');

            return self::FAILURE;
        }

        $groups = $this->groupColumns($by);
        $rows = app(CrawlQueueServiceRepository::class)->tableQuery('crawl_fetch_attempts')
            ->where('created_at', '>=', now()->subHours($hours))
            ->select($groups)
            ->selectRaw('COUNT(*) AS attempts')
            ->selectRaw('SUM(CASE WHEN outcome = ? THEN 1 ELSE 0 END) AS ok', ['ok'])
            ->selectRaw('SUM(CASE WHEN http_status IN (403, 429) OR NULLIF(cf_mitigated, ?) IS NOT NULL THEN 1 ELSE 0 END) AS blocked', [''])
            ->selectRaw('CAST(SUM(CASE WHEN outcome = ? THEN 1 ELSE 0 END) AS REAL) / NULLIF(COUNT(*), 0) AS success_ratio', ['ok'])
            ->selectRaw('COALESCE(AVG(duration_ms), 0) AS avg_duration_ms')
            ->groupBy($groups)
            ->orderByDesc('blocked')
            ->orderByDesc('attempts')
            ->orderBy('source_slug')
            ->orderBy('egress_ip')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No fetch attempts found for the selected period.');

            return self::SUCCESS;
        }

        $this->table($this->headers($by), $rows->map(fn(object $row): array => $this->formatRow($row, $by))->all());

        return self::SUCCESS;
    }

    private function hasEgressSchema(): bool
    {
        if (! Schema::hasTable('crawl_fetch_attempts')) {
            return false;
        }

        return Schema::hasColumn('crawl_fetch_attempts', 'egress_ip')
            && Schema::hasColumn('crawl_fetch_attempts', 'node');
    }

    /** @return list<string> */
    private function groupColumns(string $by): array
    {
        return match ($by) {
            'source' => ['source_slug', 'egress_ip'],
            'node' => ['node', 'egress_ip', 'source_slug'],
            default => ['egress_ip', 'source_slug'],
        };
    }

    /** @return list<string> */
    private function headers(string $by): array
    {
        $groupHeaders = match ($by) {
            'source' => ['Source', 'Egress IP'],
            'node' => ['Node', 'Egress IP', 'Source'],
            default => ['Egress IP', 'Source'],
        };

        return [...$groupHeaders, 'Attempts', 'OK', 'Blocked', 'Success ratio', 'Average duration (ms)'];
    }

    /** @return list<string|int> */
    private function formatRow(object $row, string $by): array
    {
        $groupValues = match ($by) {
            'source' => [$row->source_slug, $row->egress_ip ?? 'unknown'],
            'node' => [$row->node ?? 'unknown', $row->egress_ip ?? 'unknown', $row->source_slug],
            default => [$row->egress_ip ?? 'unknown', $row->source_slug],
        };

        return [
            ...$groupValues,
            (int) $row->attempts,
            (int) $row->ok,
            (int) $row->blocked,
            number_format((float) $row->success_ratio * 100, 2) . '%',
            number_format((float) $row->avg_duration_ms, 2),
        ];
    }
}
