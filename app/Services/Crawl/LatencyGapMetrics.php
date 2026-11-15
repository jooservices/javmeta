<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Observability\OpenObserveClient;
use Throwable;

final class LatencyGapMetrics
{
    private const METRIC_NAME = 'javmeta_source_gap_changes_total';

    public function __construct(private readonly OpenObserveClient $client) {}

    public function recordChange(string $reason, string $direction): void
    {
        if (! in_array([$reason, $direction], [
            ['latency_high', 'increase'],
            ['latency_healthy', 'decrease'],
        ], true)) {
            return;
        }

        try {
            $this->client->ingestMetrics([[
                'name' => self::METRIC_NAME,
                'value' => 1,
                'labels' => [
                    'reason' => $reason,
                    'direction' => $direction,
                ],
            ]]);
        } catch (Throwable) {
            // Metrics are best effort and must never interrupt a crawl.
        }
    }
}
