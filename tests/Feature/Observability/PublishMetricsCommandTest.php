<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use App\Console\Commands\Observability\PublishMetricsCommand;
use App\Models\CrawlEvent;
use App\Models\Source;
use App\Services\Archive\MongoArchiveWriteHealth;
use App\Services\Search\ElasticsearchRequestHealth;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PublishMetricsCommandTest extends TestCase
{
    use RefreshDatabase;

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
            return str_contains($request->url(), '/v1/metrics')
                && str_contains($request->body(), 'javmeta_source_gap_seconds')
                && ! str_contains($request->body(), 'javmeta_source_circuit_state')
                && ! str_contains($request->body(), 'javmeta_source_consecutive_failures');
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
}
