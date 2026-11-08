<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Alerts\AlertEvaluationService;
use Illuminate\Console\Command;

final class AlertsEvaluateCommand extends Command
{
    protected $signature = 'alerts:evaluate';

    protected $description = 'Evaluate crawl safety thresholds and notify configured channels.';

    public function handle(AlertEvaluationService $evaluation): int
    {
        if (! $evaluation->evaluate()) {
            $this->components->info('Alert evaluation skipped; alerts are disabled or the interval has not elapsed.');

            return self::SUCCESS;
        }

        $this->components->info('Alert rules evaluated.');

        return self::SUCCESS;
    }
}
