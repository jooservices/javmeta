<?php

declare(strict_types=1);

namespace App\Console\Commands\Reports;

use App\Services\Reports\ReportService;
use Illuminate\Console\Command;

final class RefreshFieldConflictsCommand extends Command
{
    protected $signature = 'reports:field-conflicts';

    protected $description = 'Refresh the cached movie field-conflict summary.';

    public function handle(ReportService $reports): int
    {
        $summary = $reports->refreshFieldConflicts();
        $this->components->info(sprintf(
            'Refreshed field conflicts for %d movies across %d fields.',
            $summary['movies_with_a_conflict'],
            count($summary['by_field']),
        ));

        return self::SUCCESS;
    }
}
