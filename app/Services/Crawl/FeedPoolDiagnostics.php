<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Observability\MetricStreams;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

final class FeedPoolDiagnostics
{
    private const KEY_PREFIX = 'javmeta:feed-pool:diagnostics:';

    /**
     * @param  array<string, array{budget: int, started: int, waiting: int}>  $sources
     * @param  array<string, int>  $dispatchedBySource
     * @param  list<array{queue: string, lane: string, reason: string}>  $stopReasons
     */
    public function recordRun(
        array $sources,
        array $dispatchedBySource,
        array $stopReasons,
        int $runMs,
        int $claimRowsExamined,
    ): void {
        try {
            if (! (bool) config('javmeta_queues.feed_diagnostics.enabled', true)) {
                return;
            }

            $minute = CarbonImmutable::now()->startOfMinute();
            $key = self::KEY_PREFIX . $minute->timestamp;
            $retentionSeconds = $this->retentionMinutes() * 60;
            $connection = Redis::connection((string) config('horizon.use', 'default'));

            $connection->pipeline();
            try {
                foreach ($sources as $slug => $snapshot) {
                    $sourceKey = rawurlencode((string) $slug);
                    $connection->hset($key, "s|{$sourceKey}|budget", max(0, $snapshot['budget']));
                    $connection->hset($key, "s|{$sourceKey}|started", max(0, $snapshot['started']));
                    $connection->hset($key, "s|{$sourceKey}|waiting", max(0, $snapshot['waiting']));
                    $connection->hincrby($key, "s|{$sourceKey}|dispatched", max(0, $dispatchedBySource[$slug] ?? 0));
                }

                foreach ($stopReasons as $stop) {
                    $queue = rawurlencode($stop['queue']);
                    $connection->hincrby($key, "q|{$queue}|{$stop['lane']}|{$stop['reason']}", 1);
                }

                $connection->hincrby($key, 'run_ms_total', max(0, $runMs));
                $connection->hincrby($key, 'run_count', 1);
                $connection->hincrby($key, 'claim_rows_examined', max(0, $claimRowsExamined));
                $connection->expire($key, $retentionSeconds);
                $connection->exec();
            } catch (Throwable $error) {
                try {
                    $connection->discard();
                } catch (Throwable) {
                    // The connection may have already reset after a pipeline failure.
                }

                throw $error;
            }
        } catch (Throwable) {
            // Diagnostics must not delay or interrupt feed dispatch.
        }
    }

    /**
     * @return array{
     *     window_minutes: int,
     *     sources: array<string, array{budget: int, started: int, waiting: int, dispatched: int}>,
     *     stop_reasons: list<array{queue: string, lane: string, reason: string, count: int}>,
     *     run_ms_total: int,
     *     run_count: int,
     *     claim_rows_examined: int
     * }
     */
    public function report(int $minutes): array
    {
        try {
            if (! (bool) config('javmeta_queues.feed_diagnostics.enabled', true)) {
                return $this->emptyReport(0);
            }

            $minutes = max(1, min($minutes, $this->retentionMinutes()));
            $sources = [];
            $stopReasons = [];
            $runMsTotal = 0;
            $runCount = 0;
            $claimRowsExamined = 0;
            $currentMinute = CarbonImmutable::now()->startOfMinute();

            for ($offset = $minutes - 1; $offset >= 0; $offset--) {
                $bucket = $this->readBucket($currentMinute->subMinutes($offset));
                foreach ($bucket as $field => $value) {
                    $parts = explode('|', (string) $field);
                    if ($parts[0] === 's' && count($parts) === 3) {
                        $slug = rawurldecode($parts[1]);
                        $metric = $parts[2];
                        if (in_array($metric, ['budget', 'started', 'waiting', 'dispatched'], true)) {
                            $sources[$slug] ??= ['budget' => 0, 'started' => 0, 'waiting' => 0, 'dispatched' => 0];
                            if ($metric === 'dispatched') {
                                $sources[$slug][$metric] += (int) $value;
                            } else {
                                $sources[$slug][$metric] = (int) $value;
                            }
                        }

                        continue;
                    }

                    if ($parts[0] === 'q' && count($parts) === 4) {
                        $queue = rawurldecode($parts[1]);
                        $reasonKey = $queue . '|' . $parts[2] . '|' . $parts[3];
                        $stopReasons[$reasonKey] ??= [
                            'queue' => $queue,
                            'lane' => $parts[2],
                            'reason' => $parts[3],
                            'count' => 0,
                        ];
                        $stopReasons[$reasonKey]['count'] += (int) $value;

                        continue;
                    }

                    if ($field === 'run_ms_total') {
                        $runMsTotal += (int) $value;
                    } elseif ($field === 'run_count') {
                        $runCount += (int) $value;
                    } elseif ($field === 'claim_rows_examined') {
                        $claimRowsExamined += (int) $value;
                    }
                }
            }

            ksort($sources);
            ksort($stopReasons);

            return [
                'window_minutes' => $minutes,
                'sources' => $sources,
                'stop_reasons' => array_values($stopReasons),
                'run_ms_total' => $runMsTotal,
                'run_count' => $runCount,
                'claim_rows_examined' => $claimRowsExamined,
            ];
        } catch (Throwable $error) {
            throw new RuntimeException('Unable to read feed diagnostics from Redis.', 0, $error);
        }
    }

    /** @return list<array{name: string, value: float|int, labels?: array<string, string>}> */
    public function metricPointsForMinute(CarbonImmutable $minute): array
    {
        try {
            if (! (bool) config('javmeta_queues.feed_diagnostics.enabled', true)) {
                return [];
            }

            $bucket = $this->reportForMinute($minute->startOfMinute());
            $points = [];
            foreach ($bucket['sources'] as $slug => $source) {
                $labels = ['source_slug' => $slug];
                foreach ([
                    'budget' => 'feed_source_budget',
                    'started' => 'feed_source_started',
                    'waiting' => 'feed_source_waiting',
                    'dispatched' => 'feed_source_dispatched_1m',
                ] as $field => $name) {
                    $points[] = [
                        'name' => MetricStreams::NAMES[$name],
                        'value' => $source[$field],
                        'labels' => $labels,
                    ];
                }
            }

            foreach ($bucket['stop_reasons'] as $stop) {
                $points[] = [
                    'name' => MetricStreams::NAMES['feed_stop_reason_1m'],
                    'value' => $stop['count'],
                    'labels' => ['queue' => $stop['queue'], 'lane' => $stop['lane'], 'reason' => $stop['reason']],
                ];
            }

            if ($bucket['run_count'] > 0) {
                $points[] = [
                    'name' => MetricStreams::NAMES['feed_run_ms'],
                    'value' => $bucket['run_ms_total'] / $bucket['run_count'],
                ];
            }
            if ($bucket['run_count'] > 0) {
                $points[] = [
                    'name' => MetricStreams::NAMES['feed_claim_rows_examined_1m'],
                    'value' => $bucket['claim_rows_examined'],
                ];
            }

            return $points;
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string, string|int> */
    private function readBucket(CarbonImmutable $minute): array
    {
        $key = self::KEY_PREFIX . $minute->timestamp;
        $value = Redis::connection((string) config('horizon.use', 'default'))->hgetall($key);

        return is_array($value) ? $value : [];
    }

    /**
     * @return array{
     *     sources: array<string, array{budget: int, started: int, waiting: int, dispatched: int}>,
     *     stop_reasons: list<array{queue: string, lane: string, reason: string, count: int}>,
     *     run_ms_total: int,
     *     run_count: int,
     *     claim_rows_examined: int
     * }
     */
    private function reportForMinute(CarbonImmutable $minute): array
    {
        $aggregate = $this->emptyReport(1);
        $sources = [];
        $stopReasons = [];
        foreach ($this->readBucket($minute) as $field => $value) {
            $parts = explode('|', (string) $field);
            if ($parts[0] === 's' && count($parts) === 3) {
                $slug = rawurldecode($parts[1]);
                $metric = $parts[2];
                if (in_array($metric, ['budget', 'started', 'waiting', 'dispatched'], true)) {
                    $sources[$slug] ??= ['budget' => 0, 'started' => 0, 'waiting' => 0, 'dispatched' => 0];
                    $sources[$slug][$metric] = (int) $value;
                }
            } elseif ($parts[0] === 'q' && count($parts) === 4) {
                $queue = rawurldecode($parts[1]);
                $key = $queue . '|' . $parts[2] . '|' . $parts[3];
                $stopReasons[$key] = [
                    'queue' => $queue,
                    'lane' => $parts[2],
                    'reason' => $parts[3],
                    'count' => (int) $value,
                ];
            } elseif ($field === 'run_ms_total') {
                $aggregate['run_ms_total'] = (int) $value;
            } elseif ($field === 'run_count') {
                $aggregate['run_count'] = (int) $value;
            } elseif ($field === 'claim_rows_examined') {
                $aggregate['claim_rows_examined'] = (int) $value;
            }
        }

        $aggregate['sources'] = $sources;
        $aggregate['stop_reasons'] = array_values($stopReasons);

        return $aggregate;
    }

    private function retentionMinutes(): int
    {
        return max(3, min(1440, (int) config('javmeta_queues.feed_diagnostics.retention_minutes', 120)));
    }

    /** @return array{window_minutes: int, sources: array, stop_reasons: list<array>, run_ms_total: int, run_count: int, claim_rows_examined: int} */
    private function emptyReport(int $minutes): array
    {
        return [
            'window_minutes' => $minutes,
            'sources' => [],
            'stop_reasons' => [],
            'run_ms_total' => 0,
            'run_count' => 0,
            'claim_rows_examined' => 0,
        ];
    }
}
