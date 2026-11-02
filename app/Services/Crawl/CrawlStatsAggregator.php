<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use Carbon\CarbonImmutable;

final class CrawlStatsAggregator
{
    /**
     * @param iterable<array<string, mixed>|object> $attempts
     * @return array{groups: list<array<string, mixed>>, hours: list<array<string, mixed>>}
     */
    public function aggregate(iterable $attempts): array
    {
        $groups = [];
        $hours = [];

        foreach ($attempts as $attempt) {
            $startedAt = CarbonImmutable::parse((string) $this->value($attempt, 'started_at'))->utc();
            $hour = $startedAt->startOfHour();
            $source = (string) $this->value($attempt, 'source_slug');
            $lane = (string) ($this->value($attempt, 'lane') ?: 'unknown');
            $method = (string) $this->value($attempt, 'method');
            $key = implode('|', [$hour->toDateTimeString(), $source, $lane, $method]);
            $class = self::classify([
                'outcome' => $this->value($attempt, 'outcome'),
                'http_status' => $this->value($attempt, 'http_status'),
                'cf_mitigated' => $this->value($attempt, 'cf_mitigated'),
                'error_code' => $this->value($attempt, 'error_code'),
            ]);
            $duration = max(0, (int) $this->value($attempt, 'duration_ms'));

            $groups[$key] ??= $this->newBucket($hour, $source, $lane, $method);
            $this->add($groups[$key], $class, $duration);
            $hourKey = $hour->toDateTimeString();
            $hours[$hourKey] ??= $this->newBucket($hour, '__global__', '__global__', '__global__');
            $this->add($hours[$hourKey], $class, $duration);
        }

        return [
            'groups' => array_values(array_map($this->finalize(...), $groups)),
            'hours' => array_values(array_map($this->finalize(...), $hours)),
        ];
    }

    /** @param array<string, mixed> $attempt */
    public static function classify(array $attempt): string
    {
        $outcome = (string) ($attempt['outcome'] ?? 'error');
        if ($outcome === 'skipped') {
            return 'skipped';
        }

        $status = (int) ($attempt['http_status'] ?? 0);
        $code = strtolower((string) ($attempt['error_code'] ?? ''));
        $challenge = trim((string) ($attempt['cf_mitigated'] ?? '')) !== ''
            || str_contains($code, 'cloudflare')
            || str_contains($code, 'challenge');

        if (in_array($status, [403, 429], true) || $challenge) {
            return 'blocked';
        }

        if (in_array($status, [408, 504, 522, 524, 598, 599], true)
            || str_contains($code, 'timeout')
            || str_contains($code, 'timed_out')) {
            return 'timeout';
        }

        return $outcome === 'ok' ? 'ok' : 'other_error';
    }

    /** @param list<int> $durations */
    public static function percentile90(array $durations): ?int
    {
        if ($durations === []) {
            return null;
        }

        sort($durations, SORT_NUMERIC);
        $index = (int) ceil(count($durations) * 0.9) - 1;

        return $durations[max(0, $index)];
    }

    /** @return array<string, mixed> */
    private function newBucket(CarbonImmutable $hour, string $source, string $lane, string $method): array
    {
        return [
            'hour_at' => $hour,
            'source_slug' => $source,
            'lane' => $lane,
            'method' => $method,
            'attempts' => 0,
            'ok' => 0,
            'blocked' => 0,
            'timeout' => 0,
            'other_error' => 0,
            'skipped' => 0,
            'duration_sum' => 0,
            'durations' => [],
        ];
    }

    /** @param array<string, mixed> $bucket */
    private function add(array &$bucket, string $class, int $duration): void
    {
        $bucket['attempts']++;
        $bucket[$class]++;
        $bucket['duration_sum'] += $duration;
        $bucket['durations'][] = $duration;
    }

    /** @param array<string, mixed> $bucket @return array<string, mixed> */
    private function finalize(array $bucket): array
    {
        $durations = $bucket['durations'];
        $attempts = (int) $bucket['attempts'];
        $durationSum = (int) $bucket['duration_sum'];
        unset($bucket['duration_sum'], $bucket['durations']);
        $bucket['avg_duration_ms'] = $attempts > 0 ? round($durationSum / $attempts, 2) : null;
        $bucket['p90_duration_ms'] = self::percentile90($durations);

        return $bucket;
    }

    private function value(array|object $row, string $key): mixed
    {
        return is_array($row) ? ($row[$key] ?? null) : ($row->{$key} ?? null);
    }
}
