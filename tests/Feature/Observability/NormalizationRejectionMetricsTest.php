<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use App\Observability\MetricStreams;
use App\Observability\NormalizationRejectionMetrics;
use App\Services\Normalize\PerformerAttributeNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NormalizationRejectionMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejections_are_counted_by_entity_field_and_source_and_reported(): void
    {
        $normalizer = app(PerformerAttributeNormalizer::class);
        $normalizer->bloodType('- Type', 'xcity');
        $normalizer->bloodType('Trans', 'avjoho');
        $normalizer->cup('FANZA', 'xcity');

        $points = app(NormalizationRejectionMetrics::class)->points();
        self::assertSame([
            [
                'name' => MetricStreams::NAMES['normalize_rejected_total'],
                'value' => 1,
                'labels' => ['entity' => 'performer', 'field' => 'blood_type', 'source' => 'avjoho'],
            ],
            [
                'name' => MetricStreams::NAMES['normalize_rejected_total'],
                'value' => 1,
                'labels' => ['entity' => 'performer', 'field' => 'blood_type', 'source' => 'xcity'],
            ],
            [
                'name' => MetricStreams::NAMES['normalize_rejected_total'],
                'value' => 1,
                'labels' => ['entity' => 'performer', 'field' => 'cup', 'source' => 'xcity'],
            ],
        ], $points);

        $this->artisan('performers:normalization-report')
            ->assertSuccessful()
            ->expectsOutputToContain('javmeta_normalize_rejected_total{entity,field,source}')
            ->expectsOutputToContain('field="blood_type",source="xcity"} 1');
    }
}
