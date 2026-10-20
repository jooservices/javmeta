<?php

declare(strict_types=1);

namespace Tests\Feature\Health;

use App\Models\Source;
use App\Services\Archive\MongoArchiveWriteHealth;
use App\Services\Crawl\WorkerHeartbeat;
use App\Services\Dependencies\Dependency;
use App\Services\Search\ElasticsearchHttpClient;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\DependencyMonitorTestHelper;
use Tests\TestCase;

final class HealthControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DependencyMonitorTestHelper::bind();
    }

    public function test_health_is_public_and_includes_source_status(): void
    {
        Source::query()->create([
            'slug' => 'javdb',
            'name' => 'JavDB',
            'base_url' => 'https://javdb.com',
            'enabled' => true,
            'priority' => 10,
            'needs_proxy' => false,
            'gap_seconds_default' => 20,
            'gap_seconds_min' => 20,
            'gap_seconds_max' => 60,
            'gap_seconds_current' => 20,
            'last_success_at' => now(),
        ]);

        app(WorkerHeartbeat::class)->beat();

        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('database', 'ok')
            ->assertJsonPath('sources.0.slug', 'javdb')
            ->assertJsonPath('worker.stale', false);

        $this->assertIsArray($response->json('instances'));

        $this->assertArrayNotHasKey('movies', $response->json());
        $this->assertArrayNotHasKey('titles', $response->json());
    }

    public function test_health_marks_degraded_when_worker_stale(): void
    {
        Cache::forget(WorkerHeartbeat::CACHE_KEY);

        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('worker.stale', true);
    }

    public function test_health_reports_archive_write_failures_and_recovers_after_a_success(): void
    {
        app(WorkerHeartbeat::class)->beat();
        $archiveWrites = app(MongoArchiveWriteHealth::class);
        $archiveWrites->recordFailure();

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('archive.status', 'degraded')
            ->assertJsonPath('archive.failed_writes', 1);

        $archiveWrites->recordSuccess();

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('archive.status', 'ok')
            ->assertJsonPath('archive.failed_writes', 1);
    }

    public function test_health_reports_each_dependency_with_state_and_hardness(): void
    {
        DependencyMonitorTestHelper::bind([Dependency::Elasticsearch->value => false]);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('dependencies.postgres.state', 'healthy')
            ->assertJsonPath('dependencies.postgres.hard', true)
            ->assertJsonPath('dependencies.redis.state', 'healthy')
            ->assertJsonPath('dependencies.elasticsearch.state', 'down')
            ->assertJsonPath('dependencies.elasticsearch.hard', false)
            ->assertJsonPath('dependencies.mongo.state', 'healthy')
            ->assertJsonPath('dependencies.embedder.state', 'healthy')
            ->assertJsonPath('dependencies.observability.state', 'healthy');
    }

    public function test_health_exposes_a_failed_search_with_its_status_code_and_count(): void
    {
        config()->set('elasticsearch.host', 'http://elasticsearch.test');
        DependencyMonitorTestHelper::bind();
        Http::fake(['elasticsearch.test/*' => Http::response([], 403)]);

        app(ElasticsearchHttpClient::class)->post('search', '/javmeta_test_movies/_search', ['query' => []]);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('dependencies.elasticsearch.state', 'down')
            ->assertJsonPath('dependencies.elasticsearch.available', false)
            ->assertJsonPath('dependencies.elasticsearch.request_failures', 1)
            ->assertJsonPath('dependencies.elasticsearch.last_failure.operation', 'search')
            ->assertJsonPath('dependencies.elasticsearch.last_failure.status_code', 403);
    }

    public function test_health_skips_database_dependent_queries_when_postgres_is_down(): void
    {
        config()->set('cache.default', 'database');
        DependencyMonitorTestHelper::bind([Dependency::Postgres->value => false]);
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->getJson('/api/health')
            ->assertServiceUnavailable()
            ->assertJsonPath('status', 'down')
            ->assertJsonPath('database', 'down')
            ->assertJsonPath('queue.pending', 0)
            ->assertJsonPath('dependencies.postgres.state', 'down')
            ->assertJsonPath('archive.status', 'unknown')
            ->assertJsonPath('archive.failed_writes', null);

        self::assertSame([], $queries);
    }

    public function test_live_health_is_process_only_and_never_checks_dependencies(): void
    {
        $monitor = DependencyMonitorTestHelper::bind(array_fill_keys(
            array_map(static fn(Dependency $dependency): string => $dependency->value, Dependency::cases()),
            false,
        ));

        $this->getJson('/api/health/live')
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        self::assertSame('unknown', $monitor->state(Dependency::Postgres));
    }
}
