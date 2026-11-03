<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Report;

use App\Services\Report\ReportRenderer;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class ReportRendererTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['javmeta_report.timezone' => 'America/Mexico_City']);
    }

    public function test_report_uses_english_layout_and_keeps_movie_and_performer_blocks_separate(): void
    {
        $text = (new ReportRenderer())->render([
            'generated_at' => '2026-11-02T18:00:00-06:00',
            'window_minutes' => 120,
            'movies' => ['total' => 181737, 'since_last' => 1240, 'last_24h' => 83856, 'avg_per_hour' => 3490, 'peak_per_hour' => 5574],
            'performers' => ['total' => 99902, 'since_last' => 210, 'last_24h' => 18263, 'avg_per_hour' => 760, 'peak_per_hour' => 1210],
            'crawl' => [
                'movies' => ['success_percent' => 89, 'blocked_percent' => 1.3, 'attempts' => 1842],
                'performers' => ['success_percent' => 91, 'blocked_percent' => 0.6, 'attempts' => 412],
            ],
            'sources' => ['blocked' => ['jable', 'javlibrary'], 'exhausted' => ['ffjav']],
            'fleet' => ['nodes_running' => 3, 'failed_jobs' => 0],
            'node_names' => [],
        ], CarbonImmutable::parse('2026-11-02 18:00:00 America/Mexico_City'));

        self::assertStringStartsWith('📊 [XCrawler] Report · Nov 2, 18:00 (Mexico) · last 2 h', $text);
        self::assertStringContainsString("Movies        181,737   (+1,240 since last report · +83,856 in 24 h)\nSpeed", $text);
        self::assertStringContainsString("Crawl         success 89% · blocked 1.3% · 1,842 attempts\n\nPerformers", $text);
        self::assertStringContainsString('⚠️ Blocked sources: jable, javlibrary', $text);
        self::assertStringContainsString('🖥 3 nodes running · 0 failed jobs', $text);
        self::assertSame(1, preg_match('/success 100%/', (new ReportRenderer())->render([
            'generated_at' => '2026-11-02T18:00:00Z',
            'window_minutes' => 120,
            'sections' => ['crawl'],
            'crawl' => ['movies' => ['success_percent' => 100, 'blocked_percent' => 10, 'attempts' => 1]],
        ])));
    }

    public function test_empty_sections_are_omitted(): void
    {
        $text = (new ReportRenderer())->render(['generated_at' => '2026-11-02T18:00:00Z', 'window_minutes' => 120]);

        self::assertStringContainsString('[XCrawler] Report', $text);
        self::assertStringNotContainsString('Movies', $text);
        self::assertStringNotContainsString('Performers', $text);
        self::assertStringNotContainsString('Services', $text);
    }

    public function test_daily_report_renders_info_and_notice_events_in_english(): void
    {
        config(['javmeta_report.daily_timezone' => 'America/Mexico_City']);
        $text = (new ReportRenderer())->render([
            'report_type' => 'daily',
            'generated_at' => '2026-11-03T12:00:00Z',
            'window_minutes' => 1440,
            'sections' => [],
            'notification_events' => [
                ['key' => 'egress.changed', 'tier' => 'notice', 'opened_at' => '2026-11-03T11:00:00Z'],
                ['key' => 'source.exhausted', 'tier' => 'info', 'opened_at' => '2026-11-03T10:00:00Z'],
            ],
        ]);

        self::assertStringContainsString("Events\n🟡 NOTICE egress.changed · Nov 3, 05:00\n🔵 INFO source.exhausted · Nov 3, 04:00", $text);
    }

    public function test_report_renders_open_warnings_with_age_value_and_threshold(): void
    {
        $text = (new ReportRenderer())->render([
            'generated_at' => '2026-11-03T12:00:00Z',
            'window_minutes' => 120,
            'sections' => [],
            'warnings' => [[
                'key' => 'crawl.success_rate_low',
                'tier' => 'warning',
                'opened_at' => '2026-11-03T10:30:00Z',
                'value' => 82,
                'threshold' => 85,
            ]],
        ]);

        self::assertStringContainsString("Warnings\n⚠️ crawl.success_rate_low · open since Nov 3, 04:30 · value 82 · threshold 85", $text);
    }

    public function test_independent_section_selection_keeps_crawl_without_catalog_totals(): void
    {
        $text = (new ReportRenderer())->render([
            'generated_at' => '2026-11-02T18:00:00Z',
            'window_minutes' => 120,
            'sections' => ['crawl'],
            'crawl' => ['movies' => ['success_percent' => 89, 'blocked_percent' => 1.3, 'attempts' => 10]],
        ]);

        self::assertStringContainsString('Movies crawl   success 89% · blocked 1.3% · 10 attempts', $text);
        self::assertStringNotContainsString('Speed', $text);
        self::assertStringNotContainsString('Workers', $text);
    }
}
