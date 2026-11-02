<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Movie;
use App\Models\Performer;
use App\Models\ReportSnapshot;
use App\Services\Report\NodeSampleAggregator;
use App\Services\Report\NodeSampleStore;
use App\Services\Report\ReportDataBuilder;
use App\Services\Report\ReportServiceStats;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ReportDataBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_movie_and_performer_deltas_and_hourly_speeds_use_their_own_seen_timestamps(): void
    {
        config(['cache.default' => 'array', 'javmeta_report.interval_minutes' => 120, 'javmeta_report.sections' => ['movies', 'performers']]);
        Cache::store('array')->flush();
        $at = CarbonImmutable::parse('2026-11-03 12:00:00 UTC');
        Movie::factory()->create(['crawled_at' => $at->subHours(2)->addMinutes(10)]);
        Movie::factory()->create(['crawled_at' => $at->subHour()->addMinutes(10)]);
        Movie::factory()->create(['crawled_at' => $at->subDays(2)]);
        Performer::factory()->create(['first_seen_at' => $at->subHours(2)->addMinutes(20)]);
        Performer::factory()->create(['first_seen_at' => $at->subHour()->addMinutes(20)]);
        ReportSnapshot::query()->create([
            'result' => 'sent',
            'channel' => 'telegram',
            'rendered_text' => 'Previous report',
            'raw_numbers' => ['movies' => ['total' => 1], 'performers' => ['total' => 0]],
        ]);

        $data = app(ReportDataBuilder::class)->build($at);

        self::assertSame(['total' => 3, 'since_last' => 2, 'last_24h' => 2, 'avg_per_hour' => 1.0, 'peak_per_hour' => 1.0], $data['movies']);
        self::assertSame(['total' => 2, 'since_last' => 2, 'last_24h' => 2, 'avg_per_hour' => 1.0, 'peak_per_hour' => 1.0], $data['performers']);
    }

    public function test_daily_report_uses_the_same_indexed_24_hour_window_for_its_delta_and_count(): void
    {
        config(['cache.default' => 'array', 'javmeta_report.sections' => ['movies', 'performers']]);
        Cache::store('array')->flush();
        $at = CarbonImmutable::parse('2026-11-03 12:00:00 UTC');
        Movie::factory()->create(['crawled_at' => $at->subDay()->subMinute()]);
        Movie::factory()->create(['crawled_at' => $at->subHours(12)]);
        Movie::factory()->create(['crawled_at' => $at->subMinutes(10)]);
        Performer::factory()->create(['first_seen_at' => $at->subDay()->subMinute()]);
        Performer::factory()->create(['first_seen_at' => $at->subHours(12)]);

        $data = app(ReportDataBuilder::class)->build($at, 1440);

        self::assertSame('daily', $data['report_type']);
        self::assertSame(2, $data['movies']['since_last']);
        self::assertSame(2, $data['movies']['last_24h']);
        self::assertSame(1, $data['performers']['since_last']);
        self::assertSame(1, $data['performers']['last_24h']);
    }

    public function test_daily_and_periodic_snapshots_can_share_the_same_scheduled_time(): void
    {
        $scheduledFor = CarbonImmutable::parse('2026-11-03 08:00:00 America/Mexico_City');
        foreach (['periodic', 'daily'] as $reportType) {
            ReportSnapshot::query()->create([
                'scheduled_for' => $scheduledFor,
                'report_type' => $reportType,
                'result' => 'sent',
                'channel' => 'telegram',
                'rendered_text' => $reportType,
                'raw_numbers' => [],
            ]);
        }

        self::assertSame(['daily', 'periodic'], ReportSnapshot::query()->orderBy('report_type')->pluck('report_type')->all());
    }

    public function test_crawl_success_excludes_skipped_attempts_and_uses_kind_to_separate_entities(): void
    {
        config(['cache.default' => 'array', 'javmeta_report.interval_minutes' => 120, 'javmeta_report.sections' => ['crawl', 'sources']]);
        Cache::store('array')->flush();
        $at = CarbonImmutable::parse('2026-11-03 12:00:00 UTC');
        foreach ([
            ['listing', 'ok', 200, null, 'movie-source'],
            ['detail', 'error', 403, null, 'blocked-source'],
            ['gallery', 'skipped', 200, null, 'movie-source'],
            ['performer_detail', 'ok', 200, null, 'performer-source'],
        ] as $index => [$kind, $outcome, $status, $mitigated, $source]) {
            DB::table('crawl_fetch_attempts')->insert([
                'created_at' => $at,
                'source_slug' => $source,
                'crawl_queue_id' => null,
                'kind' => $kind,
                'url_hash' => hash('sha256', (string) $index),
                'method' => 'http',
                'started_at' => $at->subMinutes(30),
                'duration_ms' => 100,
                'http_status' => $status,
                'cf_mitigated' => $mitigated,
                'outcome' => $outcome,
                'error_code' => null,
                'error' => null,
            ]);
        }

        $data = app(ReportDataBuilder::class)->build($at);

        self::assertSame(['attempts' => 3, 'success_percent' => 50.0, 'blocked_percent' => 33.3], $data['crawl']['movies']);
        self::assertSame(['attempts' => 1, 'success_percent' => 100.0, 'blocked_percent' => 0.0], $data['crawl']['performers']);
        self::assertSame(['blocked-source'], $data['sources']['blocked']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(ReportServiceStats::class, static fn() => new ReportServiceStats(app(\App\Services\Search\ElasticsearchHttpClient::class)));
        $this->app->instance(NodeSampleStore::class, new NodeSampleStore());
        $this->app->instance(NodeSampleAggregator::class, new NodeSampleAggregator());
    }
}
