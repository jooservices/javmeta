<?php

declare(strict_types=1);

namespace Tests\Feature\Archive;

use App\Services\Archive\MongoSiteArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use Sentry\ClientBuilder;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Tests\Support\DependencyMonitorTestHelper;
use Tests\Support\RecordingSentryTransport;
use Tests\TestCase;

final class MongoSiteArchiveFailureTest extends TestCase
{
    use RefreshDatabase;

    private HubInterface $previousHub;

    private Hub $testHub;

    private RecordingSentryTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::store('array')->flush();
        $this->previousHub = SentrySdk::getCurrentHub();
        $this->transport = new RecordingSentryTransport();
        config()->set([
            'sentry.dsn' => 'https://public@example.test/1',
            'sentry.role' => 'worker',
            'sentry.node' => 'test-node',
            'sentry.before_send' => null,
            'openobserve.enabled' => false,
        ]);

        $client = ClientBuilder::create(['dsn' => 'https://public@example.test/1'])
            ->setTransport($this->transport)
            ->getClient();
        $this->testHub = new Hub($client);
        SentrySdk::setCurrentHub($this->testHub);
        $this->app->instance(HubInterface::class, $this->testHub);
        DependencyMonitorTestHelper::bind();
    }

    protected function tearDown(): void
    {
        SentrySdk::setCurrentHub($this->previousHub);

        parent::tearDown();
    }

    public function test_unreachable_archive_write_is_logged_sent_to_sentry_and_reported_by_health(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($server, "Could not start MongoDB handshake test server: {$errorMessage} ({$errorCode})");

        $address = stream_socket_get_name($server, false);
        self::assertIsString($address);
        config()->set('mongodb.uri', "mongodb://{$address}");

        $logger = Log::spy();
        $started = hrtime(true);

        try {
            $archiveId = app(MongoSiteArchive::class)->upsertMovie(
                'javdb',
                'https://javdb.test/movies/1',
                ['code' => 'SSIS-001'],
                'SSIS-001',
            );
        } finally {
            fclose($server);
        }

        $durationMs = (int) round((hrtime(true) - $started) / 1_000_000);

        self::assertNull($archiveId);
        self::assertLessThan(5000, $durationMs, 'MongoDB server selection exceeded the configured 1.5 second timeout.');
        $logger->shouldHaveReceived('error')
            ->once()
            ->with('Mongo archive write failed.', Mockery::on(
                static fn(array $context): bool
                => ($context['error_code'] ?? null) === 'mongo_archive_write_failed'
                && ($context['entity_type'] ?? null) === 'movie'
                && ($context['site_slug'] ?? null) === 'javdb'
                && ! array_key_exists('source_url', $context),
            ));
        self::assertCount(1, $this->transport->events);
        self::assertSame('mongo_archive_write_failed', $this->transport->events[0]->getTags()['error_code'] ?? null);
        self::assertSame('javdb', $this->transport->events[0]->getTags()['source'] ?? null);

        app(\App\Services\Crawl\WorkerHeartbeat::class)->beat();
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('archive.status', 'degraded')
            ->assertJsonPath('archive.failed_writes', 1)
            ->assertJsonPath('dependencies.mongo.available', true);

        self::assertIsString($this->getJson('/api/health')->json('archive.last_failure_at'));
    }
}
