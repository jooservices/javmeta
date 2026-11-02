<?php

declare(strict_types=1);

namespace App\Observability;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read cumulative, bounded notification delivery counts for the periodic metrics publisher. */
final class NotificationMetrics
{
    /** @return list<array{name: string, value: int, labels: array{notification_key: string, channel: string}}> */
    public function points(): array
    {
        if (! Schema::hasTable('notification_templates')) {
            return [];
        }

        return DB::table('notification_templates')
            ->where('sent_count', '>', 0)
            ->orderBy('key')
            ->get(['key', 'channel', 'sent_count'])
            ->map(static fn(object $row): array => [
                'name' => MetricStreams::NAMES['notification_sent_total'],
                'value' => (int) $row->sent_count,
                'labels' => ['notification_key' => (string) $row->key, 'channel' => (string) $row->channel],
            ])
            ->all();
    }
}
