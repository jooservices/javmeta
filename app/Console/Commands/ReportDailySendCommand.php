<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Report\PeriodicReportService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class ReportDailySendCommand extends Command
{
    protected $signature = 'report:send-daily {--now : Send immediately without changing the daily schedule}';

    protected $description = 'Send the daily XCrawler status report for the last 24 hours.';

    public function handle(PeriodicReportService $reports): int
    {
        $slot = (bool) $this->option('now') ? null : CarbonImmutable::now()->startOfMinute();
        $result = $slot === null ? $reports->sendDailyNow() : $reports->sendDailyScheduled($slot);
        if ($result['text'] !== '') {
            $this->line($result['text']);
        }
        if ($result['duplicate']) {
            $this->components->info('This daily report slot has already been handled.');

            return self::SUCCESS;
        }
        if (! $result['sent']) {
            $this->components->error('The daily report was not delivered.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
