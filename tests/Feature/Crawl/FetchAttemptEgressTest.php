<?php

declare(strict_types=1);

namespace Tests\Feature\Crawl;

use App\Models\CrawlQueue;
use App\Services\Crawl\FetchAttemptRecorder;
use App\Services\Crawler\CrawlerxFetchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class FetchAttemptEgressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.default', 'array');
        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
    }

    public function test_direct_attempt_stores_the_node_ip(): void
    {
        $node = $this->nodeId();
        Cache::put('javmeta:egress:' . $node, ['ip' => '198.51.100.7']);

        $this->recordAttempt('direct');

        $attempt = DB::table('crawl_fetch_attempts')->first();
        self::assertSame('198.51.100.7', $attempt->egress_ip);
        self::assertSame($node, $attempt->node);
        self::assertSame('node', $attempt->egress_kind);
    }

    public function test_browser_attempt_stores_the_browser_host_ip(): void
    {
        config()->set('javmeta_browser.playwright_url', 'http://browser-a.test');
        Cache::put('javmeta:browser-egress:browser-a.test', ['ip' => '203.0.113.8']);

        $this->recordAttempt('browser');

        $attempt = DB::table('crawl_fetch_attempts')->first();
        self::assertSame('203.0.113.8', $attempt->egress_ip);
        self::assertSame($this->nodeId(), $attempt->node);
        self::assertSame('browser_host', $attempt->egress_kind);
    }

    public function test_browser_attempts_use_the_configured_host_for_each_method(): void
    {
        config()->set([
            'javmeta_browser.playwright_url' => 'http://browser-a.test',
            'javmeta_browser.flaresolverr_url' => 'http://browser-b.test',
        ]);
        Cache::put('javmeta:browser-egress:browser-a.test', ['ip' => '203.0.113.21']);
        Cache::put('javmeta:browser-egress:browser-b.test', ['ip' => '203.0.113.22']);
        $queue = new CrawlQueue(['source_slug' => 'ffjav', 'url' => 'https://ffjav.example.test/page']);
        $queue->id = 43;
        $queue->setAttribute('claimed_lane', 'browser');
        $result = CrawlerxFetchResult::failure('blocked', 'blocked', attempts: [
            [
                'method' => 'playwright',
                'started_at' => now()->toIso8601String(),
                'duration_ms' => 100,
                'outcome' => 'error',
            ],
            [
                'method' => 'flaresolverr',
                'started_at' => now()->toIso8601String(),
                'duration_ms' => 200,
                'outcome' => 'error',
            ],
        ]);

        app(FetchAttemptRecorder::class)->record($queue, $result);

        $rows = DB::table('crawl_fetch_attempts')->orderBy('id')->get();
        self::assertSame(['203.0.113.21', '203.0.113.22'], $rows->pluck('egress_ip')->all());
        self::assertSame(['browser_host', 'browser_host'], $rows->pluck('egress_kind')->all());
    }

    public function test_attempt_without_a_probe_value_keeps_a_null_ip(): void
    {
        $this->recordAttempt('direct');

        $attempt = DB::table('crawl_fetch_attempts')->first();
        self::assertNull($attempt->egress_ip);
        self::assertSame($this->nodeId(), $attempt->node);
        self::assertSame('node', $attempt->egress_kind);
    }

    public function test_database_switch_can_leave_ip_blank_without_changing_the_node_label(): void
    {
        $node = $this->nodeId();
        Cache::put('javmeta:egress:' . $node, ['ip' => '198.51.100.13']);
        config()->set('javmeta_observability.record_egress_ip', false);

        $this->recordAttempt('direct');

        $attempt = DB::table('crawl_fetch_attempts')->first();
        self::assertNull($attempt->egress_ip);
        self::assertSame($node, $attempt->node);
    }

    private function recordAttempt(string $lane): void
    {
        $queue = new CrawlQueue(['source_slug' => 'ffjav', 'url' => 'https://ffjav.example.test/page']);
        $queue->id = 42;
        $queue->setAttribute('claimed_lane', $lane);
        $result = CrawlerxFetchResult::failure('blocked', 'blocked', attempts: [[
            'method' => 'playwright',
            'started_at' => now()->toIso8601String(),
            'duration_ms' => 250,
            'outcome' => 'error',
            'http_status' => 403,
        ]]);

        app(FetchAttemptRecorder::class)->record($queue, $result);
    }

    private function nodeId(): string
    {
        $hostname = gethostname();

        return mb_substr(preg_replace('/[^A-Za-z0-9_.-]/', '-', is_string($hostname) ? trim($hostname) : 'local') ?? 'local', 0, 64);
    }
}
