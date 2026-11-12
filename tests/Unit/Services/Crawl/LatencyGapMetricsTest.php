<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawl;

use App\Observability\OpenObserveClient;
use App\Observability\TelemetrySanitizer;
use App\Services\Crawl\LatencyGapMetrics;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class LatencyGapMetricsTest extends TestCase
{
    public function test_metric_uses_only_fixed_reason_and_direction_labels(): void
    {
        config([
            'openobserve.enabled' => true,
            'openobserve.url' => 'https://openobserve.test',
            'openobserve.org' => 'test',
            'openobserve.service_name' => 'javmeta-test',
            'app.env' => 'testing',
        ]);
        Http::fake(['*' => Http::response([], 200)]);

        $metrics = new LatencyGapMetrics(new OpenObserveClient(new TelemetrySanitizer()));
        $metrics->recordChange('latency_high', 'increase');

        Http::assertSent(static function (Request $request): bool {
            $points = data_get($request->data(), 'resourceMetrics.0.scopeMetrics.0.metrics', []);
            if (count($points) !== 1 || ($points[0]['name'] ?? null) !== 'javmeta_source_gap_changes_total') {
                return false;
            }

            $attributes = data_get($points[0], 'gauge.dataPoints.0.attributes', []);
            $labels = [];
            foreach ($attributes as $attribute) {
                if (! is_array($attribute) || ! is_string($attribute['key'] ?? null)) {
                    continue;
                }
                $labels[$attribute['key']] = data_get($attribute, 'value.stringValue');
            }

            return $labels === [
                'reason' => 'latency_high',
                'direction' => 'increase',
                'environment' => 'testing',
                'role' => 'api',
            ];
        });
    }

    public function test_invalid_reason_direction_pairs_are_ignored(): void
    {
        config(['openobserve.enabled' => true]);
        Http::fake();

        $metrics = new LatencyGapMetrics(new OpenObserveClient(new TelemetrySanitizer()));
        $metrics->recordChange('latency_high', 'decrease');

        Http::assertNothingSent();
    }
}
