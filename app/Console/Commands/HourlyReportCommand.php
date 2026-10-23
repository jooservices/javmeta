<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Alerts\HourlyReport;
use Illuminate\Console\Command;

final class HourlyReportCommand extends Command
{
    protected $signature = 'javmeta:hourly-report {--dry-run : Print the message and send nothing}';

    protected $description = 'Send the hourly Telegram report: movies and performers now and the change since the previous report.';

    public function handle(HourlyReport $report): int
    {
        $result = $report->run((bool) $this->option('dry-run'));
        $this->line($result['message']);

        if ((bool) $this->option('dry-run')) {
            return self::SUCCESS;
        }

        if (! $result['sent']) {
            $this->components->error('The report was not delivered; the stored values were not changed.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
