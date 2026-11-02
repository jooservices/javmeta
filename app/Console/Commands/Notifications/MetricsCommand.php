<?php

declare(strict_types=1);

namespace App\Console\Commands\Notifications;

use App\Observability\NotificationMetrics;
use Illuminate\Console\Command;

final class MetricsCommand extends Command
{
    protected $signature = 'notifications:metrics';

    protected $description = 'Show cumulative notification delivery and policy counters.';

    public function handle(NotificationMetrics $metrics): int
    {
        $this->table(
            ['Metric', 'Value', 'Labels'],
            array_map(static fn(array $point): array => [
                $point['name'],
                $point['value'],
                json_encode($point['labels'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            ], $metrics->points()),
        );

        return self::SUCCESS;
    }
}
