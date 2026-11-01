<?php

declare(strict_types=1);

namespace App\Services\Observability\Pruning;

use RuntimeException;

/** Stream statistics lag behind the searchable in-memory buffer. Verify both views. */
final class StreamSnapshot
{
    public function __construct(private readonly PruneApi $api) {}

    /** @param list<array<string, mixed>> $candidates */
    public function verify(array $candidates): void
    {
        foreach ($candidates as $candidate) {
            $body = $this->api->request('POST', $this->api->orgPath() . '/_search?type=' . $candidate['type'], ['query' => [
                'sql' => 'SELECT COUNT(*) AS num, MIN(_timestamp) AS oldest, MAX(_timestamp) AS newest FROM "' . $candidate['name'] . '"',
                'start_time' => 1, 'end_time' => max((int) $candidate['max'] + 1, (now()->getTimestamp() + 86400) * 1_000_000),
                'from' => 0, 'size' => 1,
            ]]);
            $hit = $body['hits'][0] ?? null;
            if (! empty($body['is_partial']) || ! empty($body['function_error']) || ! empty($body['error']) || ! is_array($hit) || ! isset($hit['num'])) {
                throw new RuntimeException('Cannot verify searchable stream statistics.');
            }
            if ((int) $hit['num'] !== $candidate['count'] || ($candidate['count'] > 0 && ((int) ($hit['oldest'] ?? -1) !== $candidate['min'] || (int) ($hit['newest'] ?? -1) !== $candidate['max']))) {
                throw new RuntimeException('Searchable state differs from stream statistics; wait for ingestion to settle.');
            }
        }
    }
}
