<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Http\Middleware\StartupHealthMiddleware;
use App\Services\Dependencies\Probes\MongoProbe;
use App\Services\Health\StartupHealthService;
use App\Services\Health\StartupHealthSnapshotStore;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RuntimeDependencyHealthTest extends IntegrationTestCase
{
    public function test_mongo_outage_is_reported_and_blocks_web_middleware(): void
    {
        if (getenv('JAVMETA_INTEGRATION_STOP_MONGO') !== '1') {
            $this->markTestSkipped('The integration runner must stop Mongo during this test.');
        }

        $signalId = getenv('JAVMETA_MONGO_OUTAGE_SIGNAL_ID');
        self::assertIsString($signalId);
        self::assertNotSame('', $signalId);
        self::assertTrue(app(MongoProbe::class)->probe(), 'Mongo must be reachable before the outage.');

        $signalDirectory = '/tmp/javmeta-integration-signal';
        $readySignal = "{$signalDirectory}/mongo-outage-{$signalId}.ready";
        $stoppedSignal = "{$signalDirectory}/mongo-outage-{$signalId}.stopped";
        $failureSignal = "{$signalDirectory}/mongo-outage-{$signalId}.failed";
        self::assertDirectoryExists($signalDirectory);
        self::assertSame(strlen('ready'), file_put_contents($readySignal, 'ready'));

        $deadline = microtime(true) + 30;
        while (! is_file($stoppedSignal) && microtime(true) < $deadline) {
            usleep(10_000);
        }

        self::assertFileDoesNotExist($failureSignal, 'The integration runner could not stop Mongo.');
        self::assertFileExists($stoppedSignal, 'The integration runner did not stop Mongo in time.');

        $this->getJson('/api/health')
            ->assertServiceUnavailable()
            ->assertJsonPath('status', 'down')
            ->assertJsonPath('dependencies.mongo.available', false)
            ->assertJsonPath('dependencies.mongo.hard', true)
            ->assertJsonPath('dependencies.mongo.state', 'down');

        $healthPath = tempnam(sys_get_temp_dir(), 'startup-health-');
        self::assertNotFalse($healthPath);
        unlink($healthPath);
        $snapshots = new StartupHealthSnapshotStore($healthPath);
        try {
            $report = app(StartupHealthService::class)->run('web', 0, false);
            self::assertTrue($report['blocking']);
            $snapshots->put($report, (int) config('javmeta_health.startup.snapshot_ttl_seconds', 5));

            $passed = false;
            $middleware = new StartupHealthMiddleware(
                app(StartupHealthService::class),
                $snapshots,
            );
            $response = $middleware->handle(
                Request::create('/api/v1/performers', 'GET', server: ['HTTP_ACCEPT' => 'application/json']),
                static function () use (&$passed): Response {
                    $passed = true;

                    return new Response('allowed');
                },
            );

            self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
            self::assertSame((string) config('javmeta_health.startup.snapshot_ttl_seconds', 5), $response->headers->get('Retry-After'));
            self::assertFalse($passed);
            $body = (array) json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('unavailable', $body['status'] ?? null);
            self::assertSame('mongo', $body['checks'][0]['service'] ?? null);
        } finally {
            @unlink($healthPath);
        }
    }
}
