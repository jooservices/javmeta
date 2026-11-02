<?php

declare(strict_types=1);

namespace App\Services\Alerts;

final class AlertMessageFormatter
{
    /**
     * @param array{severity: string, value: float|int, threshold: float|int, context: array<string, mixed>} $condition
     * @return array{subject: string, body: string, context: array<string, mixed>}
     */
    public function format(string $rule, array $condition, bool $resolved = false): array
    {
        $label = $this->label($rule);
        $subject = $label;
        $body = $this->body($rule, $condition, $resolved);
        $context = $condition['context'] + [
            'alert_state' => $resolved ? 'resolved' : 'open',
            'severity' => $resolved ? 'info' : $condition['severity'],
            'rule' => $rule,
            'value' => $condition['value'],
            'threshold' => $condition['threshold'],
        ];

        return ['subject' => $subject, 'body' => $body, 'context' => $context];
    }

    private function label(string $rule): string
    {
        if (str_starts_with($rule, 'source_success_rate:')) {
            return 'Source ' . substr($rule, strlen('source_success_rate:')) . ' is performing poorly';
        }
        if (str_starts_with($rule, 'backlog_depth:')) {
            return 'Source ' . substr($rule, strlen('backlog_depth:')) . ' is running out of due work';
        }

        return match ($rule) {
            'success_rate' => 'Crawl success rate is low',
            'blocked_ratio' => 'Blocked request ratio is high',
            'postgres_connections' => 'Database connections are high',
            'dispatch_age' => 'Crawl dispatch is delayed',
            default => 'Operational alert',
        };
    }

    /** @param array{severity: string, value: float|int, threshold: float|int, context: array<string, mixed>} $condition */
    private function body(string $rule, array $condition, bool $resolved): string
    {
        $prefix = $resolved ? 'Back to normal. ' : '';
        $value = $condition['value'];
        $threshold = $condition['threshold'];

        if (in_array($rule, ['success_rate', 'blocked_ratio'], true) || str_starts_with($rule, 'source_success_rate:')) {
            $window = max(1, (int) ($condition['context']['window_minutes'] ?? config('javmeta_alerts.window_minutes', 15)));
            $rate = $this->number($value) . '%';
            $limit = $this->number($threshold) . '%';
            $name = $rule === 'blocked_ratio' ? 'Blocked' : 'Success';
            $comparison = $rule === 'blocked_ratio' ? 'maximum' : 'minimum';
            $body = "{$prefix}{$name}: {$rate} ({$comparison} {$limit}) over the last {$window} minutes.";
            $sources = array_values(array_filter(array_map('strval', (array) ($condition['context']['top_sources'] ?? []))));
            if ($sources !== []) {
                $body .= ' Sources to watch: ' . implode(', ', $sources) . '.';
            }

            return $body;
        }

        if ($rule === 'postgres_connections') {
            return "{$prefix}There are currently {$this->number($value)} PostgreSQL connections (threshold {$this->number($threshold)}).";
        }

        if ($rule === 'dispatch_age') {
            if ((bool) ($condition['context']['idle'] ?? false)) {
                return "{$prefix}No crawl work is due right now.";
            }

            if ((bool) ($condition['context']['missing'] ?? false)) {
                return "{$prefix}Work is due but no new dispatch has been recorded (threshold {$this->number($threshold)} seconds).";
            }

            return "{$prefix}The last dispatch was {$this->number($value)} seconds ago (threshold {$this->number($threshold)} seconds).";
        }

        if (str_starts_with($rule, 'backlog_depth:')) {
            $source = substr($rule, strlen('backlog_depth:'));

            return "{$prefix}Source {$source} has {$this->number($value)} due items (minimum {$this->number($threshold)}).";
        }

        return $prefix . 'Current value ' . $this->number($value) . ' (threshold ' . $this->number($threshold) . ').';
    }

    private function number(float|int $value): string
    {
        return number_format((float) $value, 1, '.', ',');
    }
}
