<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Observability\MetricStreams;
use Illuminate\Support\Facades\Cache;

final class ReportMetrics
{
    /** @param 'sent'|'failed' $result */
    public function record(string $result, ?int $sentAt = null): void
    {
        $key = 'javmeta:report:sent-total:' . $result;
        Cache::add($key, 0, now()->addYears(5));
        Cache::increment($key);
        if ($result === 'sent' && $sentAt !== null) {
            Cache::put('javmeta:report:last-sent', $sentAt, now()->addYears(5));
        }
    }

    /** @return list<array{name: string, value: int, labels: array{result: string}}|array{name: string, value: int}> */
    public function points(): array
    {
        $points = [];
        foreach (['sent', 'failed'] as $result) {
            $points[] = [
                'name' => MetricStreams::NAMES['report_sent_total'],
                'value' => (int) Cache::get('javmeta:report:sent-total:' . $result, 0),
                'labels' => ['result' => $result],
            ];
        }
        $points[] = [
            'name' => MetricStreams::NAMES['report_last_sent_timestamp_seconds'],
            'value' => (int) Cache::get('javmeta:report:last-sent', 0),
        ];

        return $points;
    }
}
