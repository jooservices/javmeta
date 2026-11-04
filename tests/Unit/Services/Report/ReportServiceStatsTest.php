<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Report;

use App\Services\Dependencies\DependencyMonitor;
use App\Services\Dependencies\DependencyProbe;
use App\Services\Report\ReportServiceStats;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Tests\TestCase;

#[CoversClass(ReportServiceStats::class)]
final class ReportServiceStatsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('elasticsearch.enabled', true);
        config()->set('elasticsearch.host', 'http://es.test:9200');
        config()->set('javmeta_report.browser_stats_url', '');
        $this->useRedisInfo(['used_memory_human' => '12.5M']);
        $this->useBrowserProbe(null);
    }

    public function test_every_reachable_service_is_reported(): void
    {
        $this->usePostgres(42, 150);
        $this->fakeHttp(['es.test:9200/_cluster/health' => Http::response(['status' => 'GREEN'])]);
        $this->useBrowserProbe(true);

        $stats = $this->stats()->collect();

        self::assertSame(['used' => 42, 'maximum' => 150], $stats['postgres']);
        self::assertSame('12.5M', $stats['redis']);
        self::assertSame('green', $stats['elasticsearch']);
        self::assertSame('up', $stats['browser']);
        self::assertArrayNotHasKey('browser_stats', $stats);
    }

    public function test_postgres_counts_are_clamped_and_other_drivers_are_skipped(): void
    {
        $this->usePostgres(-5, 0);
        $this->fakeHttp(['*' => Http::response(['status' => 'yellow'])]);

        $stats = $this->stats()->collect();
        self::assertSame(['used' => 0, 'maximum' => 1], $stats['postgres']);

        $this->useDriver('sqlite');
        self::assertArrayNotHasKey('postgres', $this->stats()->collect());
    }

    public function test_a_failing_postgres_query_is_left_out_instead_of_breaking_the_report(): void
    {
        $this->useDriver('pgsql', static fn() => throw new RuntimeException('connection refused'));
        $this->fakeHttp(['*' => Http::response(['status' => 'green'])]);

        self::assertArrayNotHasKey('postgres', $this->stats()->collect());
    }

    public function test_redis_memory_is_read_from_a_nested_info_shape_and_failures_are_skipped(): void
    {
        $this->useDriver('sqlite');
        $this->fakeHttp(['*' => Http::response(['status' => 'green'])]);

        $this->useRedisInfo(['memory' => ['used_memory_human' => '3.1M']]);
        self::assertSame('3.1M', $this->stats()->collect()['redis']);

        $this->useRedisInfo(['unrelated' => 'x']);
        self::assertArrayNotHasKey('redis', $this->stats()->collect());

        $broken = \Mockery::mock();
        $broken->shouldReceive('connection')->andThrow(new RuntimeException('redis down'));
        Redis::swap($broken);
        self::assertArrayNotHasKey('redis', $this->stats()->collect());
    }

    public function test_elasticsearch_states_cover_disabled_unhealthy_and_unreachable(): void
    {
        $this->useDriver('sqlite');

        config()->set('elasticsearch.enabled', false);
        self::assertArrayNotHasKey('elasticsearch', $this->stats()->collect());

        config()->set('elasticsearch.enabled', true);
        $this->fakeHttp(['es.test:9200/_cluster/health' => Http::response('', 503)]);
        self::assertSame('red', $this->stats()->collect()['elasticsearch']);

        $this->fakeHttp(['es.test:9200/_cluster/health' => Http::response(['status' => 123])]);
        self::assertArrayNotHasKey('elasticsearch', $this->stats()->collect());

        $this->fakeHttp(['es.test:9200/_cluster/health' => static fn() => throw new \Illuminate\Http\Client\ConnectionException('refused')]);
        self::assertSame('down', $this->stats()->collect()['elasticsearch']);
    }

    public function test_browser_state_is_up_down_or_omitted_when_disabled(): void
    {
        $this->useDriver('sqlite');
        $this->fakeHttp(['*' => Http::response(['status' => 'green'])]);

        $this->useBrowserProbe(false);
        self::assertSame('down', $this->stats()->collect()['browser']);

        $this->useBrowserProbe(null);
        self::assertArrayNotHasKey('browser', $this->stats()->collect());
    }

    public function test_browser_host_stats_keep_only_numeric_fields_and_cast_them(): void
    {
        $this->useDriver('sqlite');
        config()->set('javmeta_report.browser_stats_url', 'http://browser.test/stats');
        $this->fakeHttp([
            'es.test:9200/*' => Http::response(['status' => 'green']),
            'browser.test/stats' => Http::response([
                'cpu_avg' => '12.5',
                'cpu_peak' => 40,
                'mem_available_bytes' => '2048',
                'disk_used_percent' => 71.2,
                'chrome_processes' => 5,
                'playwright_busy' => 2,
                'playwright_total' => 8,
                'ignored' => 'text',
            ]),
        ]);

        $stats = $this->stats()->collect()['browser_stats'];

        self::assertSame([
            'cpu_avg' => 12.5,
            'cpu_peak' => 40.0,
            'mem_available_bytes' => 2048,
            'disk_used_percent' => 71.2,
            'chrome_processes' => 5,
            'playwright_busy' => 2,
            'playwright_total' => 8,
        ], $stats);
    }

    public function test_browser_host_stats_are_omitted_when_the_host_fails_or_answers_with_junk(): void
    {
        $this->useDriver('sqlite');
        config()->set('javmeta_report.browser_stats_url', 'http://browser.test/stats');

        $this->fakeHttp(['es.test:9200/*' => Http::response(['status' => 'green']), 'browser.test/stats' => Http::response('', 500)]);
        self::assertArrayNotHasKey('browser_stats', $this->stats()->collect());

        $this->fakeHttp(['es.test:9200/*' => Http::response(['status' => 'green']), 'browser.test/stats' => Http::response('not json', 200)]);
        self::assertArrayNotHasKey('browser_stats', $this->stats()->collect());

        $this->fakeHttp(['es.test:9200/*' => Http::response(['status' => 'green']), 'browser.test/stats' => static fn() => throw new \Illuminate\Http\Client\ConnectionException('refused')]);
        self::assertArrayNotHasKey('browser_stats', $this->stats()->collect());

        $this->fakeHttp(['es.test:9200/*' => Http::response(['status' => 'green']), 'browser.test/stats' => Http::response(['ignored' => 'text'])]);
        self::assertArrayNotHasKey('browser_stats', $this->stats()->collect());
    }

    private function stats(): ReportServiceStats
    {
        return new ReportServiceStats(app(\App\Services\Search\ElasticsearchHttpClient::class));
    }

    /** @param \Closure|null $selectOne what DB::selectOne does; default returns nothing */
    private function useDriver(string $driver, ?\Closure $selectOne = null): void
    {
        $connection = \Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn($driver);
        $manager = \Mockery::mock();
        $manager->shouldReceive('connection')->andReturn($connection);
        $manager->shouldReceive('selectOne')->andReturnUsing($selectOne ?? static fn() => null);
        DB::swap($manager);
    }

    private function usePostgres(int $used, int $maximum): void
    {
        $this->useDriver('pgsql', static fn() => (object) ['used' => $used, 'maximum' => $maximum]);
    }

    /** @param array<string, mixed> $stubs */
    private function fakeHttp(array $stubs): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake($stubs);
    }

    /** @param array<string, mixed> $info */
    private function useRedisInfo(array $info): void
    {
        $connection = \Mockery::mock();
        $connection->shouldReceive('info')->with('memory')->andReturn($info);
        $manager = \Mockery::mock();
        $manager->shouldReceive('connection')->andReturn($connection);
        Redis::swap($manager);
    }

    /** null = the browser dependency has no probe (disabled); true/false = probe result. */
    private function useBrowserProbe(?bool $available): void
    {
        $probes = [];
        if ($available !== null) {
            $probes['browser'] = new class ($available) implements DependencyProbe {
                public function __construct(private readonly bool $available) {}

                public function probe(): bool
                {
                    return $this->available;
                }
            };
        }
        $this->app->instance(DependencyMonitor::class, new DependencyMonitor($probes));
    }
}
