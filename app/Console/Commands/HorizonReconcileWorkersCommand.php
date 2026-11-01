<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Workers\HorizonWorkerReconciler;
use Illuminate\Console\Command;

final class HorizonReconcileWorkersCommand extends Command
{
    protected $signature = 'horizon:reconcile-workers';

    protected $description = 'Compare the running Horizon layout with database settings and restart this node when needed.';

    public function handle(HorizonWorkerReconciler $reconciler): int
    {
        $result = $reconciler->reconcile(fn(): int => $this->call('horizon:terminate'));
        $this->components->info('Worker reconciliation: ' . $result['status'] . '.');

        return self::SUCCESS;
    }
}
