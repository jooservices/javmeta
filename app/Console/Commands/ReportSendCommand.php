<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Report\PeriodicReportService;
use App\Services\Report\ReportSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class ReportSendCommand extends Command
{
    protected $signature = 'report:send {--now : Send immediately without changing the scheduled grid}';

    protected $description = 'Send the periodic XCrawler status report when due.';

    public function handle(PeriodicReportService $reports, ReportSchedule $schedule): int
    {
        $manual = (bool) $this->option('now');
        $slot = $manual ? null : $schedule->dueSlot(CarbonImmutable::now());
        if (! $manual && $slot === null) {
            $this->components->info('No periodic report is due.');

            return self::SUCCESS;
        }

        $result = $manual ? $reports->sendNow() : $reports->sendScheduled($slot);
        if ($result['text'] !== '') {
            $this->line($result['text']);
        }
        if ($result['duplicate']) {
            $this->components->info('This report slot has already been handled.');

            return self::SUCCESS;
        }
        if (! $result['sent']) {
            $this->components->error('The report was not delivered.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
