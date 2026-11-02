<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

final class EgressReportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_groups_attempts_by_ip_and_source_and_counts_blocks(): void
    {
        $this->insertAttempt('ffjav', '198.51.100.2', 403, 'challenge', 'error', 400);
        $this->insertAttempt('ffjav', '198.51.100.2', 200, null, 'ok', 200);
        $this->insertAttempt('javbus', '203.0.113.2', 429, null, 'error', 600);

        $tester = new CommandTester(app('Illuminate\\Contracts\\Console\\Kernel')->all()['crawler:egress-report']);
        $tester->execute(['--hours' => 24, '--by' => 'egress']);
        $output = $tester->getDisplay();

        self::assertSame(0, $tester->getStatusCode(), $output);
        self::assertStringContainsString('198.51.100.2', $output, $output);
        self::assertStringContainsString('ffjav', $output, $output);
        self::assertStringContainsString('50.00%', $output, $output);
        self::assertStringContainsString('203.0.113.2', $output, $output);
    }

    public function test_report_rejects_unknown_grouping(): void
    {
        $this->artisan('crawler:egress-report', ['--by' => 'method'])
            ->expectsOutputToContain('--by must be source, egress or node.')
            ->assertFailed();
    }

    public function test_report_supports_source_and_node_groupings(): void
    {
        $this->insertAttempt('ffjav', '198.51.100.3', 403, null, 'error', 300);

        $sourceOutput = $this->reportOutput('source');
        $nodeOutput = $this->reportOutput('node');

        self::assertStringContainsString('Source', $sourceOutput);
        self::assertStringContainsString('ffjav', $sourceOutput);
        self::assertStringContainsString('Node', $nodeOutput);
        self::assertStringContainsString('crawler-test-1', $nodeOutput);
        self::assertStringContainsString('198.51.100.3', $nodeOutput);
    }

    public function test_status_shows_the_current_node_and_browser_host_change_state(): void
    {
        config()->set([
            'cache.default' => 'array',
            'javmeta_browser.playwright_url' => 'http://browser-a.test',
        ]);
        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
        $hostname = is_string(gethostname()) ? gethostname() : 'local';
        $node = mb_substr(preg_replace('/[^A-Za-z0-9_.-]/', '-', trim($hostname)) ?? 'local', 0, 64);
        Cache::put('javmeta:egress:' . $node, ['ip' => '198.51.100.12', 'probe_ok' => true]);
        Cache::forever('javmeta:egress-meta:' . $node, [
            'last_known_ip' => '198.51.100.12',
            'last_change_at' => '2026-11-01T10:00:00+00:00',
            'change_count' => 4,
        ]);
        Cache::put('javmeta:browser-egress:browser-a.test', ['ip' => '203.0.113.12', 'probe_ok' => true]);
        Cache::forever('javmeta:browser-egress-meta:browser-a.test', [
            'last_known_ip' => '203.0.113.12',
            'last_change_at' => '2026-11-01T11:00:00+00:00',
            'change_count' => 2,
        ]);

        $tester = new CommandTester(app('Illuminate\\Contracts\\Console\\Kernel')->all()['crawler:egress-status']);
        $tester->execute([]);
        $output = $tester->getDisplay();

        self::assertSame(0, $tester->getStatusCode(), $output);
        self::assertStringContainsString($node, $output);
        self::assertStringContainsString('198.51.100.12', $output);
        self::assertStringContainsString('203.0.113.12', $output);
        self::assertStringContainsString('2026-11-01T10:00:00+00:00', $output);
    }

    private function insertAttempt(string $source, string $ip, int $status, ?string $cfMitigated, string $outcome, int $duration): void
    {
        DB::table('crawl_fetch_attempts')->insert([
            'created_at' => now(),
            'source_slug' => $source,
            'crawl_queue_id' => null,
            'url_hash' => hash('sha256', $source . $ip . $status),
            'method' => 'playwright',
            'started_at' => now(),
            'duration_ms' => $duration,
            'http_status' => $status,
            'cf_mitigated' => $cfMitigated,
            'outcome' => $outcome,
            'egress_ip' => $ip,
            'node' => 'crawler-test-1',
            'egress_kind' => 'node',
        ]);
    }

    private function reportOutput(string $group): string
    {
        $tester = new CommandTester(app('Illuminate\\Contracts\\Console\\Kernel')->all()['crawler:egress-report']);
        $tester->execute(['--hours' => 24, '--by' => $group]);
        $output = $tester->getDisplay();

        self::assertSame(0, $tester->getStatusCode(), $output);

        return $output;
    }
}
