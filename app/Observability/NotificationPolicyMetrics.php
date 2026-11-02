<?php

declare(strict_types=1);

namespace App\Observability;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class NotificationPolicyMetrics
{
    public function sent(string $tier, string $key): void
    {
        $this->increment('sent:' . $tier . ':' . $key, MetricStreams::NAMES['notifications_sent_total'], $tier, $key, null);
    }

    public function suppressed(string $reason): void
    {
        $this->increment('suppressed:' . $reason, MetricStreams::NAMES['notifications_suppressed_total'], null, null, $reason);
    }

    /** @return list<array{name: string, value: int, labels: array<string, string>}> */
    public function points(): array
    {
        if (! Schema::hasTable('notification_policy_metric_counters')) {
            return [];
        }

        $points = [];
        foreach (DB::table('notification_policy_metric_counters')->orderBy('counter_key')->get() as $row) {
            $labels = $row->notification_key !== null
                ? ['tier' => (string) $row->tier, 'key' => (string) $row->notification_key]
                : ['reason' => (string) $row->reason];
            $points[] = ['name' => (string) $row->metric, 'value' => (int) $row->value, 'labels' => $labels];
        }

        return $points;
    }

    private function increment(string $counterKey, string $metric, ?string $tier, ?string $key, ?string $reason): void
    {
        if (! Schema::hasTable('notification_policy_metric_counters')) {
            return;
        }
        DB::table('notification_policy_metric_counters')->insertOrIgnore([
            'counter_key' => $counterKey,
            'metric' => $metric,
            'tier' => $tier,
            'notification_key' => $key,
            'reason' => $reason,
            'value' => 0,
            'updated_at' => now(),
        ]);
        DB::table('notification_policy_metric_counters')->where('counter_key', $counterKey)->increment('value', 1, ['updated_at' => now()]);
    }
}
