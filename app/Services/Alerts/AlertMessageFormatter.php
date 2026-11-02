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
        $subject = $resolved ? 'Đã ổn định: ' . $label : $label;
        $body = $this->body($rule, $condition, $resolved);
        $context = $condition['context'] + [
            'alert_state' => $resolved ? 'resolved' : 'open',
            'severity' => $condition['severity'],
            'rule' => $rule,
            'value' => $condition['value'],
            'threshold' => $condition['threshold'],
        ];

        return ['subject' => $subject, 'body' => $body, 'context' => $context];
    }

    private function label(string $rule): string
    {
        if (str_starts_with($rule, 'source_success_rate:')) {
            return 'Nguồn ' . substr($rule, strlen('source_success_rate:')) . ' tải chưa tốt';
        }
        if (str_starts_with($rule, 'backlog_depth:')) {
            return 'Nguồn ' . substr($rule, strlen('backlog_depth:')) . ' sắp hết việc đến hạn';
        }

        return match ($rule) {
            'success_rate' => 'Tỷ lệ crawl thành công thấp',
            'blocked_ratio' => 'Tỷ lệ yêu cầu bị chặn cao',
            'postgres_connections' => 'Kết nối cơ sở dữ liệu cao',
            'dispatch_age' => 'Hàng đợi crawl chờ điều phối',
            default => 'Cảnh báo vận hành',
        };
    }

    /** @param array{severity: string, value: float|int, threshold: float|int, context: array<string, mixed>} $condition */
    private function body(string $rule, array $condition, bool $resolved): string
    {
        $prefix = $resolved ? 'Hiện đã ổn định. ' : '';
        $value = $condition['value'];
        $threshold = $condition['threshold'];

        if (in_array($rule, ['success_rate', 'blocked_ratio'], true) || str_starts_with($rule, 'source_success_rate:')) {
            $window = max(1, (int) ($condition['context']['window_minutes'] ?? config('javmeta_alerts.window_minutes', 15)));
            $rate = $this->number($value) . '%';
            $limit = $this->number($threshold) . '%';
            $name = $rule === 'blocked_ratio' ? 'Bị chặn' : 'Thành công';
            $comparison = $rule === 'blocked_ratio' ? 'mức tối đa' : 'mức tối thiểu';
            $body = "{$prefix}{$name}: {$rate} ({$comparison} {$limit}) trong {$window} phút.";
            $sources = array_values(array_filter(array_map('strval', (array) ($condition['context']['top_sources'] ?? []))));
            if ($sources !== []) {
                $body .= ' Nguồn cần chú ý: ' . implode(', ', $sources) . '.';
            }

            return $body;
        }

        if ($rule === 'postgres_connections') {
            return "{$prefix}Hiện có {$this->number($value)} kết nối PostgreSQL (ngưỡng {$this->number($threshold)}).";
        }

        if ($rule === 'dispatch_age') {
            if ((bool) ($condition['context']['missing'] ?? false)) {
                return "{$prefix}Còn việc đến hạn nhưng chưa ghi nhận lượt điều phối mới (ngưỡng {$this->number($threshold)} giây).";
            }

            return "{$prefix}Lượt điều phối gần nhất cách đây {$this->number($value)} giây (ngưỡng {$this->number($threshold)} giây).";
        }

        if (str_starts_with($rule, 'backlog_depth:')) {
            $source = substr($rule, strlen('backlog_depth:'));

            return "{$prefix}Nguồn {$source} còn {$this->number($value)} việc đến hạn (mức tối thiểu {$this->number($threshold)}).";
        }

        return $prefix . 'Giá trị hiện tại ' . $this->number($value) . ' (ngưỡng ' . $this->number($threshold) . ').';
    }

    private function number(float|int $value): string
    {
        return number_format((float) $value, 1, '.', ',');
    }
}
