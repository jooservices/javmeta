<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Models\AlertEvent;
use App\Models\Source;
use App\Observability\MetricStreams;

final class AlertMetrics
{
    /** @return list<array{name: string, value: int, labels?: array<string, string>}> */
    public function points(): array
    {
        $activeRules = AlertEvent::query()->whereNull('resolved_at')->pluck('rule')->map('strval')->all();
        $knownRules = ['success_rate', 'blocked_ratio', 'postgres_connections', 'dispatch_age'];
        foreach (Source::query()->where('enabled', true)->orderBy('slug')->pluck('slug')->map('strval') as $source) {
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
        $rows = AlertEvent::query()
            ->where(static function ($query) use ($retentionDays): void {
                $query->whereNull('resolved_at')
                    ->orWhere('resolved_at', '>=', now()->subDays($retentionDays));
            })
            ->selectRaw("CASE WHEN resolved_at IS NULL THEN 'firing' ELSE 'resolved' END AS state, severity, COUNT(*) AS event_count")
            ->groupBy('state', 'severity')
            ->get();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row->getAttribute('state')][(string) $row->getAttribute('severity')] = (int) $row->getAttribute('event_count');
        }

        $points = [];
        foreach (['firing', 'resolved'] as $state) {
            foreach (['warning', 'critical', 'info'] as $severity) {
                $points[] = [
                    'name' => MetricStreams::NAMES['alert_event_count'],
                    'value' => $counts[$state][$severity] ?? 0,
                    'labels' => ['state' => $state, 'severity' => $severity],
                ];
            }
        }

        return $points;
    }
}
