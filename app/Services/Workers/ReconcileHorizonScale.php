<?php

declare(strict_types=1);

namespace App\Services\Workers;

use Laravel\Horizon\Supervisor;

final class ReconcileHorizonScale
{
    /** @param array<string, mixed> $options */
    public function process(Supervisor $supervisor, array $options): void
    {
        $processes = max(1, (int) ($options['scale'] ?? 1));
        $supervisor->scale($processes);
        $supervisor->options->maxProcesses = $processes;
    }
}
