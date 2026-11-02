<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use App\Console\Commands\Observability\PublishMetricsCommand;
use App\Models\CrawlEvent;
use App\Models\CrawlQueue;
use App\Models\Source;
use App\Services\Archive\MongoArchiveWriteHealth;
use App\Services\Crawl\FetchMethodPreference;
use App\Services\Health\CrawlDispatchHeartbeat;
use App\Services\Search\ElasticsearchRequestHealth;
use App\Services\Settings\SettingsService;
use App\Services\Workers\HorizonLayoutSnapshot;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Carbon\CarbonImmutable;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

final class PublishMetricsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        SettingsService::forgetDefaults();

        parent::tearDown();
    }

    public function test_metrics_command_is_registered_with_legacy_alias(): void
    {
        $commands = app(Kernel::class)->all();
        $command = $commands['obs:publish-metrics'];

        $this->assertInstanceOf(PublishMetricsCommand::class, $command);
        $this->assertArrayHasKey('javmeta:obs-publish-metrics', $commands);
        $this->assertSame($command, $commands['javmeta:obs-publish-metrics']);
    }

    public function test_command_publishes_gauge_points_when_enabled(): void
    {
        Source::factory()->create([
            'slug' => 'javdb',
            'gap_seconds_current' => 30,
            'last_success_at' => now()->subMinutes(5),
        ]);
        config()->set('javmeta_queues.source_gap_overrides', [
            'javdb' => ['min' => 10, 'default' => 15, 'max' => 20],
        ]);

        config()->set([
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.email' => 'root@javmeta.local',
            'openobserve.password' => 'secret',
            'openobserve.timeout' => 1,
        ]);
        Http::fake([
            'openobserve.test/*' => Http::response(['code' => 200], 200),
        ]);

        $this->artisan('obs:publish-metrics')->assertSuccessful();

        Http::assertSent(function ($request): bool {
            $metrics = $request->data()['resourceMetrics'][0]['scopeMetrics'][0]['metrics'] ?? [];
            $gaps = [];
            foreach ($metrics as $metric) {
                if (! in_array($metric['name'] ?? null, ['javmeta_source_gap_seconds', 'javmeta_source_gap_effective_seconds'], true)) {
                    continue;
                }
                foreach ($metric['gauge']['dataPoints'] ?? [] as $point) {
                    $labels = [];
                    foreach ($point['attributes'] ?? [] as $attribute) {
                        $labels[$attribute['key']] = $attribute['value']['stringValue'] ?? null;
                    }
                    if (($labels['source_slug'] ?? null) === 'javdb') {
                        $gaps[$metric['name']] = [
                            'gap' => (float) ($point['asDouble'] ?? 0),
                            'source' => $labels['gap_source'] ?? null,
                        ];
                    }
                }
            }

            return str_contains($request->url(), '/v1/metrics')
                && str_contains($request->body(), 'javmeta_source_gap_seconds')
                && ($gaps['javmeta_source_gap_seconds'] ?? null) === ['gap' => 20.0, 'source' => 'override']
                && ($gaps['javmeta_source_gap_effective_seconds'] ?? null) === ['gap' => 15.0, 'source' => 'override']
                && ! str_contains($request->body(), 'javmeta_source_circuit_state')
                && ! str_contains($request->body(), 'javmeta_source_consecutive_failures');
        });
    }

    public function test_stats_sampling_failure_does_not_block_openobserve_publication(): void
    {
        config()->set([
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.email' => 'root@javmeta.local',
            'openobserve.password' => 'secret',
            'openobserve.timeout' => 1,
        ]);
        Http::fake(['openobserve.test/*' => Http::response(['code' => 200], 200)]);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER reject_global_stats_sample
            BEFORE INSERT ON crawl_stats_hourly
            WHEN NEW.source_slug = '__global__'
            BEGIN
                SELECT RAISE(ABORT, 'global stats rejected');
            END;
            SQL);

        $this->artisan('obs:publish-metrics')->assertSuccessful();

        Http::assertSent(static fn($request): bool => str_contains($request->url(), '/v1/metrics'));
    }

    public function test_retry_metrics_publish_pending_success_and_permanent_failure_with_egress_labels(): void
    {
        $row = CrawlQueue::factory()->create([
            'status' => CrawlQueue::STATUS_RETRY_PENDING,
            'retry_round' => 2,
            'retry_egress_label' => 'clean-egress',
        ]);
        DB::table('crawl_retry_metrics')->insert([
            ['source_slug' => $row->source_slug, 'round' => 2, 'outcome' => 'success', 'egress_label' => 'clean-egress', 'value' => 4],
            ['source_slug' => $row->source_slug, 'round' => 1, 'outcome' => 'failed_permanent', 'egress_label' => 'clean-egress', 'value' => 2],
        ]);
        $method = new ReflectionMethod(PublishMetricsCommand::class, 'queueMetrics');
        /** @var list<array{name: string, value: float|int, labels?: array<string, string>}> $points */
        $points = $method->invoke(app(PublishMetricsCommand::class));
        $retryPoints = collect($points)->whereIn('name', [
            'javmeta_retry_pending', 'javmeta_retry_success_total', 'javmeta_retry_failed_permanent_total',
        ]);

        self::assertCount(3, $retryPoints);
        self::assertSame(1, $retryPoints->firstWhere('name', 'javmeta_retry_pending')['value']);
        self::assertSame(4, $retryPoints->firstWhere('name', 'javmeta_retry_success_total')['value']);
        self::assertSame(2, $retryPoints->firstWhere('name', 'javmeta_retry_failed_permanent_total')['value']);
        foreach ($retryPoints as $point) {
            self::assertSame('clean-egress', $point['labels']['egress']);
        }
    }

    public function test_command_publishes_feed_pool_diagnostics_metrics_from_the_previous_minute(): void
    {
        config()->set([
            'cache.default' => 'array',
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.email' => 'root@javmeta.local',
            'openobserve.password' => 'secret',
            'openobserve.timeout' => 1,
        ]);
        Http::fake(['openobserve.test/*' => Http::response(['code' => 200], 200)]);
        $this->freezeTime();
        $minute = CarbonImmutable::now()->startOfMinute()->subMinute();
        $key = 'javmeta:feed-pool:diagnostics:' . $minute->timestamp;
        $redis = Mockery::mock();
        $redis->shouldReceive('smembers')->once()->with(HorizonLayoutSnapshot::NODE_REGISTRY_KEY)->andReturn([]);
        $redis->shouldReceive('hgetall')->once()->with($key)->andReturn([
            's|ffjav|budget' => 60,
            's|ffjav|started' => 33,
            's|ffjav|waiting' => 2,
            's|ffjav|dispatched' => 5,
            'q|listing|direct|depth' => 1,
            'run_ms_total' => 80,
            'run_count' => 2,
            'claim_rows_examined' => 4,
        ]);
        Redis::shouldReceive('connection')->andReturn($redis);

        $this->artisan('obs:publish-metrics')->assertSuccessful();

        Http::assertSent(static function ($request): bool {
            $metrics = $request->data()['resourceMetrics'][0]['scopeMetrics'][0]['metrics'] ?? [];
            $metricValue = static function (string $name, array $expectedLabels) use ($metrics): ?float {
                foreach ($metrics as $metric) {
                    if (($metric['name'] ?? null) !== $name) {
                        continue;
                    }
                    foreach ($metric['gauge']['dataPoints'] ?? [] as $point) {
                        $labels = [];
                        foreach ($point['attributes'] ?? [] as $attribute) {
                            $labels[$attribute['key']] = $attribute['value']['stringValue'] ?? null;
                        }
                        foreach ($expectedLabels as $key => $value) {
                            if (($labels[$key] ?? null) !== $value) {
                                continue 2;
                            }
                        }

                        return is_numeric($point['asDouble'] ?? null) ? (float) $point['asDouble'] : null;
                    }
                }

                return null;
            };

            return $metricValue('javmeta_feed_source_budget', ['source_slug' => 'ffjav']) === 60.0
                && $metricValue('javmeta_feed_source_started', ['source_slug' => 'ffjav']) === 33.0
                && $metricValue('javmeta_feed_source_waiting', ['source_slug' => 'ffjav']) === 2.0
                && $metricValue('javmeta_feed_source_dispatched_1m', ['source_slug' => 'ffjav']) === 5.0
                && $metricValue('javmeta_feed_stop_reason_1m', ['queue' => 'listing', 'lane' => 'direct', 'reason' => 'depth']) === 1.0
                && $metricValue('javmeta_feed_run_ms', []) === 40.0
                && $metricValue('javmeta_feed_claim_rows_examined_1m', []) === 4.0;
        });
    }

    public function test_command_publishes_fetch_attempt_preference_and_playwright_slot_metrics(): void
    {
        Source::factory()->create([
            'slug' => 'javbus',
            'gap_seconds_default' => 30,
            'gap_seconds_current' => 30,
            'enabled' => true,
        ]);
        config()->set([
            'cache.default' => 'array',
            'javmeta_queues.browser_inflight' => 2,
            'javmeta_queues.fetch_method_window_size' => 10,
            'javmeta_queues.fetch_method_failure_threshold' => 8,
            'javmeta_queues.fetch_method_window_ttl_seconds' => 3600,
            'javmeta_queues.fetch_method_preference_ttl_seconds' => 3600,
            'javmeta_queues.fetch_method_probe_interval' => 10,
        ]);
        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
        $preference = app(FetchMethodPreference::class);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $preference->recordAttempts('javbus', 'www.javbus.com', [
                ['method' => 'playwright', 'outcome' => 'error'],
                ['method' => 'flaresolverr', 'outcome' => 'ok'],
            ]);
        }

        DB::table('crawl_fetch_attempts')->insert([
            [
                'created_at' => now()->subSeconds(10),
                'source_slug' => 'javbus',
                'crawl_queue_id' => null,
                'url_hash' => str_repeat('a', 64),
                'method' => 'playwright',
                'started_at' => now()->subSeconds(30),
                'duration_ms' => 20_000,
                'http_status' => null,
                'cf_mitigated' => null,
                'outcome' => 'error',
                'error_code' => 'timeout',
                'error' => null,
            ],
            [
                'created_at' => now()->subSeconds(5),
                'source_slug' => 'javbus',
                'crawl_queue_id' => null,
                'url_hash' => str_repeat('b', 64),
                'method' => 'playwright',
                'started_at' => now()->subSeconds(10),
                'duration_ms' => 10_000,
                'http_status' => null,
                'cf_mitigated' => null,
                'outcome' => 'error',
                'error_code' => 'timeout',
                'error' => null,
            ],
        ]);

        config()->set([
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.email' => 'root@javmeta.local',
            'openobserve.password' => 'secret',
            'openobserve.timeout' => 1,
        ]);
        Http::fake(['openobserve.test/*' => Http::response(['code' => 200], 200)]);

        $this->artisan('obs:publish-metrics')->assertSuccessful();

        Http::assertSent(static function ($request): bool {
            if (! str_contains($request->url(), '/v1/metrics')) {
                return false;
            }

            $metrics = $request->data()['resourceMetrics'][0]['scopeMetrics'][0]['metrics'] ?? [];
            $metricValue = static function (string $name, array $expectedLabels) use ($metrics): ?float {
                foreach ($metrics as $metric) {
                    if (($metric['name'] ?? null) !== $name) {
                        continue;
                    }
                    foreach ($metric['gauge']['dataPoints'] ?? [] as $point) {
                        $labels = [];
                        foreach ($point['attributes'] ?? [] as $attribute) {
                            $labels[$attribute['key']] = $attribute['value']['stringValue'] ?? null;
                        }
                        foreach ($expectedLabels as $key => $value) {
                            if (($labels[$key] ?? null) !== $value) {
                                continue 2;
                            }
                        }

                        return is_numeric($point['asDouble'] ?? null) ? (float) $point['asDouble'] : null;
                    }
                }

                return null;
            };

            return $metricValue('javmeta_fetch_method_attempts', ['source' => 'javbus', 'method' => 'playwright', 'outcome' => 'error']) === 2.0
                && $metricValue('javmeta_fetch_method_preference', ['source' => 'javbus', 'method' => 'flaresolverr']) === 1.0
                && $metricValue('javmeta_fetch_method_preference', ['source' => 'javbus', 'method' => 'playwright']) === 0.0
                && $metricValue('javmeta_playwright_slot_busy_ratio', []) === 0.25;
        });
    }

    public function test_command_publishes_applied_horizon_worker_counts_by_queue_and_node(): void
    {
        config()->set([
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.email' => 'root@javmeta.local',
            'openobserve.password' => 'secret',
            'openobserve.timeout' => 1,
        ]);
        Http::fake(['openobserve.test/*' => Http::response(['code' => 200], 200)]);
        $snapshot = [
            'node' => 'worker-node-one',
            'supervisors' => [
                'crawler-listing' => ['queue' => ['listing'], 'maxProcesses' => 3],
            ],
        ];
        $redis = Mockery::mock();
        $redis->shouldReceive('smembers')->once()->with(HorizonLayoutSnapshot::NODE_REGISTRY_KEY)->andReturn(['unavailable-node', 'worker-node-one']);
        $redis->shouldReceive('hgetall')->once()->andReturn([]);
        $redis->shouldReceive('get')->once()->with(HorizonLayoutSnapshot::key('unavailable-node'))->andThrow(new \RuntimeException('Node snapshot unavailable.'));
        $redis->shouldReceive('get')->once()->with(HorizonLayoutSnapshot::key('worker-node-one'))->andReturn(json_encode(['snapshot' => $snapshot], JSON_THROW_ON_ERROR));
        Redis::shouldReceive('connection')->twice()->with('default')->andReturn($redis);

        $this->artisan('obs:publish-metrics')->assertSuccessful();

        Http::assertSent(static function ($request): bool {
            $metrics = $request->data()['resourceMetrics'][0]['scopeMetrics'][0]['metrics'] ?? [];
            foreach ($metrics as $metric) {
                if (($metric['name'] ?? null) !== 'javmeta_horizon_workers') {
                    continue;
                }
                $point = $metric['gauge']['dataPoints'][0] ?? [];
                $labels = [];
                foreach ($point['attributes'] ?? [] as $attribute) {
                    $labels[$attribute['key']] = $attribute['value']['stringValue'] ?? null;
                }

                return ($point['asDouble'] ?? null) === 3.0
                    && ($labels['queue'] ?? null) === 'listing'
                    && ($labels['node'] ?? null) === 'worker-node-one';
            }

            return false;
        });
    }

    public function test_command_publishes_dispatch_age_and_database_configured_stale_thresholds(): void
    {
        config()->set('cache.default', 'array');
        SettingsService::forgetDefaults();
        $this->app->instance(ConfigStore::class, new FakeConfigStore());
        $this->app->forgetInstance(SettingsService::class);
        $settings = app(SettingsService::class);
        $settings->set('alerts.crawl_dispatch_warning_seconds', 90);
        $settings->set('alerts.crawl_dispatch_critical_seconds', 240);
        Cache::store('array')->flush();
        app(CrawlDispatchHeartbeat::class)->record();
        $this->travel(200)->seconds();
        CrawlQueue::factory()->create([
            'source_slug' => fake()->slug(2),
            'kind' => CrawlQueue::KIND_LISTING,
            'status' => CrawlQueue::STATUS_PENDING,
            'next_attempt_at' => null,
        ]);

        config()->set([
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.email' => 'root@javmeta.local',
            'openobserve.password' => 'secret',
            'openobserve.timeout' => 1,
        ]);
        Http::fake(['openobserve.test/*' => Http::response(['code' => 200], 200)]);

        $this->artisan('obs:publish-metrics')->assertSuccessful();

        Http::assertSent(static function ($request): bool {
            $metrics = $request->data()['resourceMetrics'][0]['scopeMetrics'][0]['metrics'] ?? [];
            $age = null;
            $stale = [];
            foreach ($metrics as $metric) {
                if (($metric['name'] ?? null) === 'javmeta_crawl_dispatch_age_seconds') {
                    $age = $metric['gauge']['dataPoints'][0]['asDouble'] ?? null;
                }
                if (($metric['name'] ?? null) !== 'javmeta_crawl_dispatch_stale') {
                    continue;
                }

                $point = $metric['gauge']['dataPoints'][0] ?? [];
                $attributes = [];
                foreach ($point['attributes'] ?? [] as $attribute) {
                    $attributes[$attribute['key']] = $attribute['value']['stringValue'] ?? null;
                }
                $stale[$attributes['severity'] ?? ''] = $point['asDouble'] ?? null;
            }

            return $age === 200.0
                && ($stale['warning'] ?? null) === 1.0
                && ($stale['critical'] ?? null) === 0.0;
        });
    }

    public function test_missing_dispatch_timestamp_alerts_only_while_due_work_is_pending(): void
    {
        config()->set([
            'cache.default' => 'array',
            'javmeta_alerts.watchdog.crawl_dispatch_warning_seconds' => 60,
            'javmeta_alerts.watchdog.crawl_dispatch_critical_seconds' => 180,
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.email' => 'root@javmeta.local',
            'openobserve.password' => 'secret',
            'openobserve.timeout' => 1,
        ]);
        Cache::store('array')->flush();
        CrawlQueue::factory()->create([
            'source_slug' => fake()->slug(2),
            'kind' => CrawlQueue::KIND_LISTING,
            'status' => CrawlQueue::STATUS_PENDING,
            'next_attempt_at' => null,
        ]);
        Http::fake(['openobserve.test/*' => Http::response(['code' => 200], 200)]);

        $this->artisan('obs:publish-metrics')->assertSuccessful();

        Http::assertSent(static function ($request): bool {
            $metrics = $request->data()['resourceMetrics'][0]['scopeMetrics'][0]['metrics'] ?? [];
            $values = [];
            foreach ($metrics as $metric) {
                if (($metric['name'] ?? null) !== 'javmeta_crawl_dispatch_stale') {
                    continue;
                }

                $point = $metric['gauge']['dataPoints'][0] ?? [];
                $severity = null;
                foreach ($point['attributes'] ?? [] as $attribute) {
                    if (($attribute['key'] ?? null) === 'severity') {
                        $severity = $attribute['value']['stringValue'] ?? null;
                    }
                }
                $values[$severity ?? ''] = $point['asDouble'] ?? null;
            }

            return ($values['warning'] ?? null) === 1.0
                && ($values['critical'] ?? null) === 1.0;
        });
    }

    public function test_old_dispatch_age_does_not_alert_when_no_crawl_work_is_due(): void
    {
        config()->set([
            'cache.default' => 'array',
            'javmeta_alerts.watchdog.crawl_dispatch_warning_seconds' => 60,
            'javmeta_alerts.watchdog.crawl_dispatch_critical_seconds' => 180,
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.email' => 'root@javmeta.local',
            'openobserve.password' => 'secret',
            'openobserve.timeout' => 1,
        ]);
        Cache::store('array')->flush();
        app(CrawlDispatchHeartbeat::class)->record();
        $this->travel(200)->seconds();
        Http::fake(['openobserve.test/*' => Http::response(['code' => 200], 200)]);

        $this->artisan('obs:publish-metrics')->assertSuccessful();

        Http::assertSent(static function ($request): bool {
            $metrics = $request->data()['resourceMetrics'][0]['scopeMetrics'][0]['metrics'] ?? [];
            $values = [];
            foreach ($metrics as $metric) {
                if (($metric['name'] ?? null) !== 'javmeta_crawl_dispatch_stale') {
                    continue;
                }

                $point = $metric['gauge']['dataPoints'][0] ?? [];
                $severity = null;
                foreach ($point['attributes'] ?? [] as $attribute) {
                    if (($attribute['key'] ?? null) === 'severity') {
                        $severity = $attribute['value']['stringValue'] ?? null;
                    }
                }
                $values[$severity ?? ''] = $point['asDouble'] ?? null;
            }

            return ($values['warning'] ?? null) === 0.0
                && ($values['critical'] ?? null) === 0.0;
        });
    }

    public function test_queue_reclaims_without_a_worker_start_are_included_in_the_event_metric(): void
    {
        CrawlEvent::query()->create([
            'source_slug' => 'onejav',
            'kind' => CrawlEvent::KIND_QUEUE_RECLAIMED_WITHOUT_START,
            'url' => null,
            'detail' => ['crawl_queue_id' => 1],
            'created_at' => now(),
        ]);

        config()->set([
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.email' => 'root@javmeta.local',
            'openobserve.password' => 'secret',
            'openobserve.timeout' => 1,
        ]);
        Http::fake(['openobserve.test/*' => Http::response(['code' => 200], 200)]);

        $this->artisan('obs:publish-metrics')->assertSuccessful();

        Http::assertSent(static fn($request): bool => str_contains($request->body(), 'javmeta_crawl_events_5m')
            && str_contains($request->body(), CrawlEvent::KIND_QUEUE_RECLAIMED_WITHOUT_START));
    }

    public function test_source_past_due_metric_respects_manifest_cycles_and_missing_success(): void
    {
        Source::factory()->create([
            'slug' => 'eporner',
            'gap_seconds_current' => 30,
            'last_success_at' => now()->subDays(3),
        ]);
        Source::factory()->create([
            'slug' => 'warashi',
            'gap_seconds_current' => 30,
            'last_success_at' => null,
        ]);

        config()->set([
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.email' => 'root@javmeta.local',
            'openobserve.password' => 'secret',
            'openobserve.timeout' => 1,
        ]);
        Http::fake(['openobserve.test/*' => Http::response(['code' => 200], 200)]);

        $this->artisan('obs:publish-metrics')->assertSuccessful();

        Http::assertSent(static function ($request): bool {
            $metrics = $request->data()['resourceMetrics'][0]['scopeMetrics'][0]['metrics'] ?? [];
            $states = [];
            foreach ($metrics as $metric) {
                if (($metric['name'] ?? null) !== 'javmeta_source_past_due') {
                    continue;
                }
                foreach ($metric['gauge']['dataPoints'] ?? [] as $point) {
                    $attributes = [];
                    foreach ($point['attributes'] ?? [] as $attribute) {
                        $attributes[$attribute['key']] = $attribute['value']['stringValue'] ?? null;
                    }
                    if (isset($attributes['source_slug'])) {
                        $states[$attributes['source_slug']] = (float) ($point['asDouble'] ?? -1);
                    }
                }
            }

            return ($states['eporner'] ?? null) === 0.0 && ($states['warashi'] ?? null) === 1.0;
        });
    }

    public function test_command_skips_when_disabled(): void
    {
        config()->set('openobserve.enabled', false);
        Http::fake();

        $this->artisan('obs:publish-metrics')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_command_publishes_the_cumulative_mongo_archive_write_failure_metric(): void
    {
        Cache::store('array')->flush();
        app(MongoArchiveWriteHealth::class)->recordFailure();
        config()->set([
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.email' => 'root@javmeta.local',
            'openobserve.password' => 'secret',
            'openobserve.timeout' => 1,
        ]);
        Http::fake(['openobserve.test/*' => Http::response(['code' => 200], 200)]);

        $this->artisan('obs:publish-metrics')->assertSuccessful();

        Http::assertSent(static function ($request): bool {
            $metrics = $request->data()['resourceMetrics'][0]['scopeMetrics'][0]['metrics'] ?? [];
            foreach ($metrics as $metric) {
                if (($metric['name'] ?? null) === 'javmeta_mongo_archive_write_failures') {
                    return ($metric['gauge']['dataPoints'][0]['asDouble'] ?? null) === 1.0;
                }
            }

            return false;
        });
    }

    public function test_command_publishes_the_cumulative_elasticsearch_request_failure_metric(): void
    {
        Cache::store('array')->flush();
        app(ElasticsearchRequestHealth::class)->recordFailure('search', 503);
        config()->set([
            'elasticsearch.host' => 'http://elasticsearch.test',
            'elasticsearch.username' => 'javmeta-test-user',
            'elasticsearch.password' => 'javmeta-test-password',
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.email' => 'root@javmeta.local',
            'openobserve.password' => 'secret',
            'openobserve.timeout' => 1,
        ]);
        Http::fake([
            'openobserve.test/*' => Http::response(['code' => 200], 200),
            'elasticsearch.test/*' => Http::response(['status' => 'green'], 200),
        ]);

        $this->artisan('obs:publish-metrics')->assertSuccessful();

        Http::assertSent(static fn($request): bool => str_contains($request->url(), 'elasticsearch.test')
            && $request->hasHeader(
                'Authorization',
                'Basic ' . base64_encode('javmeta-test-user:javmeta-test-password'),
            ));
        Http::assertSent(static function ($request): bool {
            $metrics = $request->data()['resourceMetrics'][0]['scopeMetrics'][0]['metrics'] ?? [];
            foreach ($metrics as $metric) {
                if (($metric['name'] ?? null) === 'javmeta_elasticsearch_request_failures') {
                    return ($metric['gauge']['dataPoints'][0]['asDouble'] ?? null) === 1.0;
                }
            }

            return false;
        });
    }

    public function test_alert_metric_query_failure_does_not_drop_the_other_metrics(): void
    {
        config()->set([
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.email' => 'root@javmeta.local',
            'openobserve.password' => 'secret',
            'openobserve.timeout' => 1,
        ]);
        Http::fake(['openobserve.test/*' => Http::response(['code' => 200], 200)]);
        Schema::drop('alert_events');

        $this->artisan('obs:publish-metrics')
            ->expectsOutputToContain('Published')
            ->assertSuccessful();

        Http::assertSent(static fn($request): bool => str_contains($request->body(), 'javmeta_crawl_queue_claimed_age_seconds')
            && ! str_contains($request->body(), 'javmeta_alert_firing'));
    }

    public function test_command_publishes_egress_metrics_with_disposable_telemetry_configuration(): void
    {
        config()->set([
            'cache.default' => 'array',
            'javmeta_browser.playwright_url' => 'http://browser-a.test',
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.stream_logs' => 'egress-test-logs',
            'openobserve.email' => 'root@javmeta.local',
            'openobserve.password' => 'test-only',
            'openobserve.timeout' => 1,
        ]);
        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
        $node = mb_substr(preg_replace('/[^A-Za-z0-9_.-]/', '-', (string) gethostname()) ?? 'local', 0, 64);
        Cache::put('javmeta:egress:' . $node, ['ip' => '198.51.100.9', 'probe_ok' => true]);
        Cache::forever('javmeta:egress-meta:' . $node, ['last_known_ip' => '198.51.100.9', 'change_count' => 3]);
        Cache::put('javmeta:browser-egress:browser-a.test', ['ip' => '203.0.113.9', 'probe_ok' => true]);
        $startedAt = now()->subSeconds(10);
        DB::table('crawl_fetch_attempts')->insert([
            [
                'created_at' => $startedAt,
                'source_slug' => 'ffjav',
                'crawl_queue_id' => null,
                'url_hash' => str_repeat('a', 64),
                'method' => 'playwright',
                'started_at' => $startedAt,
                'duration_ms' => 400,
                'http_status' => 403,
                'cf_mitigated' => 'challenge',
                'outcome' => 'error',
                'egress_ip' => '198.51.100.9',
                'node' => $node,
                'egress_kind' => 'node',
            ],
            [
                'created_at' => $startedAt,
                'source_slug' => 'ffjav',
                'crawl_queue_id' => null,
                'url_hash' => str_repeat('b', 64),
                'method' => 'playwright',
                'started_at' => $startedAt,
                'duration_ms' => 200,
                'http_status' => 200,
                'cf_mitigated' => null,
                'outcome' => 'ok',
                'egress_ip' => '198.51.100.9',
                'node' => $node,
                'egress_kind' => 'node',
            ],
        ]);
        Http::fake(['openobserve.test/*' => Http::response(['code' => 200], 200)]);

        $this->artisan('obs:publish-metrics')->assertSuccessful();

        $requests = Http::recorded()->map(static fn(array $pair) => $pair[0]);
        $request = $requests->first(static fn($candidate): bool => str_contains($candidate->url(), '/v1/metrics'));
        self::assertNotNull($request, 'The metrics endpoint was not called.');
        $metrics = $request->data()['resourceMetrics'][0]['scopeMetrics'][0]['metrics'] ?? [];
        $hasPoint = static function (string $name, array $expectedLabels, float $value) use ($metrics): bool {
            foreach ($metrics as $metric) {
                if (($metric['name'] ?? null) !== $name) {
                    continue;
                }
                foreach ($metric['gauge']['dataPoints'] ?? [] as $point) {
                    $labels = [];
                    foreach ($point['attributes'] ?? [] as $attribute) {
                        $labels[$attribute['key']] = $attribute['value']['stringValue'] ?? null;
                    }
                    foreach ($expectedLabels as $key => $expected) {
                        if (($labels[$key] ?? null) !== $expected) {
                            continue 2;
                        }
                    }

                    if ((float) ($point['asDouble'] ?? 0) === $value) {
                        return true;
                    }
                }
            }

            return false;
        };

        self::assertTrue($hasPoint('javmeta_fetch_attempts_by_egress', ['source' => 'ffjav', 'method' => 'playwright', 'egress_ip' => '198.51.100.9', 'outcome' => 'error'], 1.0));
        self::assertTrue($hasPoint('javmeta_block_ratio_by_egress', ['egress_ip' => '198.51.100.9'], 0.5));
        self::assertTrue($hasPoint('javmeta_node_egress_info', ['node' => $node, 'ip' => '198.51.100.9'], 1.0));
        self::assertTrue($hasPoint('javmeta_node_egress_changes_total', ['node' => $node], 3.0));
        self::assertTrue($hasPoint('javmeta_node_egress_probe_ok', ['node' => $node], 1.0));
        self::assertTrue($hasPoint('javmeta_browser_egress_info', ['host' => 'browser-a.test', 'ip' => '203.0.113.9'], 1.0));
    }
}
