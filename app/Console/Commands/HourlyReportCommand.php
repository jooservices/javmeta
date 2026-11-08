<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Report\PeriodicReportService;
use Illuminate\Console\Command;

final class HourlyReportCommand extends Command
{
    protected $signature = 'javmeta:hourly-report {--dry-run : Print the message and send nothing}';

    protected $description = 'Send one periodic status report for backwards compatibility.';

    public function handle(PeriodicReportService $reports): int
    {
        if ((bool) $this->option('dry-run')) {
            $this->line($reports->preview()['text']);

            return self::SUCCESS;
        }

        $result = $reports->sendNow();
        $this->line($result['text']);
        if (! $result['sent']) {
            $this->components->error('The report was not delivered.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
