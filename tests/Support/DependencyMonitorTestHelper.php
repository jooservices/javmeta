<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Dependencies\Dependency;
use App\Services\Dependencies\DependencyMonitor;
use App\Services\Dependencies\DependencyProbe;

final class DependencyMonitorTestHelper
{
    /**
     * @param array<string, bool> $availability
     */
    public static function bind(array $availability = []): DependencyMonitor
    {
        $probes = [];
        foreach (Dependency::cases() as $dependency) {
            $probeAvailability = $availability[$dependency->value] ?? true;
            $probes[$dependency->value] = new class ($probeAvailability) implements DependencyProbe {
                public function __construct(private readonly bool $available) {}

                public function probe(): bool
                {
                    return $this->available;
                }
            };
        }

        $monitor = new DependencyMonitor($probes);
        app()->instance(DependencyMonitor::class, $monitor);

        return $monitor;
    }
}
