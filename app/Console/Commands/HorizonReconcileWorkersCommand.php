<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Workers\HorizonWorkerReconciler;
use Illuminate\Console\Command;

final class HorizonReconcileWorkersCommand extends Command
{
    protected $signature = 'horizon:reconcile-workers';

    protected $description = 'Apply safe worker-count changes to this Horizon node and report layouts needing a manual restart.';

    public function handle(HorizonWorkerReconciler $reconciler): int
    {
        $result = $reconciler->reconcile();
        if ($result['status'] === 'restart-required') {
            $this->components->warn('Worker reconciliation: manual Horizon restart required.');

            return self::SUCCESS;
        }
        if ($result['status'] === 'connection-budget-exceeded') {
            $this->components->warn('Worker reconciliation: configured PostgreSQL connection budget exceeded; keeping the current layout.');

            return self::SUCCESS;
        }
        if (in_array($result['status'], ['invalid-settings', 'settings-unavailable'], true)) {
            $this->components->warn('Worker reconciliation: settings are invalid or unavailable; keeping the current layout.');

            return self::SUCCESS;
        }
        $this->components->info('Worker reconciliation: ' . $result['status'] . '.');

        return self::SUCCESS;
    }
}
