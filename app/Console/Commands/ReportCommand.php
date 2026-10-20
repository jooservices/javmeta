<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Reports\ReportService;
use Illuminate\Console\Command;

final class ReportCommand extends Command
{
    protected $signature = 'javmeta:report {type : quality, cost or sources} {--json}';

    protected $description = 'Print the data quality, crawl cost or source freshness report.';

    public function handle(ReportService $reports): int
    {
        $report = match ((string) $this->argument('type')) {
            'quality' => $reports->quality(),
            'cost' => $reports->cost(),
            'sources' => $reports->sources(),
            default => null,
        };
        if ($report === null) {
            $this->components->error('The report type must be quality, cost or sources.');

            return self::FAILURE;
        }

        $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
