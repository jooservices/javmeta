<?php

declare(strict_types=1);

namespace App\Services\Alerts;

/** Maps an alert rule and its measured condition to the named values its notification template expects. */
final class AlertTemplateValues
{
    /**
     * @param array{severity: string, value: float|int, threshold: float|int, context: array<string, mixed>} $condition
     * @return array<string, int|float|string>
     */
    public function for(string $rule, array $condition, bool $resolved): array
    {
        $context = $condition['context'];
        $window = max(1, (int) ($context['window_minutes'] ?? config('javmeta_alerts.window_minutes', 15)));

        return match (true) {
            $rule === 'success_rate_critical' => $this->criticalSuccessRate($condition, $window),
            $rule === 'success_rate' => $this->successRate($condition, $context, $window),
            $rule === 'blocked_ratio' => $this->blockedRatio($condition, $context, $window),
            str_starts_with($rule, 'source_success_rate:') => $this->sourceSuccessRate($rule, $condition, $context, $window),
            $rule === 'postgres_connections' => ['connection_count' => $condition['value'], 'threshold' => $condition['threshold']],
            $rule === 'dispatch_age' => $this->dispatchAge($condition, $context, $resolved),
            str_starts_with($rule, 'backlog_depth:') => $this->backlogDepth($rule, $condition, $context),
            default => [],
        };
    }

    /**
     * @param array{severity: string, value: float|int, threshold: float|int, context: array<string, mixed>} $condition
     * @return array<string, int|float|string>
     */
    private function criticalSuccessRate(array $condition, int $window): array
    {
        return [
            'success_rate' => $condition['value'],
            'threshold' => $condition['threshold'],
            'window_minutes' => $window,
        ];
    }

    /**
     * @param array{severity: string, value: float|int, threshold: float|int, context: array<string, mixed>} $condition
     * @param array<string, mixed> $context
     * @return array<string, int|float|string>
     */
    private function successRate(array $condition, array $context, int $window): array
    {
        return [
            'success_rate' => $condition['value'],
            'minimum_rate' => $condition['threshold'],
            'window_minutes' => $window,
            'blocked_ratio' => $context['blocked_ratio'] ?? '',
            'maximum_blocked_ratio' => $context['maximum_blocked_ratio'] ?? config('javmeta_alerts.blocked_ratio_max_percent', 10),
            'attempts' => $context['attempts'] ?? '',
            'worst_sources' => $context['worst_sources'] ?? '',
        ];
    }

    /**
     * @param array{severity: string, value: float|int, threshold: float|int, context: array<string, mixed>} $condition
     * @param array<string, mixed> $context
     * @return array<string, int|float|string>
     */
    private function blockedRatio(array $condition, array $context, int $window): array
    {
        return [
            'blocked_ratio' => $condition['value'],
            'maximum_ratio' => $condition['threshold'],
            'window_minutes' => $window,
            'attempts' => $context['attempts'] ?? '',
            'worst_sources' => $context['worst_sources'] ?? '',
        ];
    }

    /**
     * @param array{severity: string, value: float|int, threshold: float|int, context: array<string, mixed>} $condition
     * @param array<string, mixed> $context
     * @return array<string, int|float|string>
     */
    private function sourceSuccessRate(string $rule, array $condition, array $context, int $window): array
    {
        return [
            'source_slug' => $context['source_slug'] ?? substr($rule, strlen('source_success_rate:')),
            'success_rate' => $condition['value'],
            'minimum_rate' => $condition['threshold'],
            'window_minutes' => $window,
            'attempts' => $context['attempts'] ?? '',
        ];
    }

    /**
     * @param array{severity: string, value: float|int, threshold: float|int, context: array<string, mixed>} $condition
     * @param array<string, mixed> $context
     * @return array<string, int|float|string>
     */
    private function dispatchAge(array $condition, array $context, bool $resolved): array
    {
        $idle = (bool) ($context['idle'] ?? false);
        $missing = (bool) ($context['missing'] ?? false);
        $state = match (true) {
            $idle => 'no crawl work is due',
            $missing => 'no dispatch has been recorded',
            $resolved => 'dispatch has resumed',
            default => 'due work is waiting for dispatch',
        };
        $age = match (true) {
            $idle => 'not applicable',
            $missing => 'not recorded',
            default => number_format((float) $condition['value'], 0, '.', ',') . ' seconds ago',
        };

        return [
            'dispatch_state' => $state,
            'dispatch_age' => $age,
            'threshold_seconds' => $condition['threshold'],
            'due_items' => $context['due_items'] ?? '',
        ];
    }

    /**
     * @param array{severity: string, value: float|int, threshold: float|int, context: array<string, mixed>} $condition
     * @param array<string, mixed> $context
     * @return array<string, int|float|string>
     */
    private function backlogDepth(string $rule, array $condition, array $context): array
    {
        return [
            'source_slug' => $context['source_slug'] ?? substr($rule, strlen('backlog_depth:')),
            'due_items' => $condition['value'],
            'minimum_items' => $condition['threshold'],
        ];
    }
}
