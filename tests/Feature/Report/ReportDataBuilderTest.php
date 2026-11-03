<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Movie;
use App\Models\Performer;
use App\Models\ReportSnapshot;
use App\Services\Report\NodeSampleAggregator;
use App\Services\Report\NodeSampleStore;
use App\Services\Report\ReportDataBuilder;
use App\Services\Report\ReportRenderer;
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

    public function test_daily_report_uses_the_backup_manifest_for_current_and_stale_status(): void
    {
        config([
            'cache.default' => 'array',
            'javmeta_report.sections' => [],
            'backup.stale_after_seconds' => 26 * 60 * 60,
        ]);
        Cache::store('array')->flush();
        $at = CarbonImmutable::parse('2026-11-03 12:00:00 UTC');
        $manifestPath = tempnam(storage_path('app'), 'report-backup-');
        self::assertNotFalse($manifestPath);
        config(['backup.manifest_path' => $manifestPath]);

        try {
            file_put_contents($manifestPath, json_encode([
                'created_at' => $at->subHours(2)->toIso8601String(),
            ], JSON_THROW_ON_ERROR));
            $current = app(ReportDataBuilder::class)->build($at, 1440);
            self::assertSame('Successful', $current['backup']['status']);
            self::assertSame($at->subHours(2)->toIso8601String(), $current['backup']['at']);

            file_put_contents($manifestPath, json_encode([
                'created_at' => $at->subHours(27)->toIso8601String(),
            ], JSON_THROW_ON_ERROR));
            $stale = app(ReportDataBuilder::class)->build($at, 1440);
            self::assertSame('Failed or stale', $stale['backup']['status']);
        } finally {
            unlink($manifestPath);
        }
    }

    public function test_report_fallback_treats_a_resolved_backup_failure_as_successful(): void
    {
        config([
            'cache.default' => 'array',
            'javmeta_report.sections' => [],
            'backup.manifest_path' => storage_path('app/missing-backup-manifest-' . uniqid('', true) . '.json'),
        ]);
        Cache::store('array')->flush();
        $at = CarbonImmutable::parse('2026-11-03 12:00:00 UTC');
        DB::table('notification_policy_events')->insert([
            'notification_key' => 'backup.stale',
            'cause_hash' => str_repeat('a', 64),
            'first_seen_at' => $at->subHour(),
            'last_seen_at' => $at->subMinutes(40),
            'resolved_at' => $at->subMinutes(30),
            'created_at' => $at->subHour(),
            'updated_at' => $at->subMinutes(30),
        ]);

        $data = app(ReportDataBuilder::class)->build($at, 1440);

        self::assertSame('Successful', $data['backup']['status']);
        self::assertSame($at->subMinutes(30)->toDateTimeString(), $data['backup']['at']);

        DB::table('notification_policy_events')->where('notification_key', 'backup.stale')->update(['notification_key' => 'backup.success']);
        $nonFailure = app(ReportDataBuilder::class)->build($at, 1440);
        self::assertSame($at->subHour()->toDateTimeString(), $nonFailure['backup']['at']);
    }

    public function test_daily_report_labels_the_actual_retained_node_sample_span(): void
    {
        config([
            'cache.default' => 'array',
            'javmeta_report.sections' => ['workers'],
            'javmeta_report.template' => '{workers}',
            'backup.manifest_path' => storage_path('app/missing-backup-manifest-' . uniqid('', true) . '.json'),
        ]);
        Cache::store('array')->flush();
        $at = CarbonImmutable::parse('2026-11-03 12:00:00 UTC');
        $samples = app(NodeSampleStore::class);
        $samples->put('worker-1', [
            'sampled_at' => $at->subMinutes(179)->timestamp,
            'cpu_percent' => 20,
            'workers' => ['busy' => 1, 'idle' => 1, 'total' => 2],
        ]);
        $samples->put('worker-1', [
            'sampled_at' => $at->timestamp,
            'cpu_percent' => 40,
            'workers' => ['busy' => 2, 'idle' => 0, 'total' => 2],
        ]);

        $data = app(ReportDataBuilder::class)->build($at, 1440);
        $text = app(ReportRenderer::class)->render($data, $at);

        self::assertSame(179, $data['nodes']['worker-1']['sample_window_minutes']);
        self::assertStringContainsString('avg 75% busy over 2 h 59 min', $text);
        self::assertStringNotContainsString('avg busy over 24 h', $text);
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

    public function test_daily_digest_includes_recent_info_and_notice_events_and_excludes_out_of_window_events(): void
    {
        config(['cache.default' => 'array', 'javmeta_report.sections' => []]);
        Cache::store('array')->flush();
        $at = CarbonImmutable::parse('2026-11-03 12:00:00 UTC');
        DB::table('notification_templates')->whereIn('key', ['egress.changed', 'source.exhausted', 'crawl.success_rate_low'])
            ->update(['enabled' => true]);
        foreach ([
            ['egress.changed', 'recent-notice', $at->subHours(2), 12, 20],
            ['egress.changed', 'old-notice', $at->subHours(25), 12, 20],
            ['source.exhausted', 'future-info', $at->addHour(), 0, null],
            ['crawl.success_rate_low', 'open-warning', $at->subHour(), 82, 85],
        ] as [$key, $cause, $lastSeen, $value, $threshold]) {
            DB::table('notification_policy_events')->insert([
                'notification_key' => $key,
                'cause_hash' => hash('sha256', $cause),
                'first_seen_at' => $lastSeen,
                'last_seen_at' => $lastSeen,
                'value' => $value,
                'threshold' => $threshold,
                'context' => '{}',
                'created_at' => $lastSeen,
                'updated_at' => $lastSeen,
            ]);
        }

        $data = app(ReportDataBuilder::class)->build($at, 1440);

        self::assertSame(['egress.changed'], array_column($data['notification_events'], 'key'));
        self::assertSame(['crawl.success_rate_low'], array_column($data['warnings'], 'key'));
    }

    public function test_deploy_events_obey_notification_enabled_and_report_inclusion_settings(): void
    {
        config(['cache.default' => 'array', 'javmeta_report.sections' => []]);
        Cache::store('array')->flush();
        $at = CarbonImmutable::parse('2026-11-03 12:00:00 UTC');
        DB::table('notification_templates')->where('key', 'deploy.ok')->update([
            'enabled' => true,
            'include_in_daily_report' => true,
        ]);
        DB::table('notification_policy_events')->insert([
            'notification_key' => 'deploy.ok',
            'cause_hash' => hash('sha256', 'release-1'),
            'first_seen_at' => $at->subHour(),
            'last_seen_at' => $at->subHour(),
            'context' => json_encode(['release' => 'release-1', 'nodes' => 'control, worker-1'], JSON_THROW_ON_ERROR),
            'created_at' => $at->subHour(),
            'updated_at' => $at->subHour(),
        ]);

        $data = app(ReportDataBuilder::class)->build($at, 1440);
        self::assertSame([
            ['at' => $at->subHour()->toDateTimeString(), 'release' => 'release-1', 'nodes' => 'control, worker-1'],
        ], $data['deploys']);

        DB::table('notification_templates')->where('key', 'deploy.ok')->update(['include_in_daily_report' => false]);
        self::assertSame([], app(ReportDataBuilder::class)->build($at, 1440)['deploys']);

        DB::table('notification_templates')->where('key', 'deploy.ok')->update([
            'enabled' => false,
            'include_in_daily_report' => true,
        ]);
        self::assertSame([], app(ReportDataBuilder::class)->build($at, 1440)['deploys']);
    }

    public function test_escalated_warnings_are_no_longer_listed_as_open_warnings(): void
    {
        config(['cache.default' => 'array', 'javmeta_report.sections' => []]);
        Cache::store('array')->flush();
        $at = CarbonImmutable::parse('2026-11-03 12:00:00 UTC');
        DB::table('notification_templates')->where('key', 'crawl.success_rate_low')->update([
            'enabled' => true,
            'tier' => 'warning',
            'include_in_periodic_report' => true,
        ]);
        DB::table('notification_policy_events')->insert([
            'notification_key' => 'crawl.success_rate_low',
            'cause_hash' => hash('sha256', 'escalated-warning'),
            'first_seen_at' => $at->subMinutes(90),
            'last_seen_at' => $at->subMinute(),
            'critical_sent_at' => $at->subMinutes(30),
            'escalated_at' => $at->subMinutes(30),
            'value' => 68,
            'threshold' => 85,
            'context' => '{}',
            'created_at' => $at->subMinutes(90),
            'updated_at' => $at->subMinute(),
        ]);

        self::assertSame([], app(ReportDataBuilder::class)->build($at)['warnings']);
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
