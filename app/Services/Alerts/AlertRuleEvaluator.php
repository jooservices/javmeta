<?php

declare(strict_types=1);

namespace App\Services\Alerts;

final class AlertRuleEvaluator
{
    /**
     * @param array{attempts_by_source: array<string, array{attempts: int, successes: int, blocked: int}>, enabled_sources: list<string>, hot_sources: list<string>, due_by_source: array<string, int>, postgres_connections: int|null, dispatch_age_seconds: int|null, has_due_work: bool} $snapshot
     * @return array{value: float|int, threshold: float|int, context: array<string, mixed>}|null
     */
    public function measurement(string $rule, array $snapshot): ?array
    {
        $source = null;
        if (str_contains($rule, ':')) {
            [$rule, $source] = explode(':', $rule, 2);
        }

        return match ($rule) {
            'success_rate' => $this->globalRateMeasurement($snapshot, 'successes', 'success_rate_min_percent'),
            'success_rate_critical' => $this->globalRateMeasurement($snapshot, 'successes', 'success_rate_critical_percent'),
            'blocked_ratio' => $this->globalRateMeasurement($snapshot, 'blocked', 'blocked_ratio_max_percent'),
            'source_success_rate' => $this->sourceRateMeasurement($source, $snapshot),
            'postgres_connections' => $snapshot['postgres_connections'] === null ? null : [
                'value' => $snapshot['postgres_connections'],
                'threshold' => max(1, (int) config('javmeta_alerts.pg_connections_max', 45)),
                'context' => [],
            ],
            'dispatch_age' => [
                'value' => $snapshot['has_due_work'] ? ($snapshot['dispatch_age_seconds'] ?? -1) : 0,
                'threshold' => max(1, (int) config('javmeta_alerts.dispatch_age_max_seconds', 60)),
                'context' => [
                    'idle' => ! $snapshot['has_due_work'],
                    'missing' => $snapshot['has_due_work'] && $snapshot['dispatch_age_seconds'] === null,
                    'due_items' => array_sum($snapshot['due_by_source']),
                ],
            ],
            'backlog_depth' => $source === null || ! in_array($source, $snapshot['hot_sources'], true) ? null : [
                'value' => $snapshot['due_by_source'][$source] ?? 0,
                'threshold' => max(0, (int) config('javmeta_alerts.backlog_due_min_per_hot_source', 10)),
                'context' => ['source_slug' => $source],
            ],
            default => null,
        };
    }

    /**
     * @param array{attempts_by_source: array<string, array{attempts: int, successes: int, blocked: int}>, enabled_sources: list<string>, hot_sources: list<string>, due_by_source: array<string, int>, postgres_connections: int|null, dispatch_age_seconds: int|null, has_due_work: bool} $snapshot
     * @return array<string, array{severity: string, value: float|int, threshold: float|int, context: array<string, mixed>}>
     */
    public function evaluate(array $snapshot): array
    {
        $rules = [];
        $minimumAttempts = max(1, (int) config('javmeta_alerts.min_attempts_in_window', 100));
        $totalAttempts = array_sum(array_column($snapshot['attempts_by_source'], 'attempts'));
        $totalSuccesses = array_sum(array_column($snapshot['attempts_by_source'], 'successes'));
        $totalBlocked = array_sum(array_column($snapshot['attempts_by_source'], 'blocked'));
        $successThreshold = (float) config('javmeta_alerts.success_rate_min_percent', 85);
        $criticalSuccessThreshold = (float) config('javmeta_alerts.success_rate_critical_percent', 70);
        $blockedThreshold = (float) config('javmeta_alerts.blocked_ratio_max_percent', 10);

        if ($totalAttempts >= $minimumAttempts) {
            $successRate = $this->percentage($totalSuccesses, $totalAttempts);
            if ($successRate < $criticalSuccessThreshold) {
                $rules['success_rate_critical'] = $this->condition($successRate, $criticalSuccessThreshold, $this->rateContext($snapshot, $minimumAttempts), 'critical');
            } elseif ($successRate < $successThreshold) {
                $rules['success_rate'] = $this->condition($successRate, $successThreshold, $this->rateContext($snapshot, $minimumAttempts));
            }

            $blockedRate = $this->percentage($totalBlocked, $totalAttempts);
            if ($blockedRate > $blockedThreshold) {
                $rules['blocked_ratio'] = $this->condition($blockedRate, $blockedThreshold, $this->rateContext($snapshot, $minimumAttempts));
            }
        }

        $this->sourceSuccessRules($snapshot, $minimumAttempts, $successThreshold, $rules);
        $this->capacityRules($snapshot, $rules);
        $this->backlogRules($snapshot, $rules);

        return $rules;
    }

    /**
     * @param array{attempts_by_source: array<string, array{attempts: int, successes: int, blocked: int}>, enabled_sources: list<string>, hot_sources: list<string>, due_by_source: array<string, int>, postgres_connections: int|null, dispatch_age_seconds: int|null, has_due_work: bool} $snapshot
     * @param array<string, array{severity: string, value: float|int, threshold: float|int, context: array<string, mixed>}> $rules
     */
    private function sourceSuccessRules(array $snapshot, int $minimumAttempts, float $threshold, array &$rules): void
    {
        foreach ($snapshot['enabled_sources'] as $source) {
            $stats = $snapshot['attempts_by_source'][$source] ?? null;
            if ($stats === null || $stats['attempts'] < $minimumAttempts) {
                continue;
            }

            $rate = $this->percentage($stats['successes'], $stats['attempts']);
            if ($rate < $threshold) {
                $rules['source_success_rate:' . $source] = $this->condition($rate, $threshold, [
                    'source_slug' => $source,
                    'window_minutes' => max(1, (int) config('javmeta_alerts.window_minutes', 15)),
                    'attempts' => $stats['attempts'],
                ]);
            }
        }
    }

    /**
     * @param array{attempts_by_source: array<string, array{attempts: int, successes: int, blocked: int}>, enabled_sources: list<string>, hot_sources: list<string>, due_by_source: array<string, int>, postgres_connections: int|null, dispatch_age_seconds: int|null, has_due_work: bool} $snapshot
     * @param array<string, array{severity: string, value: float|int, threshold: float|int, context: array<string, mixed>}> $rules
     */
    private function capacityRules(array $snapshot, array &$rules): void
    {
        $connections = $snapshot['postgres_connections'];
        $connectionsThreshold = max(1, (int) config('javmeta_alerts.pg_connections_max', 45));
        if ($connections !== null && $connections > $connectionsThreshold) {
            $rules['postgres_connections'] = $this->condition($connections, $connectionsThreshold, [], 'critical');
        }

        $dispatchAge = $snapshot['dispatch_age_seconds'];
        $dispatchThreshold = max(1, (int) config('javmeta_alerts.dispatch_age_max_seconds', 60));
        if ($snapshot['has_due_work'] && ($dispatchAge === null || $dispatchAge > $dispatchThreshold)) {
            $rules['dispatch_age'] = $this->condition($dispatchAge ?? -1, $dispatchThreshold, [
                'missing' => $dispatchAge === null,
                'due_items' => array_sum($snapshot['due_by_source']),
            ]);
        }
    }

    /**
     * @param array{attempts_by_source: array<string, array{attempts: int, successes: int, blocked: int}>, enabled_sources: list<string>, hot_sources: list<string>, due_by_source: array<string, int>, postgres_connections: int|null, dispatch_age_seconds: int|null, has_due_work: bool} $snapshot
     * @param array<string, array{severity: string, value: float|int, threshold: float|int, context: array<string, mixed>}> $rules
     */
    private function backlogRules(array $snapshot, array &$rules): void
    {
        $threshold = max(0, (int) config('javmeta_alerts.backlog_due_min_per_hot_source', 10));
        if ($threshold === 0) {
            return;
        }

        foreach ($snapshot['hot_sources'] as $source) {
            $due = $snapshot['due_by_source'][$source] ?? 0;
            if ($due < $threshold) {
                $rules['backlog_depth:' . $source] = $this->condition($due, $threshold, ['source_slug' => $source]);
            }
        }
    }

    /** @param array<string, mixed> $context
     *  @return array{severity: string, value: float|int, threshold: float|int, context: array<string, mixed>}
     */
    private function condition(float|int $value, float|int $threshold, array $context, string $severity = 'warning'): array
    {
        return ['severity' => $severity, 'value' => round($value, 1), 'threshold' => $threshold, 'context' => $context];
    }

    private function percentage(int $part, int $whole): float
    {
        return $part * 100 / max(1, $whole);
    }

    /**
     * @param array{attempts_by_source: array<string, array{attempts: int, successes: int, blocked: int}>, enabled_sources: list<string>, hot_sources: list<string>, due_by_source: array<string, int>, postgres_connections: int|null, dispatch_age_seconds: int|null, has_due_work: bool} $snapshot
     * @return array{value: float, threshold: float, context: array<string, mixed>}|null
     */
    private function globalRateMeasurement(array $snapshot, string $numerator, string $thresholdKey): ?array
    {
        $minimum = max(1, (int) config('javmeta_alerts.min_attempts_in_window', 100));
        $attempts = array_sum(array_column($snapshot['attempts_by_source'], 'attempts'));
        if ($attempts < $minimum) {
            return null;
        }
        $count = array_sum(array_column($snapshot['attempts_by_source'], $numerator));

        return [
            'value' => $this->percentage($count, $attempts),
            'threshold' => (float) config('javmeta_alerts.' . $thresholdKey),
            'context' => $this->rateContext($snapshot, $minimum),
        ];
    }

    /**
     * @param array{attempts_by_source: array<string, array{attempts: int, successes: int, blocked: int}>, enabled_sources: list<string>, hot_sources: list<string>, due_by_source: array<string, int>, postgres_connections: int|null, dispatch_age_seconds: int|null, has_due_work: bool} $snapshot
     * @return array{value: float, threshold: float, context: array<string, mixed>}|null
     */
    private function sourceRateMeasurement(?string $source, array $snapshot): ?array
    {
        if ($source === null || ! in_array($source, $snapshot['enabled_sources'], true)) {
            return null;
        }
        $stats = $snapshot['attempts_by_source'][$source] ?? null;
        if ($stats === null || $stats['attempts'] < max(1, (int) config('javmeta_alerts.min_attempts_in_window', 100))) {
            return null;
        }

        return [
            'value' => $this->percentage($stats['successes'], $stats['attempts']),
            'threshold' => (float) config('javmeta_alerts.success_rate_min_percent', 85),
            'context' => [
                'source_slug' => $source,
                'window_minutes' => max(1, (int) config('javmeta_alerts.window_minutes', 15)),
                'attempts' => $stats['attempts'],
            ],
        ];
    }

    /**
     * @param array{attempts_by_source: array<string, array{attempts: int, successes: int, blocked: int}>, enabled_sources: list<string>, hot_sources: list<string>, due_by_source: array<string, int>, postgres_connections: int|null, dispatch_age_seconds: int|null, has_due_work: bool} $snapshot
     * @return array{window_minutes: int, top_sources: list<string>}
     */
    private function rateContext(array $snapshot, int $minimumAttempts): array
    {
        $sources = [];
        $attempts = array_sum(array_column($snapshot['attempts_by_source'], 'attempts'));
        $blocked = array_sum(array_column($snapshot['attempts_by_source'], 'blocked'));
        foreach ($snapshot['attempts_by_source'] as $source => $stats) {
            if ($stats['attempts'] >= $minimumAttempts) {
                $sources[$source] = $this->percentage($stats['successes'], $stats['attempts']);
            }
        }
        asort($sources, SORT_NUMERIC);
        $worstSources = array_slice($sources, 0, 5, true);
        $worstSourceLabels = [];
        foreach ($worstSources as $source => $rate) {
            $worstSourceLabels[] = $source . ' ' . number_format($rate, 1, '.', ',') . '%';
        }

        return [
            'window_minutes' => max(1, (int) config('javmeta_alerts.window_minutes', 15)),
            'top_sources' => array_slice(array_map('strval', array_keys($sources)), 0, 3),
            'attempts' => $attempts,
            'blocked_ratio' => $blocked * 100 / max(1, $attempts),
            'maximum_blocked_ratio' => (float) config('javmeta_alerts.blocked_ratio_max_percent', 10),
            'worst_sources' => implode(', ', $worstSourceLabels),
        ];
    }
}
