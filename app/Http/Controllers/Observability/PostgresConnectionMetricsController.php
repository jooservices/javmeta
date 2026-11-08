<?php

declare(strict_types=1);

namespace App\Http\Controllers\Observability;

use App\Observability\MetricStreams;
use App\Services\Workers\HorizonWorkerLayout;
use App\Services\Workers\PostgresConnectionBudget;
use Illuminate\Http\Response;

final class PostgresConnectionMetricsController
{
    public function __invoke(HorizonWorkerLayout $layout, PostgresConnectionBudget $budget): Response
    {
        $summary = $budget->summary($layout->configuredFleetWorkerCount());
        $metrics = [
            MetricStreams::NAMES['postgres_connection_demand'] => $summary['demand'],
            MetricStreams::NAMES['postgres_connection_role_limit'] => $summary['role_limit'],
            MetricStreams::NAMES['postgres_connection_budget_limit'] => $summary['budget_limit'],
            MetricStreams::NAMES['postgres_connection_demand_warning_threshold'] => $summary['warning_threshold'],
            MetricStreams::NAMES['postgres_connection_demand_warning'] => $summary['warning'] ? 1 : 0,
        ];
        $lines = [];
        foreach ($metrics as $name => $value) {
            $lines[] = '# TYPE ' . $name . ' gauge';
            $lines[] = $name . ' ' . (is_float($value) ? sprintf('%.1f', $value) : (string) $value);
        }

        return response(implode("\n", $lines) . "\n", 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
