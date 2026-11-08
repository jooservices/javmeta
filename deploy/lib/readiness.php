<?php

declare(strict_types=1);

namespace JavMeta\Deploy;

use App\Services\Health\SchedulerHeartbeat;
use Illuminate\Contracts\Console\Kernel;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/** @param list<object> $masters */
function runtimeReady(array $masters, bool $checkScheduler, bool $heartbeatFresh): bool
{
    if ($masters === []) {
        return false;
    }
    foreach ($masters as $master) {
        if (($master->status ?? null) !== 'running') {
            return false;
        }
    }

    return ! $checkScheduler || $heartbeatFresh;
}

if (realpath($_SERVER['SCRIPT_FILENAME']) !== __FILE__) {
    return;
}

try {
    require getcwd() . '/vendor/autoload.php';
    $app = require getcwd() . '/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $scheduler = ($argv[1] ?? '0') === '1';
    $ready = runtimeReady($app->make(MasterSupervisorRepository::class)->all(), $scheduler, $scheduler && $app->make(SchedulerHeartbeat::class)->isFresh());
    exit($ready ? 0 : 1);
} catch (Throwable) {
    exit(1);
}
