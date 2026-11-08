<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Report;

use App\Services\Report\ReportMetrics;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class ReportMetricsTest extends TestCase
{
    public function test_it_exports_delivery_totals_and_last_successful_timestamp(): void
    {
        config(['cache.default' => 'array']);
        Cache::store('array')->flush();
        $metrics = new ReportMetrics();

        $metrics->record('failed');
        $metrics->record('sent', 1793656800);
        $points = $metrics->points();

        self::assertSame([
            ['name' => 'javmeta_report_sent_total', 'value' => 1, 'labels' => ['result' => 'sent']],
            ['name' => 'javmeta_report_sent_total', 'value' => 1, 'labels' => ['result' => 'failed']],
            ['name' => 'javmeta_report_last_sent_timestamp_seconds', 'value' => 1793656800],
        ], $points);
    }
}
