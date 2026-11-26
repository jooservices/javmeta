<?php

declare(strict_types=1);

namespace Tests\Integration\Api\V2;

use App\Models\Movie;
use App\Models\Performer;
use App\Models\Source;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** The Command Center endpoints against real PostgreSQL: aggregates, hour truncation, jsonb events and timestamptz output. */
final class OperationsTest extends PostgresApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The application writes UTC wall-clock times; a session in another zone would shift them.
        DB::statement("SET TIME ZONE 'UTC'");
    }

    /** @return array<string, string> */
    private function signIn(): array
    {
        $this->actingAs(User::factory()->create(['role' => 'super_administrator']), 'web')->getJson('/api/v2/sessions/current');

        return ['X-CSRF-Token' => (string) $this->app['session.store']->token()];
    }

    private function seedCrawl(): void
    {
        Source::factory()->create(['slug' => 'alpha', 'name' => 'Alpha', 'base_url' => 'https://alpha.test', 'priority' => 1, 'gap_seconds_default' => 20, 'gap_seconds_current' => 45.5]);
        Source::factory()->create(['slug' => 'beta', 'name' => 'Beta', 'base_url' => 'https://beta.test', 'priority' => 2, 'enabled' => false]);
        foreach ([1, 2] as $hoursAgo) {
            DB::table('crawl_stats_hourly')->insert([
                'hour_at' => now()->startOfHour()->subHours($hoursAgo), 'source_slug' => 'alpha', 'lane' => 'direct', 'method' => 'http', 'attempts' => 100, 'ok' => 80, 'blocked' => 10,
                'timeout' => 0, 'other_error' => 10, 'skipped' => 0, 'avg_duration_ms' => 400.5, 'p90_duration_ms' => 900, 'movies_new' => 5, 'movies_updated' => 1, 'performers_new' => 2,
                'queue_ready_avg' => 12.4, 'postgres_connections_avg' => 90, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $runId = DB::table('crawl_runs')->insertGetId(['source_slug' => 'alpha', 'started_at' => now()->subMinutes(5), 'finished_at' => now()->subMinutes(4), 'status' => 'completed', 'pages_fetched' => 3, 'movies_new' => 1, 'movies_updated' => 2, 'failures' => 0, 'browser_fetches' => 1]);
        DB::table('crawl_queue')->insert(['source_slug' => 'alpha', 'url' => 'a', 'kind' => 'movie', 'status' => 'pending', 'next_attempt_at' => now()]);
        DB::table('crawl_queue')->insert(['source_slug' => 'alpha', 'url' => 'b', 'kind' => 'movie', 'status' => 'claimed', 'claimed_at' => now()->subMinutes(30), 'claimed_lane' => 'browser']);
        $attempt = static fn(string $outcome, ?string $code, string $node, string $ip): array => [
            'created_at' => now()->subMinutes(4)->subSeconds(30), 'source_slug' => 'alpha', 'url_hash' => str_repeat('a', 64), 'method' => 'http', 'started_at' => now()->subMinutes(5), 'duration_ms' => 200,
            'http_status' => 200, 'outcome' => $outcome, 'error_code' => $code, 'lane' => 'direct', 'node' => $node, 'egress_ip' => $ip,
        ];
        DB::table('crawl_fetch_attempts')->insert([$attempt('ok', null, 'n1', '1.1.1.1'), $attempt('error', 'fetch_error', 'n1', '2.2.2.2'), $attempt('error', 'fetch_error', 'n2', '3.3.3.3')]);
        DB::table('crawl_events')->insert(['source_slug' => 'alpha', 'kind' => 'listing_parsed', 'url' => 'https://alpha.test/1', 'detail' => json_encode(['items' => 3]), 'created_at' => now()]);
        DB::table('alert_events')->insert(['rule' => 'success_rate', 'severity' => 'warning', 'value' => 85.59, 'threshold' => 85, 'started_at' => now()->subHour()]);
        DB::table('review_flags')->insert(['code_normalized' => 'url:abc', 'source_slug' => 'alpha', 'consecutive_failures' => 2, 'reason' => 'consecutive_crawl_failure', 'flagged_at' => now()]);
        $this->assertGreaterThan(0, $runId);
    }

    public function test_the_status_summary_counts_the_read_models_and_the_sources(): void
    {
        $this->seedCrawl();
        Movie::factory()->count(2)->create();
        Performer::factory()->create();
        $this->artisan('xcrawler:read-models:refresh')->assertSuccessful();

        $this->getJson('/api/v2/status/summary')->assertOk()->assertJsonPath('data.movies', 2)->assertJsonPath('data.performers', 1)->assertJsonPath('data.sources_ok', 1)->assertJsonPath('data.sources_enabled', 1);
    }

    public function test_overview_performance_and_sources_aggregate_in_postgresql(): void
    {
        $this->seedCrawl();
        $this->signIn();

        $overview = $this->getJson('/api/v2/operations/overview')->assertOk()->json('data');
        $this->assertSame(['attempts' => 200, 'ok' => 160, 'blocked' => 20, 'timeout' => 0, 'errors' => 20, 'movies_new' => 10, 'performers_new' => 4], $overview['last_24h']);
        $this->assertSame(['pending' => 1, 'claimed' => 1, 'failed' => 0], $overview['queue']);
        $this->assertCount(2, $overview['hourly']);
        $this->assertSame('85.59', $overview['alerts'][0]['value']);
        $this->assertSame(['enabled' => 1, 'total' => 2, 'circuit_open' => 0], $overview['sources']);

        $performance = $this->getJson('/api/v2/operations/performance')->assertOk()->json('data');
        $this->assertSame(['attempts' => 100, 'avg_ms' => 401, 'p90' => 900, 'queue_ready' => 12, 'pg' => 90], array_intersect_key($performance['hourly'][0], array_flip(['attempts', 'avg_ms', 'p90', 'queue_ready', 'pg'])));
        $this->assertSame([['claimed_lane' => 'browser', 'n' => 1]], $performance['lanes']);
        $this->assertSame(1, $performance['runs']['n10']);

        $sources = $this->getJson('/api/v2/sources')->assertOk()->json('data');
        $this->assertSame(['alpha', 'beta'], array_column($sources, 'slug'));
        $this->assertSame(['stable', 'disabled'], array_column($sources, 'status'));
        $this->assertSame('45.50', $sources[0]['gap_seconds_current']);
        $this->assertSame(1, $sources[0]['running']);
        $this->assertCount(2, $this->getJson('/api/v2/sources/alpha')->assertOk()->json('data.history'));
    }

    public function test_switching_a_source_writes_a_jsonb_event_and_runs_are_paged_with_their_attempts(): void
    {
        $this->seedCrawl();
        $headers = $this->signIn();

        $this->patchJson('/api/v2/sources/alpha', ['enabled' => false], $headers)->assertOk()->assertJsonPath('data.enabled', false);
        $event = DB::table('crawl_events')->where('kind', 'source_toggled')->first();
        $this->assertEquals(['from' => true, 'to' => false], array_intersect_key(json_decode((string) $event->detail, true), ['from' => 1, 'to' => 1]));

        $runs = $this->getJson('/api/v2/crawl-runs?source=alpha')->assertOk();
        $runs->assertJsonPath('meta.pagination.total', 1);
        $id = $runs->json('data.0.id');
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $runs->json('data.0.started_at'));
        $this->getJson("/api/v2/crawl-runs/{$id}")->assertOk()->assertJsonPath('data.browser_fetches', 1);
        $this->assertCount(3, $this->getJson("/api/v2/crawl-runs/{$id}/attempts")->assertOk()->json('data'));
    }

    public function test_queue_errors_events_and_nodes_group_correctly_in_postgresql(): void
    {
        $this->seedCrawl();
        $this->signIn();

        $queue = collect($this->getJson('/api/v2/crawl-queue')->assertOk()->json('data'))->keyBy('status');
        $this->assertSame(1, $queue['claimed']['stale']);
        $this->assertSame(1, $queue['pending']['n']);

        $this->assertSame([['source_slug' => 'alpha', 'error_code' => 'fetch_error', 'n' => 2]], array_map(static fn(array $row): array => array_intersect_key($row, array_flip(['source_slug', 'error_code', 'n'])), $this->getJson('/api/v2/crawl-errors')->assertOk()->json('data')));

        $events = $this->getJson('/api/v2/events?kind=listing_parsed')->assertOk();
        $this->assertSame(['items' => 3], $events->json('data.0.detail'));
        $this->assertSame([['kind' => 'listing_parsed', 'n' => 1]], $events->json('meta.kinds'));

        $nodes = collect($this->getJson('/api/v2/nodes')->assertOk()->json('data'))->keyBy('node');
        $this->assertSame([2, 1], [$nodes['n1']['attempts_24h'], $nodes['n1']['ok_24h']]);
        $this->assertSame(2, $nodes['n1']['lanes'][0]['egress_ips']);

        $node = $this->getJson('/api/v2/nodes/n1')->assertOk()->json('data');
        $this->assertSame(2, array_sum(array_column($node['hourly'], 'attempts')));
        $this->assertMatchesRegularExpression('/:00:00\.000Z$/', $node['hourly'][0]['hour_at']);
        $this->assertSame([['source_slug' => 'alpha', 'n' => 2]], $node['contribution_24h']);
    }

    public function test_enrollments_alerts_and_review_flags_work_in_postgresql(): void
    {
        $this->seedCrawl();
        $headers = $this->signIn();

        $id = $this->postJson('/api/v2/node-enrollments', ['node_name' => 'worker-9'], $headers)->assertCreated()->json('data.id');
        $this->assertSame(['created'], array_column($this->getJson('/api/v2/node-enrollments')->assertOk()->json('data'), 'state'));
        $this->deleteJson("/api/v2/node-enrollments/{$id}", [], $headers)->assertNoContent();
        $this->assertSame([], $this->getJson('/api/v2/node-enrollments')->json('data'));

        $this->getJson('/api/v2/alerts?state=active')->assertOk()->assertJsonPath('meta.pagination.total', 1)->assertJsonPath('data.0.threshold', '85.00');

        $flag = $this->getJson('/api/v2/review-flags')->assertOk()->json('data.0.id');
        $this->patchJson("/api/v2/review-flags/{$flag}", ['state' => 'resolved'], $headers)->assertOk();
        $this->getJson('/api/v2/review-flags')->assertJsonPath('meta.pagination.total', 0);
        $this->getJson('/api/v2/review-flags?state=resolved')->assertJsonPath('meta.pagination.total', 1);
    }
}
