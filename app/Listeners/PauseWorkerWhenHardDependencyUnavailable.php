<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\Dependencies\DependencyMonitor;
use Illuminate\Queue\Events\Looping;

final class PauseWorkerWhenHardDependencyUnavailable
{
    public function __construct(private readonly DependencyMonitor $dependencies) {}

    public function handle(Looping $event): bool
    {
        foreach ($this->dependencies->statuses() as $status) {
            if ($status['hard'] && ! $status['available']) {
                return false;
            }
        }

        return true;
    }
}
