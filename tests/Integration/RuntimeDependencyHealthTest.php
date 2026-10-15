<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Performer;
use App\Services\Auth\ApiKeyService;
use App\Services\Dependencies\Probes\MongoProbe;

final class RuntimeDependencyHealthTest extends IntegrationTestCase
{
    public function test_mongo_outage_is_soft_for_health_and_postgres_backed_api_routes(): void
    {
        if (getenv('JAVMETA_INTEGRATION_STOP_MONGO') !== '1') {
            $this->markTestSkipped('The integration runner must stop Mongo during this test.');
        }

        $performer = Performer::factory()->create();
        $apiKey = app(ApiKeyService::class)->create(fake()->words(2, true))->plaintext;
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
            ->assertOk()
            ->assertJsonPath('dependencies.mongo.available', false)
            ->assertJsonPath('dependencies.mongo.hard', false)
            ->assertJsonPath('dependencies.mongo.state', 'down');

        $this->getJson('/api/v1/performers', ['X-API-Key' => $apiKey])
            ->assertOk()
            ->assertJsonPath('data.0.id', $performer->id);
    }
}
