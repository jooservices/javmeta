<?php

declare(strict_types=1);

namespace Tests\Feature\Health;

use App\Models\ApiKey;
use App\Services\Auth\ApiKeyService;
use App\Services\Dependencies\Dependency;
use App\Services\Health\SchedulerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Sentry\ClientBuilder;
use Sentry\ClientInterface;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Tests\Support\DependencyMonitorTestHelper;
use Tests\Support\RecordingSentryTransport;
use Tests\TestCase;

final class SmokeCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $apiKeys = [];

    private bool $failApiRequests = false;

    protected function setUp(): void
    {
        parent::setUp();

        DependencyMonitorTestHelper::bind();
        config()->set([
            'app.url' => 'http://javmeta.test',
            'elasticsearch.enabled' => true,
            'elasticsearch.embedder_enabled' => true,
            'openobserve.enabled' => false,
            'sentry.dsn' => '',
        ]);
        $this->setRunningHorizon();
        $this->setFreshSchedulerHeartbeat();
        $this->fakeApiRequests();
    }

    public function test_smoke_command_reports_named_checks_and_revokes_its_temporary_key(): void
    {
        $exitCode = Artisan::call('javmeta:smoke');
        $output = Artisan::output();

        self::assertSame(0, $exitCode, $output);
        self::assertCount(2, $this->apiKeys);
        self::assertStringContainsString('[PASS] database:', $output);
        self::assertStringContainsString('[PASS] redis:', $output);
        self::assertStringContainsString('[PASS] mongodb:', $output);
        self::assertStringContainsString('[PASS] elasticsearch:', $output);
        self::assertStringContainsString('[PASS] embedder:', $output);
        self::assertStringContainsString('[PASS] horizon:', $output);
        self::assertStringContainsString('[PASS] scheduler:', $output);
        self::assertStringContainsString('[PASS] queue_depth:', $output);
        self::assertStringContainsString('[PASS] api_lookup:', $output);
        self::assertStringContainsString('[PASS] mcp_initialize:', $output);
        self::assertStringContainsString('[SKIPPED] sentry:', $output);
        self::assertStringContainsString('[SKIPPED] openobserve:', $output);

        $storedKey = ApiKey::query()->sole();
        self::assertSame(ApiKey::STATUS_REVOKED, $storedKey->status);
        self::assertCount(2, $this->apiKeys);
        self::assertNotSame('', $this->apiKeys[0]);
        self::assertSame($this->apiKeys[0], $this->apiKeys[1]);
        self::assertSame([], array_filter($this->apiKeys, static fn(string $key): bool => str_contains($output, $key)));
        self::assertNull(app(ApiKeyService::class)->findActiveByPlaintext($this->apiKeys[0]));
    }

    public function test_json_output_has_machine_readable_named_check_results(): void
    {
        $exitCode = Artisan::call('javmeta:smoke', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $exitCode, Artisan::output());
        self::assertSame('pass', $payload['status']);
        self::assertSame(
            ['name', 'status', 'message'],
            array_keys($payload['checks'][0]),
        );
        self::assertContains('skipped', array_column($payload['checks'], 'status'));
    }

    public function test_scheduler_can_record_a_fresh_heartbeat(): void
    {
        app(SchedulerHeartbeat::class)->beat();

        self::assertTrue(app(SchedulerHeartbeat::class)->isFresh());
    }

    #[DataProvider('dependencyFailures')]
    public function test_each_dependency_failure_returns_exit_code_one(string $dependency): void
    {
        DependencyMonitorTestHelper::bind([$dependency => false]);
        if ($dependency === Dependency::Observability->value) {
            config()->set('openobserve.enabled', true);
        }

        $exitCode = Artisan::call('javmeta:smoke', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $failed = array_values(array_filter(
            $payload['checks'],
            static fn(array $check): bool => $check['status'] === 'fail',
        ));

        self::assertSame(1, $exitCode);
        self::assertSame('fail', $payload['status']);
        self::assertNotEmpty($failed);
    }

    public static function dependencyFailures(): array
    {
        return [
            'database' => [Dependency::Postgres->value],
            'redis' => [Dependency::Redis->value],
            'mongodb' => [Dependency::Mongo->value],
            'elasticsearch' => [Dependency::Elasticsearch->value],
            'embedder' => [Dependency::Embedder->value],
            'openobserve' => [Dependency::Observability->value],
        ];
    }

    public function test_disabled_services_are_reported_as_skipped(): void
    {
        config()->set([
            'elasticsearch.enabled' => false,
            'elasticsearch.embedder_enabled' => false,
            'openobserve.enabled' => false,
            'sentry.dsn' => '',
        ]);

        $exitCode = Artisan::call('javmeta:smoke');
        $output = Artisan::output();

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('[SKIPPED] elasticsearch:', $output);
        self::assertStringContainsString('[SKIPPED] embedder:', $output);
        self::assertStringContainsString('[SKIPPED] sentry:', $output);
        self::assertStringContainsString('[SKIPPED] openobserve:', $output);
    }

    public function test_openobserve_probe_is_checked_when_enabled(): void
    {
        config()->set('openobserve.enabled', true);
        DependencyMonitorTestHelper::bind();

        $exitCode = Artisan::call('javmeta:smoke');
        $output = Artisan::output();

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('[PASS] openobserve:', $output);
    }

    public function test_missing_or_stale_scheduler_heartbeat_fails(): void
    {
        Cache::forget(SchedulerHeartbeat::CACHE_KEY);

        self::assertSame(1, Artisan::call('javmeta:smoke'));
        self::assertStringContainsString('[FAIL] scheduler: No scheduler heartbeat has been recorded.', Artisan::output());

        Cache::put(SchedulerHeartbeat::CACHE_KEY, now()->subSeconds(SchedulerHeartbeat::STALE_AFTER_SECONDS + 1)->getTimestamp());

        self::assertSame(1, Artisan::call('javmeta:smoke'));
        self::assertStringContainsString('[FAIL] scheduler: The scheduler heartbeat is stale.', Artisan::output());
    }

    public function test_horizon_and_queue_limit_failures_are_reported(): void
    {
        $repository = Mockery::mock(MasterSupervisorRepository::class);
        $repository->shouldReceive('all')->andReturn([]);
        $this->app->instance(MasterSupervisorRepository::class, $repository);

        config()->set([
            'javmeta_queues.depth_limit' => 2,
            'queue.default' => 'database',
        ]);
        DB::table('jobs')->insert([
            ['queue' => 'detail', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp()],
            ['queue' => 'detail', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp()],
        ]);

        self::assertSame(1, Artisan::call('javmeta:smoke'));
        $output = Artisan::output();

        self::assertStringContainsString('[FAIL] horizon:', $output);
        self::assertStringContainsString('[WARN] queue_depth:', $output);
    }

    public function test_browser_queue_readiness_uses_its_own_depth_limit(): void
    {
        config()->set([
            'javmeta_queues.depth_limit' => 100,
            'javmeta_queues.browser_depth_limit' => 1,
            'queue.default' => 'database',
        ]);
        DB::table('jobs')->insert([
            'queue' => 'browser_detail', 'payload' => '{}', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp(),
        ]);

        Artisan::call('javmeta:smoke');
        self::assertStringContainsString('[WARN] queue_depth: Queue depth reached its configured limit for: browser_detail.', Artisan::output());
    }

    public function test_api_and_mcp_failures_still_revoke_the_temporary_key(): void
    {
        $this->failApiRequests = true;

        self::assertSame(1, Artisan::call('javmeta:smoke'));
        $output = Artisan::output();

        self::assertStringContainsString('[FAIL] api_lookup:', $output);
        self::assertStringContainsString('[FAIL] mcp_initialize:', $output);
        self::assertStringContainsString('[PASS] temporary_api_key:', $output);
        self::assertSame(ApiKey::STATUS_REVOKED, ApiKey::query()->sole()->status);
    }

    public function test_sentry_delivery_is_checked_when_enabled(): void
    {
        config()->set('sentry.dsn', 'https://public@example.test/1');
        $transport = new RecordingSentryTransport();
        $client = ClientBuilder::create(['dsn' => 'https://public@example.test/1'])
            ->setTransport($transport)
            ->getClient();
        $this->app->instance(HubInterface::class, new Hub($client));

        $exitCode = Artisan::call('javmeta:smoke');
        $output = Artisan::output();

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('[PASS] sentry: Sentry accepted and delivered the smoke-check event.', $output);
        self::assertCount(1, $transport->events);
    }

    public function test_sentry_failure_is_reported_when_enabled(): void
    {
        config()->set('sentry.dsn', 'https://public@example.test/1');
        $client = Mockery::mock(ClientInterface::class);
        $hub = Mockery::mock(HubInterface::class);
        $hub->shouldReceive('getClient')->once()->andReturn($client);
        $hub->shouldReceive('captureMessage')
            ->once()
            ->with('JavMeta deployment smoke check', Mockery::type(\Sentry\Severity::class))
            ->andReturn(null);
        $this->app->instance(HubInterface::class, $hub);

        self::assertSame(1, Artisan::call('javmeta:smoke'));
        self::assertStringContainsString('[FAIL] sentry:', Artisan::output());
    }

    private function setRunningHorizon(): void
    {
        $repository = Mockery::mock(MasterSupervisorRepository::class);
        $repository->shouldReceive('all')->andReturn([(object) ['status' => 'running']]);
        $this->app->instance(MasterSupervisorRepository::class, $repository);
    }

    private function setFreshSchedulerHeartbeat(): void
    {
        Cache::put(SchedulerHeartbeat::CACHE_KEY, now()->subSeconds(20)->getTimestamp());
    }

    private function fakeApiRequests(): void
    {
        $apiKeys = &$this->apiKeys;
        $failApiRequests = &$this->failApiRequests;
        Http::fake(function (Request $request) use (&$apiKeys, &$failApiRequests): \GuzzleHttp\Promise\PromiseInterface {
            $apiKeys[] = (string) ($request->header('X-API-Key')[0] ?? '');
            if ($failApiRequests) {
                return Http::response(['message' => 'unavailable'], 503);
            }

            if (str_contains($request->url(), '/api/v1/performers')) {
                return Http::response(['data' => [], 'meta' => ['pagination' => ['total' => 0]]]);
            }

            if (str_ends_with($request->url(), '/api/v1/mcp')) {
                return Http::response([
                    'result' => [
                        'protocolVersion' => '2024-11-05',
                        'capabilities' => [],
                        'serverInfo' => ['name' => 'javmeta'],
                    ],
                ]);
            }

            return Http::response(['message' => 'unexpected request'], 500);
        });
    }
}
