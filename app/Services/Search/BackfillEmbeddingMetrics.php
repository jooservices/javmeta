<?php

declare(strict_types=1);

namespace App\Services\Search;

final class BackfillEmbeddingMetrics
{
    /**
     * @param array<string, int> $missingByEntity
     * @param array<string, int> $fixedByEntity
     * @return list<array{name: string, value: int, labels: array{entity: string}}>
     */
    public static function points(array $missingByEntity, array $fixedByEntity): array
    {
        $points = [];
        foreach ($missingByEntity as $entity => $missing) {
            $points[] = [
                'name' => 'javmeta_search_embedding_backfill_missing',
                'value' => $missing,
                'labels' => ['entity' => (string) $entity],
            ];
            $points[] = [
                'name' => 'javmeta_search_embedding_backfill_fixed',
                'value' => $fixedByEntity[$entity] ?? 0,
                'labels' => ['entity' => (string) $entity],
            ];
        }

        return $points;
    }
}
