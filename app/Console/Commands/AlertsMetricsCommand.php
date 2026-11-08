<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Alerts\AlertMetrics;
use Illuminate\Console\Command;

final class AlertsMetricsCommand extends Command
{
    protected $signature = 'alerts:metrics';

    protected $description = 'Show current alert metrics and retained event counts.';

    public function handle(AlertMetrics $metrics): int
    {
        $this->table(
            ['Metric', 'Value', 'Labels'],
            array_map(static fn(array $point): array => [
                $point['name'],
                $point['value'],
                json_encode($point['labels'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            ], $metrics->points()),
        );

        return self::SUCCESS;
    }
}
