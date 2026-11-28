<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V2;

use App\Models\Movie;
use App\Models\Performer;
use App\Models\Source;
use App\Models\User;
use App\Services\Api\V2\Contracts\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** The Command Center part of the v2 API: crawl health, sources, runs, events, nodes, infrastructure, alerts and review flags. */
final class OperationsTest extends V2TestCase
{
    use RefreshDatabase;

    /** @var list<array{0: ?int, 1: string, 2: string, 3: array<string, mixed>}> */
    private array $audited = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->audited = [];
        $this->app->instance(AuditLog::class, new class ($this->audited) implements AuditLog {
            /** @param array<int, mixed> $rows */
            public function __construct(private array &$rows) {}

            public function record(string $actor, string $action, string $target, array $details = []): void
            {
                $this->rows[] = [$actor, $action, $target, $details];
            }
        });
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_administrator']);
    }

    /** @return array<string, string> */
    private function signIn(User $user): array
    {
        $this->actingAs($user, 'web')->getJson('/api/v2/sessions/current');

        return ['X-CSRF-Token' => (string) $this->app['session.store']->token()];
    }

    private function addSource(string $slug, array $overrides = []): void
    {
        Source::factory()->create(['slug' => $slug, 'name' => strtoupper($slug), 'base_url' => "https://{$slug}.test", 'gap_seconds_default' => 20, 'gap_seconds_current' => 45.5] + $overrides);
    }

    private function addStats(string $slug, int $hoursAgo, int $attempts, int $ok, int $blocked = 0, int $errors = 0, int $new = 0): void
    {
        DB::table('crawl_stats_hourly')->insert([
            'hour_at' => now()->startOfHour()->subHours($hoursAgo), 'source_slug' => $slug, 'lane' => 'direct', 'method' => 'http',
            'attempts' => $attempts, 'ok' => $ok, 'blocked' => $blocked, 'timeout' => 0, 'other_error' => $errors, 'skipped' => 0,
            'avg_duration_ms' => 400, 'p90_duration_ms' => 900, 'movies_new' => $new, 'movies_updated' => 1, 'performers_new' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function crawlRun(string $slug, string $status = 'completed', int $minutesAgo = 5): int
    {
        return (int) DB::table('crawl_runs')->insertGetId([
            'source_slug' => $slug, 'started_at' => now()->subMinutes($minutesAgo), 'finished_at' => now()->subMinutes($minutesAgo - 1), 'status' => $status,
            'pages_fetched' => 3, 'movies_new' => 1, 'movies_updated' => 2, 'failures' => 0, 'proxy_requests' => 4, 'proxy_bytes' => 5, 'browser_fetches' => 6,
        ]);
    }

    /** @return list<string> */
    private static function adminGets(): array
    {
        return ['operations/overview', 'operations/performance', 'infrastructure', 'sources', 'sources/x', 'crawl-runs', 'crawl-runs/1', 'crawl-runs/1/attempts', 'crawl-queue', 'crawl-errors', 'events', 'events/stream', 'nodes', 'nodes/x', 'node-enrollments', 'alerts', 'review-flags'];
    }

    public function test_every_command_center_read_needs_an_administrator(): void
    {
        foreach (self::adminGets() as $path) {
            $this->getJson("/api/v2/{$path}")->assertUnauthorized()->assertJsonPath('code', 'authentication_required');
        }

        $this->actingAs(User::factory()->create(['role' => 'user']), 'web');
        foreach (self::adminGets() as $path) {
            $this->getJson("/api/v2/{$path}")->assertForbidden()->assertJsonPath('code', 'forbidden');
        }
    }

    public function test_every_command_center_write_needs_an_administrator_and_a_csrf_token(): void
    {
        $writes = [['patchJson', 'sources/x', ['enabled' => true]], ['postJson', 'node-enrollments', ['node_name' => 'abc']], ['deleteJson', 'node-enrollments/1', []], ['patchJson', 'review-flags/1', ['state' => 'resolved']]];
        foreach ($writes as [$method, $path, $body]) {
            $this->{$method}("/api/v2/{$path}", $body)->assertUnauthorized();
        }

        $this->actingAs(User::factory()->create(['role' => 'user']), 'web')->getJson('/api/v2/sessions/current');
        $owner = ['X-CSRF-Token' => (string) $this->app['session.store']->token()];
        foreach ($writes as [$method, $path, $body]) {
            $this->{$method}("/api/v2/{$path}", $body, $owner)->assertForbidden()->assertJsonPath('code', 'forbidden');
        }

        $this->actingAs($this->admin(), 'web');
        foreach ($writes as [$method, $path, $body]) {
            $this->{$method}("/api/v2/{$path}", $body)->assertForbidden()->assertJsonPath('code', 'csrf_mismatch');
        }
    }

    public function test_the_status_summary_is_public_and_counts_the_catalogue_and_the_sources(): void
    {
        Movie::factory()->count(2)->create();
        Performer::factory()->create();
        $this->addSource('alpha');
        $this->addSource('beta', ['enabled' => false]);
        $this->addSource('gamma', ['circuit_state' => 'open']);

        $this->getJson('/api/v2/status/summary')->assertOk()->assertExactJson(['data' => ['movies' => 2, 'performers' => 1, 'sources_ok' => 1, 'sources_enabled' => 2], 'meta' => []]);
    }

    public function test_the_overview_sums_the_last_day_and_lists_queue_alerts_and_sources(): void
    {
        $this->actingAs($this->admin(), 'web');
        $this->addSource('alpha');
        $this->addSource('beta', ['circuit_state' => 'open']);
        $this->addStats('alpha', 1, 100, 90, 4, 6, 3);
        $this->addStats('alpha', 30, 50, 50);
        DB::table('crawl_queue')->insert([['source_slug' => 'alpha', 'url' => 'a', 'kind' => 'movie', 'status' => 'pending'], ['source_slug' => 'alpha', 'url' => 'b', 'kind' => 'movie', 'status' => 'claimed'], ['source_slug' => 'alpha', 'url' => 'c', 'kind' => 'movie', 'status' => 'failed'], ['source_slug' => 'alpha', 'url' => 'd', 'kind' => 'movie', 'status' => 'done']]);
        DB::table('alert_events')->insert(['rule' => 'success_rate', 'severity' => 'warning', 'value' => 85.5, 'threshold' => 90, 'started_at' => now()->subHour()]);

        $data = $this->getJson('/api/v2/operations/overview')->assertOk()->json('data');

        $this->assertSame(['attempts' => 100, 'ok' => 90, 'blocked' => 4, 'timeout' => 0, 'errors' => 6, 'movies_new' => 3, 'performers_new' => 2], $data['last_24h']);
        $this->assertCount(2, $data['hourly']);
        $this->assertSame(['pending' => 1, 'claimed' => 1, 'failed' => 1], $data['queue']);
        $this->assertSame(['enabled' => 2, 'total' => 2, 'circuit_open' => 1], $data['sources']);
        $this->assertSame(['rule' => 'success_rate', 'value' => '85.50', 'threshold' => '90.00'], array_intersect_key($data['alerts'][0], array_flip(['rule', 'value', 'threshold'])));
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:00:00\.000Z$/', $data['hourly'][0]['hour_at']);
    }

    public function test_the_performance_page_has_hourly_rows_lanes_run_activity_and_the_queue_caps(): void
    {
        $this->actingAs($this->admin(), 'web');
        $this->addStats('alpha', 2, 10, 9, 0, 1);
        $this->addStats('__global__', 2, 99, 99);
        DB::table('crawl_queue')->insert([['source_slug' => 'alpha', 'url' => 'a', 'kind' => 'movie', 'status' => 'claimed', 'claimed_lane' => 'browser'], ['source_slug' => 'alpha', 'url' => 'b', 'kind' => 'movie', 'status' => 'claimed', 'claimed_lane' => 'direct']]);
        $this->crawlRun('alpha', 'completed', 5);
        $this->crawlRun('alpha', 'completed', 30);

        $data = $this->getJson('/api/v2/operations/performance')->assertOk()->json('data');

        $this->assertSame(10, $data['hourly'][0]['attempts']);
        $this->assertSame(400, $data['hourly'][0]['avg_ms']);
        $this->assertSame(900, $data['hourly'][0]['p90']);
        $this->assertNull($data['hourly'][0]['queue_ready']);
        $this->assertEqualsCanonicalizing(['browser', 'direct'], array_column($data['lanes'], 'claimed_lane'));
        $this->assertSame(1, $data['runs']['n10']);
        $this->assertSame(2, $data['runs']['n60']);
        $this->assertNotNull($data['runs']['last']);
        $this->assertArrayHasKey('queue.depth_limit', $data['caps']);
    }

    public function test_sources_are_listed_with_status_lane_and_counters(): void
    {
        $this->actingAs($this->admin(), 'web');
        $this->addSource('alpha', ['priority' => 1]);
        $this->addSource('beta', ['priority' => 2, 'enabled' => false]);
        $this->addSource('gamma', ['priority' => 3, 'circuit_state' => 'open']);
        $this->addSource('delta', ['priority' => 4, 'browser_lane' => true]);
        $this->addSource('omega', ['priority' => 5]);
        $this->addStats('alpha', 1, 100, 100, 0, 0, 24);
        $this->addStats('delta', 1, 100, 40, 20, 40);
        DB::table('crawl_queue')->insert(['source_slug' => 'alpha', 'url' => 'a', 'kind' => 'movie', 'status' => 'claimed']);
        $this->crawlRun('alpha');

        $rows = collect($this->getJson('/api/v2/sources')->assertOk()->json('data'))->keyBy('slug');

        $this->assertSame(['alpha', 'beta', 'gamma', 'delta', 'omega'], $rows->keys()->all());
        $this->assertSame(['stable', 'disabled', 'blocked', 'degraded', 'stable'], $rows->pluck('status')->values()->all());
        $this->assertSame('browser', $rows['delta']['lane']);
        $this->assertSame('direct', $rows['alpha']['lane']);
        $this->assertSame('45.50', $rows['alpha']['gap_seconds_current']);
        $this->assertSame('20.00', $rows['alpha']['gap_seconds_default']);
        $this->assertSame(1, $rows['alpha']['running']);
        $this->assertSame(1, $rows['alpha']['runs_1h']);
        $this->assertEquals(1.0, $rows['alpha']['new_per_hour']);
        $this->assertSame(['attempts' => 100, 'ok' => 100, 'blocked' => 0, 'timeout' => 0, 'errors' => 0, 'movies_new' => 24, 'movies_updated' => 1], $rows['alpha']['last_24h']);
        $this->assertFalse($rows['alpha']['enabled_overridden']);
        $this->assertSame(0, $rows['omega']['last_24h']['attempts']);
    }

    public function test_one_source_comes_with_72_hours_of_history_or_a_404(): void
    {
        $this->actingAs($this->admin(), 'web');
        $this->addSource('alpha');
        $this->addStats('alpha', 1, 10, 10);
        $this->addStats('alpha', 100, 10, 10);

        $data = $this->getJson('/api/v2/sources/alpha')->assertOk()->json('data');
        $this->assertSame('alpha', $data['slug']);
        $this->assertCount(1, $data['history']);
        $this->assertSame(400, $data['history'][0]['avg_ms']);

        $this->getJson('/api/v2/sources/nope')->assertNotFound()->assertJsonPath('code', 'source_not_found');
    }

    public function test_a_source_is_switched_with_an_event_and_an_audit_row_and_an_unchanged_switch_writes_nothing(): void
    {
        $admin = $this->admin();
        $headers = $this->signIn($admin);
        $this->addSource('alpha');

        $this->patchJson('/api/v2/sources/alpha', ['enabled' => false], $headers)->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.status', 'disabled');
        $this->assertFalse((bool) DB::table('sources')->where('slug', 'alpha')->value('enabled'));
        $event = DB::table('crawl_events')->where('source_slug', 'alpha')->first();
        $this->assertSame('source_toggled', $event->kind);
        $this->assertSame(['from' => true, 'to' => false, 'by' => "user:{$admin->id}"], json_decode((string) $event->detail, true));
        $this->assertSame([['user:' . $admin->id, 'source.update', 'sources/alpha', ['enabled' => false, 'was' => true]]], $this->audited);

        $this->patchJson('/api/v2/sources/alpha', ['enabled' => false], $headers)->assertOk();
        $this->assertSame(1, DB::table('crawl_events')->count());
    }

    public function test_switching_a_source_validates_the_body_and_needs_an_existing_source(): void
    {
        $headers = $this->signIn($this->admin());
        $this->addSource('alpha');

        $this->patchJson('/api/v2/sources/alpha', [], $headers)->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonFragment(['field' => 'enabled', 'code' => 'required']);
        $this->patchJson('/api/v2/sources/alpha', ['enabled' => 'maybe'], $headers)->assertStatus(422);
        $this->patchJson('/api/v2/sources/nope', ['enabled' => true], $headers)->assertNotFound()->assertJsonPath('code', 'source_not_found');
        $this->assertSame([], $this->audited);
    }

    public function test_crawl_runs_are_paged_newest_first_and_filtered(): void
    {
        $this->actingAs($this->admin(), 'web');
        $old = $this->crawlRun('alpha', 'completed', 30);
        $this->crawlRun('beta', 'failed', 20);
        $new = $this->crawlRun('alpha', 'completed', 10);

        $all = $this->getJson('/api/v2/crawl-runs?per_page=2')->assertOk();
        $all->assertJsonPath('meta.pagination', ['page' => 1, 'per_page' => 2, 'total' => 3, 'pages' => 2]);
        $this->assertSame($new, $all->json('data.0.id'));
        $this->assertArrayNotHasKey('proxy_bytes', $all->json('data.0'));

        $this->assertSame([$old], array_column($this->getJson('/api/v2/crawl-runs?page=2&per_page=2')->json('data'), 'id'));
        $this->assertSame(2, $this->getJson('/api/v2/crawl-runs?source=alpha')->json('meta.pagination.total'));
        $this->assertSame(1, $this->getJson('/api/v2/crawl-runs?status=failed')->json('meta.pagination.total'));
        $this->assertSame(100, $this->getJson('/api/v2/crawl-runs?per_page=5000')->json('meta.pagination.per_page'));
        $this->assertSame(40, $this->getJson('/api/v2/crawl-runs?per_page=abc')->json('meta.pagination.per_page'));
    }

    public function test_one_crawl_run_and_its_fetch_attempts(): void
    {
        $this->actingAs($this->admin(), 'web');
        $id = $this->crawlRun('alpha', 'completed', 10);
        $open = (int) DB::table('crawl_runs')->insertGetId(['source_slug' => 'alpha', 'started_at' => now()->subMinutes(3), 'finished_at' => null, 'status' => 'running']);
        $attempt = static fn(string $slug, int $minutesAgo, string $outcome = 'ok'): array => ['created_at' => now()->subMinutes($minutesAgo), 'source_slug' => $slug, 'url_hash' => str_repeat('a', 64), 'method' => 'http', 'started_at' => now()->subMinutes($minutesAgo), 'duration_ms' => 120, 'http_status' => 200, 'outcome' => $outcome, 'lane' => 'direct', 'node' => 'n1'];
        DB::table('crawl_fetch_attempts')->insert([$attempt('alpha', 10), $attempt('alpha', 60), $attempt('beta', 10), $attempt('alpha', 2)]);

        $this->getJson("/api/v2/crawl-runs/{$id}")->assertOk()->assertJsonPath('data.proxy_requests', 4)->assertJsonPath('data.proxy_bytes', 5)->assertJsonPath('data.browser_fetches', 6)->assertJsonPath('data.source_slug', 'alpha');
        $this->assertCount(1, $this->getJson("/api/v2/crawl-runs/{$id}/attempts")->assertOk()->json('data'));
        $this->assertSame(['created_at' => $this->getJson("/api/v2/crawl-runs/{$open}/attempts")->json('data.0.created_at'), 'method' => 'http', 'duration_ms' => 120, 'http_status' => 200, 'outcome' => 'ok', 'error_code' => null, 'lane' => 'direct', 'node' => 'n1'], $this->getJson("/api/v2/crawl-runs/{$open}/attempts")->json('data.0'));

        $this->getJson('/api/v2/crawl-runs/999')->assertNotFound()->assertJsonPath('code', 'run_not_found');
        $this->getJson('/api/v2/crawl-runs/999/attempts')->assertNotFound()->assertJsonPath('code', 'run_not_found');
        $this->getJson('/api/v2/crawl-runs/abc')->assertNotFound();
    }

    public function test_the_queue_is_counted_by_source_and_status_with_stale_claims(): void
    {
        $this->actingAs($this->admin(), 'web');
        $row = static fn(string $url, string $status, ?object $at = null, ?object $next = null): array => ['source_slug' => 'alpha', 'url' => $url, 'kind' => 'movie', 'status' => $status, 'next_attempt_at' => $next, 'claimed_at' => $at];
        DB::table('crawl_queue')->insert([
            $row('1', 'pending', next: now()->subDay()),
            $row('2', 'pending', next: now()),
            $row('3', 'claimed', now()->subMinutes(30)),
            $row('4', 'claimed', now()->subMinutes(1)),
            $row('5', 'done'),
        ]);

        $rows = collect($this->getJson('/api/v2/crawl-queue')->assertOk()->json('data'));

        $this->assertEqualsCanonicalizing(['pending', 'claimed'], $rows->pluck('status')->all());
        $this->assertSame(2, $rows->firstWhere('status', 'pending')['n']);
        $this->assertNotNull($rows->firstWhere('status', 'pending')['oldest_next']);
        $this->assertNull($rows->firstWhere('status', 'pending')['oldest_claim']);
        $this->assertSame(1, $rows->firstWhere('status', 'claimed')['stale']);
        $this->assertCount(2, $rows);
    }

    public function test_the_error_table_groups_failures_of_the_last_day_by_code(): void
    {
        $this->actingAs($this->admin(), 'web');
        $attempt = static fn(string $outcome, ?string $code, int $hoursAgo): array => ['created_at' => now()->subHours($hoursAgo), 'source_slug' => 'alpha', 'url_hash' => str_repeat('b', 64), 'method' => 'http', 'started_at' => now()->subHours($hoursAgo), 'duration_ms' => 10, 'outcome' => $outcome, 'error_code' => $code];
        DB::table('crawl_fetch_attempts')->insert([$attempt('error', 'fetch_error', 1), $attempt('error', 'fetch_error', 2), $attempt('skipped', null, 1), $attempt('ok', null, 1), $attempt('error', 'old', 30)]);

        $rows = collect($this->getJson('/api/v2/crawl-errors')->assertOk()->json('data'))->keyBy('error_code');

        $this->assertSame(['fetch_error', 'skipped'], $rows->keys()->all());
        $this->assertSame(2, $rows['fetch_error']['n']);
        $this->assertSame('alpha', $rows['fetch_error']['source_slug']);
        $this->assertNotNull($rows['fetch_error']['last_at']);
    }

    private function crawlEvent(string $kind, string $source = 'alpha', ?array $detail = ['items' => 3], int $minutesAgo = 1): int
    {
        return (int) DB::table('crawl_events')->insertGetId(['source_slug' => $source, 'kind' => $kind, 'url' => 'https://x.test/1', 'detail' => $detail === null ? null : json_encode($detail), 'created_at' => now()->subMinutes($minutesAgo)]);
    }

    public function test_events_are_paged_filtered_by_kind_and_source_and_counted_by_kind(): void
    {
        $this->actingAs($this->admin(), 'web');
        $this->crawlEvent('listing_parsed', 'alpha', ['items' => 3], 3);
        $this->crawlEvent('blocked', 'beta', null, 2);
        $this->crawlEvent('blocked', 'alpha', ['x' => 1], 1);
        $this->crawlEvent('ancient', 'alpha', null, 60 * 24 * 30);

        $all = $this->getJson('/api/v2/events')->assertOk();
        $this->assertSame(['blocked', 'blocked', 'listing_parsed', 'ancient'], array_column($all->json('data'), 'kind'));
        $this->assertSame(['items' => 3], $all->json('data.2.detail'));
        $this->assertNull($all->json('data.1.detail'));
        $this->assertSame([['kind' => 'blocked', 'n' => 2], ['kind' => 'listing_parsed', 'n' => 1]], $all->json('meta.kinds'));

        $this->assertCount(3, $this->getJson('/api/v2/events?kind=blocked,listing_parsed')->json('data'));
        $this->assertCount(2, $this->getJson('/api/v2/events?kind=blocked&source=')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v2/events?kind=blocked&source=alpha')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v2/events?per_page=1&page=2')->json('data'));
    }

    public function test_the_event_stream_sends_recent_events_as_sse_frames_with_a_keep_alive(): void
    {
        $this->actingAs($this->admin(), 'web');
        config()->set('javmeta_ops.stream_seconds', 1);
        $first = $this->crawlEvent('blocked', 'alpha', ['n' => 1], 0);
        $second = $this->crawlEvent('listing_parsed', 'beta', null, 0);
        $this->crawlEvent('too_old', 'beta', null, 30);

        $response = $this->get('/api/v2/events/stream', ['Accept' => 'text/event-stream']);
        $body = $response->streamedContent();

        $response->assertOk();
        $this->assertStringContainsString('text/event-stream', (string) $response->headers->get('Content-Type'));
        $this->assertSame("id: {$first}\nevent: crawl_event\ndata: ", substr($body, 0, strlen("id: {$first}\nevent: crawl_event\ndata: ")));
        $this->assertStringContainsString("id: {$second}\n", $body);
        $this->assertStringNotContainsString('too_old', $body);
        $this->assertStringEndsWith(": keep-alive\n\n", $body);

        $resumed = $this->get('/api/v2/events/stream', ['Last-Event-ID' => (string) $first])->streamedContent();
        $this->assertStringNotContainsString("id: {$first}\n", $resumed);
        $this->assertStringContainsString("id: {$second}\n", $resumed);
    }

    public function test_the_event_stream_keeps_polling_and_picks_up_new_events(): void
    {
        $feed = $this->app->make(\App\Services\Api\V2\Operations\EventFeed::class);
        $frames = $feed->frames(null, 2, 0);

        $this->assertSame(": keep-alive\n\n", $frames->current());
        $this->crawlEvent('blocked', 'alpha', null, 0);
        $frames->next();
        $this->assertStringContainsString('event: crawl_event', (string) $frames->current());
        $frames->next();
        $this->assertSame(": keep-alive\n\n", $frames->current());
        $frames->next();
        $this->assertFalse($frames->valid());
    }

    private function fetchAttempt(string $node, string $lane, string $outcome, int $minutesAgo, string $slug = 'alpha', ?string $ip = '1.1.1.1'): array
    {
        return ['created_at' => now()->subMinutes($minutesAgo), 'source_slug' => $slug, 'url_hash' => str_repeat('c', 64), 'method' => 'http', 'started_at' => now()->subMinutes($minutesAgo), 'duration_ms' => 200, 'outcome' => $outcome, 'lane' => $lane, 'node' => $node, 'egress_ip' => $ip];
    }

    public function test_nodes_are_listed_with_their_lanes_of_the_last_hour_and_totals_of_the_last_day(): void
    {
        $this->actingAs($this->admin(), 'web');
        DB::table('crawl_fetch_attempts')->insert([
            $this->fetchAttempt('n1', 'direct', 'ok', 5), $this->fetchAttempt('n1', 'direct', 'error', 6, ip: '2.2.2.2'), $this->fetchAttempt('n1', 'browser', 'ok', 7, ip: null),
            $this->fetchAttempt('n1', 'direct', 'ok', 600), $this->fetchAttempt('n2', 'direct', 'ok', 600), $this->fetchAttempt('', 'direct', 'ok', 5),
        ]);
        DB::table('crawl_fetch_attempts')->insert(['created_at' => now(), 'source_slug' => 'alpha', 'url_hash' => str_repeat('d', 64), 'method' => 'http', 'started_at' => now(), 'duration_ms' => 1, 'outcome' => 'ok']);

        $nodes = collect($this->getJson('/api/v2/nodes')->assertOk()->json('data'))->keyBy('node');

        $this->assertEqualsCanonicalizing(['n1', ''], $nodes->keys()->all());
        $this->assertSame(4, $nodes['n1']['attempts_24h']);
        $this->assertSame(3, $nodes['n1']['ok_24h']);
        $this->assertSame(['browser', 'direct'], array_column($nodes['n1']['lanes'], 'lane'));
        $direct = $nodes['n1']['lanes'][1];
        $this->assertSame(['node' => 'n1', 'lane' => 'direct', 'attempts' => 2, 'ok' => 1, 'avg_ms' => 200, 'egress_ips' => 2], array_diff_key($direct, ['last_at' => 1]));
        $this->assertNotNull($direct['last_at']);
        $this->assertSame(0, $nodes['n1']['lanes'][0]['egress_ips']);
        $this->assertArrayNotHasKey('n2', $nodes->all());
    }

    public function test_one_node_has_two_days_of_hourly_activity_and_its_top_sources(): void
    {
        $this->actingAs($this->admin(), 'web');
        DB::table('crawl_fetch_attempts')->insert([
            $this->fetchAttempt('n1', 'direct', 'ok', 5), $this->fetchAttempt('n1', 'direct', 'error', 6, 'beta'), $this->fetchAttempt('n1', 'direct', 'ok', 7, 'beta'),
            $this->fetchAttempt('n1', 'direct', 'ok', 60 * 24 * 3), $this->fetchAttempt('n9', 'direct', 'ok', 5),
        ]);

        $data = $this->getJson('/api/v2/nodes/n1')->assertOk()->json('data');

        $this->assertSame('n1', $data['node']);
        $this->assertSame(3, array_sum(array_column($data['hourly'], 'attempts')));
        $this->assertSame(200, $data['hourly'][0]['avg_ms']);
        $this->assertMatchesRegularExpression('/:00:00\.000Z$/', $data['hourly'][0]['hour_at']);
        $this->assertSame([['source_slug' => 'beta', 'n' => 2], ['source_slug' => 'alpha', 'n' => 1]], $data['contribution_24h']);
        $this->assertSame([], $this->getJson('/api/v2/nodes/ghost')->assertOk()->json('data.hourly'));
    }

    public function test_node_enrollments_are_created_listed_with_their_state_and_revoked_only_while_unused(): void
    {
        $admin = $this->admin();
        $headers = $this->signIn($admin);

        $created = $this->postJson('/api/v2/node-enrollments', ['node_name' => 'worker-3'], $headers)->assertCreated();
        $id = $created->json('data.id');
        $this->assertSame("/api/v2/node-enrollments/{$id}", $created->headers->get('Location'));
        $created->assertJsonPath('data.node_name', 'worker-3')->assertJsonPath('data.role', 'worker')->assertJsonPath('data.state', 'created');
        $this->assertStringContainsString('--token jne_', $created->json('data.join_command'));
        $this->assertNotSame('', $created->json('data.note'));
        $this->assertStringContainsString('no-store', (string) $created->headers->get('Cache-Control'));
        $this->assertSame([['user:' . $admin->id, 'node_enrollment.create', "node-enrollments/{$id}", ['node_name' => 'worker-3', 'role' => 'worker']]], $this->audited);

        $past = ['node_name' => 'old-one', 'role' => 'worker', 'token_hash' => hash('sha256', 'a'), 'expires_at' => now()->subHour(), 'created_at' => now()->subHours(2), 'updated_at' => now()];
        $usedId = (int) DB::table('node_enrollments')->insertGetId(['node_name' => 'used-one', 'token_hash' => hash('sha256', 'b'), 'used_at' => now(), 'used_from_ip' => '10.0.0.5', 'expires_at' => now()->addHour(), 'role' => 'worker', 'created_at' => now()->subMinute(), 'updated_at' => now()]);
        DB::table('node_enrollments')->insert($past);

        $rows = collect($this->getJson('/api/v2/node-enrollments')->assertOk()->json('data'))->keyBy('node_name');
        $this->assertSame(['created', 'redeemed', 'expired'], [$rows['worker-3']['state'], $rows['used-one']['state'], $rows['old-one']['state']]);
        $this->assertSame('10.0.0.5', $rows['used-one']['used_from_ip']);
        $this->assertSame('production', $rows['worker-3']['origin']);
        $this->assertArrayNotHasKey('token_hash', $rows['worker-3']);

        $this->deleteJson("/api/v2/node-enrollments/{$id}", [], $headers)->assertNoContent();
        $this->deleteJson("/api/v2/node-enrollments/{$usedId}", [], $headers)->assertNoContent();
        $this->deleteJson('/api/v2/node-enrollments/9999', [], $headers)->assertNoContent();
        $this->assertFalse(DB::table('node_enrollments')->where('id', $id)->exists());
        $this->assertTrue(DB::table('node_enrollments')->where('id', $usedId)->exists());
    }

    public function test_a_node_enrollment_is_validated_and_the_main_role_needs_to_be_enabled(): void
    {
        $headers = $this->signIn($this->admin());

        $this->postJson('/api/v2/node-enrollments', [], $headers)->assertStatus(422)->assertJsonFragment(['field' => 'node_name', 'code' => 'required']);
        $this->postJson('/api/v2/node-enrollments', ['node_name' => 'Bad_Name'], $headers)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->postJson('/api/v2/node-enrollments', ['node_name' => 'ok-name', 'role' => 'boss'], $headers)->assertStatus(422);
        $this->postJson('/api/v2/node-enrollments', ['node_name' => 'ok-name', 'role' => 'main'], $headers)->assertStatus(422)->assertJsonFragment(['field' => 'role', 'code' => 'unsupported']);

        config()->set('javmeta_nodes.roles', ['worker', 'main']);
        $this->postJson('/api/v2/node-enrollments', ['node_name' => 'ok-name', 'role' => 'main'], $headers)->assertCreated()->assertJsonPath('data.role', 'main');
    }

    private function prometheus(): void
    {
        $vector = static fn(array $series): array => ['status' => 'success', 'data' => ['result' => $series]];
        $sample = static fn(array $metric, string $value): array => ['metric' => $metric, 'value' => [1, $value]];
        $instance = static fn(string $ip, string $name): array => ['instance' => "{$ip}:9100", 'vm_name' => $name];

        Http::fake([
            'prom.test/api/v1/query_range*' => Http::response(['status' => 'success', 'data' => ['result' => [['values' => [[1_700_000_000, '16.14'], [1_700_001_800, '15.96']]]]]]),
            'prom.test/api/v1/query*' => static function ($request) use ($vector, $sample, $instance) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
                $query = (string) $q['query'];

                return Http::response($vector(match (true) {
                    str_starts_with($query, 'up{job="node_exporter"}') => [$sample($instance('10.0.0.20', 'xcrawler'), '1'), $sample($instance('10.0.0.3', 'monitor'), '0'), $sample(['instance' => 'plain:9100'], '1')],
                    str_starts_with($query, '100 - avg by') => [$sample(['instance' => '10.0.0.20:9100'], '4.5')],
                    str_starts_with($query, '(1 - node_memory') => [$sample(['instance' => '10.0.0.20:9100'], '40')],
                    str_starts_with($query, '(1 - node_filesystem') => [$sample(['instance' => '10.0.0.20:9100'], '22')],
                    $query === 'node_load1' => [$sample(['instance' => '10.0.0.20:9100'], '0.36')],
                    str_starts_with($query, 'time() - node_boot') => [$sample(['instance' => '10.0.0.20:9100'], (string) (86400 * 52.1))],
                    $query === 'node_memory_SwapTotal_bytes' => [$sample(['instance' => '10.0.0.20:9100'], '1000')],
                    $query === 'node_memory_SwapFree_bytes' => [$sample(['instance' => '10.0.0.20:9100'], '750')],
                    str_starts_with($query, 'node_systemd_unit_state') => [
                        $sample(['vm_name' => 'xcrawler', 'name' => 'xcrawler-horizon.service', 'type' => 'simple'], '1'),
                        $sample(['vm_name' => 'xcrawler', 'name' => 'xcrawler-embedder.service'], '0'),
                        $sample(['vm_name' => 'xcrawler', 'name' => 'xcrawler-clean.timer', 'type' => 'oneshot'], '1'),
                    ],
                    $query === 'sum(pg_stat_activity_count)' => [$sample([], '92')],
                    str_contains($query, 'datname="xcrawler"') && str_starts_with($query, 'sum(') => [$sample([], '56')],
                    str_starts_with($query, 'max(pg_settings') => [$sample([], '300')],
                    str_starts_with($query, 'pg_database_size') => [$sample([], '7102174911')],
                    $query === 'redis_connected_clients' => [$sample([], '325')],
                    $query === 'elasticsearch_cluster_health_status' => [$sample(['color' => 'red'], '0'), $sample(['color' => 'green'], '1')],
                    $query === 'mongodb_ss_connections' => [$sample(['conn_type' => 'available'], '1'), $sample(['conn_type' => 'current'], '235')],
                    $query === 'up{job="mongodb_exporter"}' => [$sample([], '1')],
                    default => [],
                }));
            },
            'prom.test/-/healthy' => Http::response('ok'),
            'sentry.test/*' => Http::response('nope', 503),
        ]);
    }

    public function test_the_infrastructure_page_is_built_from_prometheus_and_the_configured_probes(): void
    {
        $this->actingAs($this->admin(), 'web');
        Cache::flush();
        config()->set('javmeta_ops.prometheus_url', 'http://prom.test/');
        config()->set('javmeta_ops.probes', ['sentry' => ['http' => 'http://sentry.test/_health/'], 'closed' => ['tcp' => '127.0.0.1:1'], 'blank' => []]);
        $this->prometheus();

        $response = $this->getJson('/api/v2/infrastructure')->assertOk();
        $data = $response->json('data');

        $this->assertSame([], $response->json('meta'));
        $this->assertSame(['10.0.0.3:9100', '10.0.0.20:9100', 'plain:9100'], array_column($data['vms'], 'instance'));
        $vms = collect($data['vms'])->keyBy('instance');
        $this->assertEquals(['instance' => '10.0.0.20:9100', 'ip' => '10.0.0.20', 'name' => 'xcrawler', 'role' => 'Crawler main node', 'group' => 'app', 'up' => true, 'cpu' => 4.5, 'mem' => 40.0, 'disk' => 22.0, 'load1' => 0.36, 'uptimeDays' => 52.1, 'swap' => 25.0], $vms['10.0.0.20:9100']);
        $this->assertFalse($vms['10.0.0.3:9100']['up']);
        $this->assertNull($vms['10.0.0.3:9100']['cpu']);
        $this->assertNull($vms['10.0.0.3:9100']['swap']);
        $this->assertSame(['role' => 'plain:9100', 'group' => 'ops'], ['role' => $vms['plain:9100']['role'], 'group' => $vms['plain:9100']['group']]);
        $this->assertSame([['unit' => 'xcrawler-horizon', 'type' => 'simple', 'active' => true], ['unit' => 'xcrawler-embedder', 'type' => 'simple', 'active' => false]], $data['services']['xcrawler']);
        $this->assertCount(2, $data['series']['xcrawler']);
        $this->assertEquals(16.1, $data['series']['xcrawler'][0]['v']);
        $this->assertEquals(['conn' => 92.0, 'connXcrawler' => 56.0, 'max' => 300.0], array_intersect_key($data['deps']['postgres'], array_flip(['conn', 'connXcrawler', 'max'])));
        $this->assertEquals(['clients' => 325.0, 'memBytes' => null, 'opsPerSec' => null], $data['deps']['redis']);
        $this->assertSame('green', $data['deps']['elasticsearch']['color']);
        $this->assertEquals(['connections' => 235.0, 'up' => true], $data['deps']['mongo']);
        $this->assertTrue($data['probes']['prometheus']['ok']);
        $this->assertSame(['ok' => false, 'status' => 503], array_intersect_key($data['probes']['sentry'], array_flip(['ok', 'status'])));
        $this->assertSame(['ok' => false, 'ms' => null], $data['probes']['closed']);
        $this->assertFalse($data['probes']['blank']['ok']);
    }

    public function test_the_infrastructure_answer_is_cached_for_a_short_time(): void
    {
        $this->actingAs($this->admin(), 'web');
        Cache::flush();
        config()->set('javmeta_ops.prometheus_url', 'http://prom.test');
        $this->prometheus();

        $this->getJson('/api/v2/infrastructure')->assertOk();
        $calls = count(Http::recorded());
        $this->getJson('/api/v2/infrastructure')->assertOk();

        $this->assertSame($calls, count(Http::recorded()));
    }

    public function test_the_infrastructure_page_degrades_to_empty_lists_when_prometheus_is_missing_or_down(): void
    {
        $this->actingAs($this->admin(), 'web');
        Cache::flush();

        config()->set('javmeta_ops.prometheus_url', null);
        $this->getJson('/api/v2/infrastructure')->assertOk()->assertJsonPath('meta', ['degraded' => true, 'reason' => 'prometheus_not_configured'])->assertJsonPath('data.vms', [])->assertJsonPath('data.probes', []);

        config()->set('javmeta_ops.prometheus_url', 'http://prom.test');
        Http::fake(['prom.test/*' => Http::response('down', 500)]);
        $this->getJson('/api/v2/infrastructure')->assertOk()->assertJsonPath('meta', ['degraded' => true, 'reason' => 'prometheus_unreachable'])->assertJsonPath('data.vms', []);
        Http::assertSentCount(1);

        Cache::flush();
        Http::fake(static fn() => throw new \RuntimeException('connection refused'));
        $this->getJson('/api/v2/infrastructure')->assertOk()->assertJsonPath('meta.reason', 'prometheus_unreachable');

        Cache::flush();
        Http::fake(['prom.test/*' => Http::response(['status' => 'success'])]);
        $this->getJson('/api/v2/infrastructure')->assertOk()->assertJsonPath('meta.reason', 'prometheus_unreachable');
    }

    public function test_alerts_are_paged_newest_first_and_can_be_limited_to_the_open_ones(): void
    {
        $this->actingAs($this->admin(), 'web');
        DB::table('alert_events')->insert(['rule' => 'old', 'severity' => 'warning', 'value' => 1, 'threshold' => 2, 'started_at' => now()->subHours(3), 'resolved_at' => now()->subHours(2)]);
        DB::table('alert_events')->insert(['rule' => 'open', 'severity' => 'critical', 'value' => 99.126, 'threshold' => 90, 'started_at' => now()->subHour(), 'notified_at' => now()]);

        $all = $this->getJson('/api/v2/alerts')->assertOk();
        $this->assertSame(['open', 'old'], array_column($all->json('data'), 'rule'));
        $this->assertSame('99.13', $all->json('data.0.value'));
        $this->assertNotNull($all->json('data.0.notified_at'));
        $this->assertNull($all->json('data.1.notified_at'));
        $all->assertJsonPath('meta.pagination.total', 2);

        $active = $this->getJson('/api/v2/alerts?state=active&per_page=1')->assertOk();
        $this->assertSame(['open'], array_column($active->json('data'), 'rule'));
        $active->assertJsonPath('meta.pagination.total', 1);
    }

    private function reviewFlag(string $source, ?string $resolvedAt = null, int $hoursAgo = 1): int
    {
        return (int) DB::table('review_flags')->insertGetId(['code_normalized' => "url:{$source}", 'source_slug' => $source, 'consecutive_failures' => 2, 'reason' => 'consecutive_crawl_failure', 'flagged_at' => now()->subHours($hoursAgo), 'resolved_at' => $resolvedAt]);
    }

    public function test_review_flags_list_open_ones_by_default_and_resolved_ones_on_request(): void
    {
        $this->actingAs($this->admin(), 'web');
        $this->reviewFlag('alpha', null, 2);
        $this->reviewFlag('beta', null, 1);
        $this->reviewFlag('gamma', (string) now(), 3);

        $open = $this->getJson('/api/v2/review-flags')->assertOk();
        $this->assertSame(['beta', 'alpha'], array_column($open->json('data'), 'source_slug'));
        $open->assertJsonPath('meta.pagination.total', 2);
        $this->assertSame(['id', 'code_normalized', 'source_slug', 'consecutive_failures', 'reason', 'flagged_at', 'resolved_at'], array_keys($open->json('data.0')));

        $resolved = $this->getJson('/api/v2/review-flags?state=resolved')->assertOk();
        $this->assertSame(['gamma'], array_column($resolved->json('data'), 'source_slug'));
        $this->assertNotNull($resolved->json('data.0.resolved_at'));
    }

    public function test_a_review_flag_is_resolved_once_with_an_audit_row(): void
    {
        $admin = $this->admin();
        $headers = $this->signIn($admin);
        $id = $this->reviewFlag('alpha');

        $this->patchJson("/api/v2/review-flags/{$id}", ['state' => 'resolved', 'reason' => 'fixed by hand'], $headers)->assertOk()->assertExactJson(['data' => ['id' => $id, 'state' => 'resolved'], 'meta' => []]);
        $this->assertNotNull(DB::table('review_flags')->where('id', $id)->value('resolved_at'));
        $this->assertSame([['user:' . $admin->id, 'review_flag.resolve', "review-flags/{$id}", ['state' => 'resolved', 'reason' => 'fixed by hand']]], $this->audited);

        $first = DB::table('review_flags')->where('id', $id)->value('resolved_at');
        $this->patchJson("/api/v2/review-flags/{$id}", ['state' => 'resolved'], $headers)->assertOk();
        $this->assertSame($first, DB::table('review_flags')->where('id', $id)->value('resolved_at'));
        $this->assertNull($this->audited[1][3]['reason']);
    }

    public function test_resolving_a_review_flag_validates_the_body_and_needs_an_existing_flag(): void
    {
        $headers = $this->signIn($this->admin());
        $id = $this->reviewFlag('alpha');

        $this->patchJson("/api/v2/review-flags/{$id}", [], $headers)->assertStatus(422)->assertJsonFragment(['field' => 'state', 'code' => 'required']);
        $this->patchJson("/api/v2/review-flags/{$id}", ['state' => 'open'], $headers)->assertStatus(422);
        $this->patchJson("/api/v2/review-flags/{$id}", ['state' => 'resolved', 'reason' => str_repeat('x', 501)], $headers)->assertStatus(422);
        $this->patchJson('/api/v2/review-flags/999', ['state' => 'resolved'], $headers)->assertNotFound()->assertJsonPath('code', 'flag_not_found');
        $this->assertNull(DB::table('review_flags')->where('id', $id)->value('resolved_at'));
    }
}
