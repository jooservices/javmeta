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
                ['key' => 'egress.changed', 'tier' => 'notice', 'opened_at' => '2026-11-02T11:00:00Z', 'last_seen_at' => '2026-11-03T11:00:00Z'],
                ['key' => 'source.exhausted', 'tier' => 'info', 'opened_at' => '2026-11-03T10:00:00Z'],
            ],
        ]);

        self::assertStringContainsString("Events\n🟡 NOTICE egress.changed · Nov 3, 05:00\n🔵 INFO source.exhausted · Nov 3, 04:00", $text);
    }

    public function test_daily_report_labels_its_twenty_four_hour_counts_without_calling_them_since_last_report(): void
    {
        $text = (new ReportRenderer())->render([
            'report_type' => 'daily',
            'generated_at' => '2026-11-03T12:00:00Z',
            'window_minutes' => 1440,
            'sections' => ['movies'],
            'movies' => ['total' => 100, 'since_last' => 12, 'last_24h' => 12],
        ]);

        self::assertStringContainsString('(+12 in the last 24 h)', $text);
        self::assertStringNotContainsString('since last report', $text);
        self::assertStringNotContainsString('in 24 h)', $text);
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

    public function test_workers_block_lists_each_node_and_a_fleet_total_split_by_lane(): void
    {
        $text = $this->renderSections(['workers'], [
            'node_names' => ['main', 'worker-1', 'worker-2'],
            'workers' => [
                'main' => ['workers' => ['busy' => 3, 'idle' => 1, 'total' => 4, 'lanes' => ['direct' => ['busy' => 3, 'idle' => 1, 'total' => 4]]], 'busy_share_avg' => 0.62, 'sample_window_minutes' => 90],
                'worker-1' => ['workers' => ['busy' => 1, 'idle' => 3, 'total' => 4, 'lanes' => ['browser' => ['busy' => 1, 'idle' => 3, 'total' => 4]]], 'busy_share_avg' => null],
                'worker-2' => ['workers' => null],
            ],
        ]);

        self::assertStringContainsString('Workers (now: busy / idle / total', $text);
        self::assertStringContainsString('main   busy 3 · idle 1 · total 4   (avg 62% busy over 1 h 30 min)', $text);
        self::assertStringContainsString('worker-1   busy 1 · idle 3 · total 4   (avg no data)', $text);
        self::assertStringContainsString('worker-2   no data', $text);
        self::assertStringContainsString('Fleet      busy 4 · idle 4 · total 8   (direct 3/4 busy · browser 1/4 busy)', $text);
    }

    public function test_workers_block_is_left_out_when_no_node_reported(): void
    {
        self::assertStringNotContainsString('Workers', $this->renderSections(['workers'], ['node_names' => []]));
    }

    public function test_servers_block_shows_cpu_memory_disk_per_node_and_the_browser_host(): void
    {
        $text = $this->renderSections(['servers'], [
            'node_names' => ['main', 'worker-1'],
            'servers' => [
                'main' => ['cpu_avg' => 30.0, 'cpu_peak' => 55.5, 'mem_available_bytes' => 11_000_000_000, 'disk_used_percent' => 41.25, 'sample_window_minutes' => 0],
                'worker-1' => ['cpu_avg' => null, 'mem_available_bytes' => null, 'disk_used_percent' => null, 'sample_window_minutes' => 65],
            ],
            'server_extra' => ['browser_stats' => ['cpu_avg' => 10, 'cpu_peak' => 20, 'mem_available_bytes' => 27_000_000_000, 'disk_used_percent' => 5, 'chrome_processes' => 4]],
        ]);

        self::assertStringContainsString('Servers (avg / peak across retained samples)', $text);
        self::assertStringContainsString('main   CPU 30% / 56%   RAM free 11.0 GB   Disk 41%   (sample span under 1 min)', $text);
        self::assertStringContainsString('worker-1   CPU no data   RAM no data   Disk no data   (sample span 1 h 5 min)', $text);
        self::assertStringContainsString('browser   CPU 10% / 20%   RAM free 27.0 GB   Disk 5%   Chrome 4', $text);
    }

    public function test_servers_block_reports_a_missing_node_and_an_unmeasured_browser_host(): void
    {
        $text = $this->renderSections(['servers'], [
            'node_names' => ['ghost'],
            'servers' => [],
            'server_extra' => ['browser' => 'up'],
        ]);

        self::assertStringContainsString('ghost   no data', $text);
        self::assertStringContainsString('browser   CPU no data   RAM no data   Disk no data   Chrome no data', $text);

        $partial = $this->renderSections(['servers'], ['node_names' => [], 'server_extra' => ['browser_stats' => []]]);
        self::assertStringContainsString('browser   CPU no data   RAM no data   Disk no data   Chrome no data', $partial);
    }

    public function test_services_line_joins_every_known_service_and_prefers_playwright_counts(): void
    {
        $full = $this->renderSections(['services'], ['services' => [
            'postgres' => ['used' => 74, 'maximum' => 150],
            'redis' => '12.5M',
            'elasticsearch' => 'green',
            'browser' => 'up',
            'browser_stats' => ['playwright_busy' => 2, 'playwright_total' => 8],
        ]]);
        self::assertStringContainsString('Services   Postgres 74/150 conns · Redis 12.5M · Elasticsearch green · Playwright 2/8 busy', $full);

        $stateOnly = $this->renderSections(['services'], ['services' => ['browser' => 'down']]);
        self::assertStringContainsString('Services   Playwright down', $stateOnly);

        self::assertStringNotContainsString('Services', $this->renderSections(['services'], ['services' => []]));
    }

    public function test_deploys_backup_and_empty_warnings_are_rendered(): void
    {
        $text = $this->renderSections([], [
            'deploys' => [['at' => '2026-11-03T12:00:00Z', 'release' => '06540de', 'nodes' => '3/3']],
            'backup' => ['status' => 'OK', 'at' => '2026-11-03T06:00:00Z'],
            'warnings' => [],
        ]);

        self::assertStringContainsString("Deploys\n🚀 06540de · 3/3 · Nov 3, 06:00", $text);
        self::assertStringContainsString('Backup     OK · Nov 3, 00:00', $text);
        self::assertStringContainsString('Warnings   none open', $text);

        $none = $this->renderSections([], ['deploys' => [], 'backup' => []]);
        self::assertStringContainsString('Deploys    none', $none);
        self::assertStringContainsString('Backup     No recent backup status recorded · unknown time', $none);
    }

    public function test_the_crawl_block_reports_entities_that_have_no_own_section(): void
    {
        $text = $this->renderSections(['crawl'], ['crawl' => [
            'movies' => ['success_percent' => 95.5, 'blocked_percent' => 1.25, 'attempts' => 2500],
            'performers' => ['success_percent' => 80, 'blocked_percent' => 0, 'attempts' => 10],
        ]]);

        self::assertStringContainsString('Movies crawl   success 96% · blocked 1.3% · 2,500 attempts', $text);
        self::assertStringContainsString('Performers crawl   success 80% · blocked 0% · 10 attempts', $text);
    }

    public function test_more_than_five_warnings_and_ten_events_are_cut_off(): void
    {
        $warnings = array_map(static fn(int $n): array => ['name' => "warning-{$n}", 'opened_at' => '2026-11-03T10:00:00Z', 'value' => 1, 'threshold' => 2], range(1, 8));
        $events = array_map(static fn(int $n): array => ['key' => "event-{$n}", 'tier' => 'info', 'opened_at' => '2026-11-03T10:00:00Z'], range(1, 14));

        $text = $this->renderSections([], ['warnings' => $warnings, 'notification_events' => $events]);

        self::assertStringContainsString('warning-5', $text);
        self::assertStringNotContainsString('warning-6', $text);
        self::assertStringContainsString('event-10', $text);
        self::assertStringNotContainsString('event-11', $text);
    }

    /**
     * @param list<string> $sections
     * @param array<string, mixed> $data
     */
    private function renderSections(array $sections, array $data): string
    {
        return (new ReportRenderer())->render(['generated_at' => '2026-11-03T12:00:00Z', 'window_minutes' => 120, 'sections' => $sections] + $data);
    }
}
