<?php

declare(strict_types=1);

namespace App\Services\Observability\Pruning;

use App\Observability\MetricStreams;
use RuntimeException;

final class StreamPolicy
{
    /** @return list<string> */
    public function protectedNames(): array
    {
        // Keep the broad namespace protection and the current emitted metric names
        // explicit so new metric families remain visible in review and tests.
        return array_values(array_unique([
            'javmeta_', 'xcrawler_', MetricStreams::PREFIX,
            (string) config('openobserve.stream_logs'),
            (string) config('openobserve.stream_metrics'),
            ...array_values(MetricStreams::NAMES),
        ]));
    }

    public function validatePrefix(string $prefix): void
    {
        if (preg_match('/\A[a-z0-9]+_\z/D', $prefix) !== 1) {
            throw new RuntimeException('Prefix must match ^[a-z0-9]+_$.');
        }
        foreach ($this->protectedNames() as $protected) {
            if ($protected === '' || str_starts_with($protected, $prefix) || str_starts_with($prefix, $protected)) {
                throw new RuntimeException('Prefix overlaps a protected stream namespace.');
            }
        }
    }

    public function protected(string $name): bool
    {
        foreach ($this->protectedNames() as $protected) {
            if ($protected !== '' && str_starts_with($name, $protected)) {
                return true;
            }
        }

        return false;
    }

    public function matches(string $prefix, string $name): bool
    {
        return preg_match('/\A' . preg_quote($prefix, '/') . '[a-z0-9_]+\z/D', $name) === 1;
    }

    /** @param list<array<string, mixed>> $streams
     * @param list<string> $types
     * @return list<array{name: string, type: string, count: int, min: int, max: int, size: float}>
     */
    public function candidates(array $streams, string $prefix, array $types, int $days): array
    {
        $this->validatePrefix($prefix);
        $result = [];
        foreach ($streams as $stream) {
            $name = (string) ($stream['name'] ?? '');
            $type = (string) ($stream['stream_type'] ?? '');
            if (! in_array($type, $types, true) || ! $this->matches($prefix, $name)) {
                continue;
            }
            if ($this->protected($name)) {
                throw new RuntimeException('Protected candidate refused.');
            }
            $stats = $stream['stats'] ?? [];
            foreach (['doc_num', 'doc_time_min', 'doc_time_max', 'storage_size'] as $field) {
                if (! isset($stats[$field]) || ! is_numeric($stats[$field]) || $stats[$field] < 0) {
                    throw new RuntimeException('Missing or invalid candidate statistics.');
                }
            }
            if ((int) $stats['doc_num'] != $stats['doc_num'] || $stats['doc_time_min'] > $stats['doc_time_max']) {
                throw new RuntimeException('Inconsistent candidate statistics.');
            }
            $result[] = ['name' => $name, 'type' => $type, 'count' => (int) $stats['doc_num'],
                'min' => (int) $stats['doc_time_min'], 'max' => (int) $stats['doc_time_max'], 'size' => (float) $stats['storage_size']];
        }
        if (count($result) > 200) {
            throw new RuntimeException('More than 200 candidates refused.');
        }
        usort($result, static fn(array $left, array $right): int => [$left['type'], $left['name']] <=> [$right['type'], $right['name']]);

        return $result;
    }

    /** @param list<array<string, mixed>> $candidates */
    public function assertIdle(array $candidates, int $days): void
    {
        if ($days === 0) {
            return;
        }

        $recent = [];
        $cutoff = (now()->getTimestamp() - $days * 86400) * 1_000_000;
        foreach ($candidates as $candidate) {
            if ($candidate['max'] > $cutoff || ($candidate['count'] > 0 && $candidate['max'] <= 0)) {
                $recent[] = $candidate['name'];
            }
        }
        if ($recent !== []) {
            throw new RuntimeException('Recently written or unknown timestamp; skipped: ' . implode(', ', $recent));
        }
    }

    /** @param list<array<string, mixed>> $candidates */
    public function hash(array $candidates): string
    {
        $rows = array_map(static fn(array $row): array => ['type' => $row['type'], 'name' => $row['name'], 'count' => $row['count'], 'min' => $row['min'], 'max' => $row['max'], 'size' => $row['size']], $candidates);
        usort($rows, static fn(array $left, array $right): int => [$left['type'], $left['name']] <=> [$right['type'], $right['name']]);

        return hash('sha256', json_encode(['endpoint' => hash('sha256', (string) config('openobserve.url')), 'org' => config('openobserve.org'), 'streams' => $rows], JSON_THROW_ON_ERROR));
    }
}
