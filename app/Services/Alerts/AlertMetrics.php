<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Observability\MetricStreams;
use App\Repositories\AlertOperationsServiceRepository;

final class AlertMetrics
{
    public function __construct(private readonly AlertOperationsServiceRepository $repository) {}

    /** @return list<array{name: string, value: int, labels?: array<string, string>}> */
    public function points(): array
    {
        $activeRules = $this->repository->activeRules();
        $knownRules = ['success_rate', 'blocked_ratio', 'postgres_connections', 'dispatch_age'];
        foreach ($this->repository->enabledSourceSlugs() as $source) {
            $knownRules[] = 'source_success_rate:' . $source;
            $knownRules[] = 'backlog_depth:' . $source;
        }
        $rules = array_values(array_unique(array_merge($knownRules, $activeRules)));
        $points = [];

        foreach ($rules as $rule) {
            $points[] = [
                'name' => MetricStreams::NAMES['alert_firing'],
                'value' => in_array($rule, $activeRules, true) ? 1 : 0,
                'labels' => ['rule' => $rule],
            ];
        }

        return array_merge($points, $this->eventCountPoints());
    }

    /** @return list<array{name: string, value: int, labels: array{state: string, severity: string}}> */
    private function eventCountPoints(): array
    {
        $retentionDays = max(1, (int) config('javmeta_alerts.history_retention_days', 90));
        $counts = $this->repository->recentEventCounts(now()->subDays($retentionDays));

        $points = [];
        foreach (['firing', 'resolved'] as $state) {
            foreach (['warning', 'critical', 'info'] as $severity) {
                $points[] = [
                    'name' => MetricStreams::NAMES['alert_event_count'],
                    'value' => $counts[$state . ':' . $severity] ?? 0,
                    'labels' => ['state' => $state, 'severity' => $severity],
                ];
            }
        }

        return $points;
    }
}
