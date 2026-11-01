<?php

declare(strict_types=1);

namespace App\Services\Observability\Pruning;

use RuntimeException;

final class StreamExport
{
    public function __construct(private readonly PruneApi $api, private readonly BackupSynchronizer $synchronizer) {}

    /** @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    public function export(array $candidates, string $directory, string $hash): array
    {
        // A new private directory prevents accidental overwrites and reuse of an existing leaf symlink.
        if ($directory === '' || file_exists($directory) || is_link($directory) || ! mkdir($directory, 0700) || ! chmod($directory, 0700)) {
            throw new RuntimeException('Export requires a new, writable directory.');
        }
        $files = [];
        foreach ($candidates as $candidate) {
            $files[] = $this->stream($candidate, $directory);
        }
        $manifest = json_encode(['org' => config('openobserve.org'), 'hash' => $hash, 'streams' => $files], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $manifestPath = $directory . '/manifest.json';
        if (file_put_contents($manifestPath, $manifest, LOCK_EX) !== strlen($manifest)) {
            throw new RuntimeException('Cannot persist export manifest.');
        }
        if (! chmod($manifestPath, 0600)) {
            throw new RuntimeException('Cannot secure export manifest.');
        }
        $this->synchronizer->sync($manifestPath);
        $this->synchronizer->sync($directory);
        $this->synchronizer->sync(dirname($directory));

        return $files;
    }

    /** @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    private function stream(array $candidate, string $directory): array
    {
        $path = $directory . '/' . $candidate['type'] . '-' . $candidate['name'] . '.ndjson.gz';
        $handle = gzopen($path, 'wb9');
        if ($handle === false) {
            throw new RuntimeException('Cannot open export file.');
        }
        if (! chmod($path, 0600)) {
            gzclose($handle);
            throw new RuntimeException('Cannot secure export file.');
        }
        $count = 0;
        try {
            // Half-open slices avoid duplicate boundary timestamps. Split dense slices recursively.
            // Snapshot verification already proved zero documents; OpenObserve rejects zero start times.
            $count = $candidate['count'] === 0 ? 0 : $this->slice($candidate, (int) $candidate['min'], (int) $candidate['max'] + 1, $handle);
        } finally {
            if (! gzclose($handle)) {
                throw new RuntimeException('Export close failed.');
            }
        }
        $verified = $this->recount($path);
        if ($count !== $candidate['count'] || $verified !== $candidate['count']) {
            throw new RuntimeException('Export document count mismatch; no deletion allowed.');
        }
        $this->synchronizer->sync($path);
        $checksum = hash_file('sha256', $path);
        if ($checksum === false) {
            throw new RuntimeException('Export checksum failed.');
        }

        $bytes = filesize($path);
        if ($bytes === false) {
            throw new RuntimeException('Export size check failed.');
        }

        return [...$candidate, 'path' => $path, 'bytes' => $bytes, 'sha256' => $checksum];
    }

    /** @param array<string, mixed> $candidate
     * @param resource $handle
     */
    private function slice(array $candidate, int $start, int $end, $handle): int
    {
        if ($end - $start > 86_400_000_000) {
            $middle = $start + intdiv($end - $start, 2);

            return $this->slice($candidate, $start, $middle, $handle) + $this->slice($candidate, $middle, $end, $handle);
        }
        $body = $this->api->request('POST', $this->api->orgPath() . '/_search?type=' . $candidate['type'], ['query' => [
            'sql' => 'SELECT * FROM "' . $candidate['name'] . '" WHERE _timestamp >= ' . $start . ' AND _timestamp < ' . $end . ' ORDER BY _timestamp',
            'start_time' => $start, 'end_time' => $end, 'from' => 0, 'size' => 1000,
        ]]);
        if (! empty($body['is_partial']) || ! empty($body['function_error']) || ! empty($body['error'])) {
            throw new RuntimeException('Partial or failed search export refused.');
        }
        $rows = $body;
        unset($rows['total']);
        $hits = $this->api->collection($rows, 'hits');
        $total = $body['total'] ?? null;
        if ($total !== null && (! is_numeric($total) || (int) $total != $total || (int) $total < count($hits))) {
            throw new RuntimeException('Search export returned inconsistent result totals.');
        }
        if (count($hits) >= 1000 || ($total !== null && (int) $total > count($hits))) {
            if ($end - $start <= 1) {
                throw new RuntimeException('Too many documents at one timestamp; export cannot prove completeness.');
            }
            $middle = $start + intdiv($end - $start, 2);

            return $this->slice($candidate, $start, $middle, $handle) + $this->slice($candidate, $middle, $end, $handle);
        }
        if ($total !== null && (int) $total !== count($hits)) {
            throw new RuntimeException('Search export result total does not match returned documents.');
        }
        foreach ($hits as $hit) {
            if (! isset($hit['_timestamp']) || $hit['_timestamp'] < $start || $hit['_timestamp'] >= $end) {
                throw new RuntimeException('Search returned an out-of-slice document.');
            }
            $line = json_encode($hit, JSON_THROW_ON_ERROR) . "\n";
            if (gzwrite($handle, $line) !== strlen($line)) {
                throw new RuntimeException('Incomplete export write.');
            }
        }

        return count($hits);
    }

    private function recount(string $path): int
    {
        $handle = gzopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot reopen export.');
        }
        $count = 0;
        try {
            while (($line = gzgets($handle)) !== false) {
                json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $count++;
            }
            if (! gzeof($handle)) {
                throw new RuntimeException('Incomplete export read.');
            }
        } finally {
            gzclose($handle);
        }

        return $count;
    }
}
