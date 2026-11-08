<?php

declare(strict_types=1);

namespace App\Observability;

use Illuminate\Support\Facades\DB;

/** Exposes cumulative normalization rejections with bounded Prometheus-style labels. */
final class NormalizationRejectionMetrics
{
    /** @return list<array{name: string, value: int, labels: array{entity: string, field: string, source: string}}> */
    public function points(): array
    {
        return DB::table('normalization_rejections')
            ->orderBy('entity')->orderBy('field')->orderBy('source')
            ->get(['entity', 'field', 'source', 'rejected_total'])
            ->map(static fn(object $row): array => [
                'name' => MetricStreams::NAMES['normalize_rejected_total'],
                'value' => (int) $row->rejected_total,
                'labels' => [
                    'entity' => (string) $row->entity,
                    'field' => (string) $row->field,
                    'source' => (string) $row->source,
                ],
            ])->all();
    }
}
