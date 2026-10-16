<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use App\Jobs\FetchDetailJob;
use App\Models\CrawlQueue;
use App\Models\Source;
use App\Observability\Sentry\SentryScrubber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Queue\TimeoutExceededException;
use Mockery;
use RuntimeException;
use Sentry\ClientBuilder;
use Sentry\ClientInterface;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Tests\Support\RecordingSentryTransport;
use Tests\TestCase;

final class SentryReportingTest extends TestCase
{
    use RefreshDatabase;

    private HubInterface $previousHub;

    private Hub $testHub;

    private RecordingSentryTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousHub = SentrySdk::getCurrentHub();
        $this->transport = new RecordingSentryTransport();
        config()->set([
            'sentry.dsn' => 'https://public@example.test/1',
            'sentry.role' => 'api',
            'sentry.node' => 'test-node',
            'sentry.before_send' => [SentryScrubber::class, 'beforeSend'],
            'sentry.before_breadcrumb' => [SentryScrubber::class, 'beforeBreadcrumb'],
            'logging.default' => 'single',
            'openobserve.enabled' => false,
        ]);

        $client = ClientBuilder::create([
            'dsn' => 'https://public@example.test/1',
            'before_send' => [SentryScrubber::class, 'beforeSend'],
            'before_breadcrumb' => [SentryScrubber::class, 'beforeBreadcrumb'],
        ])->setTransport($this->transport)->getClient();

        $this->testHub = new Hub($client);
        SentrySdk::setCurrentHub($this->testHub);
        $this->app->instance(HubInterface::class, $this->testHub);
    }

    protected function tearDown(): void
    {
        SentrySdk::setCurrentHub($this->previousHub);

        parent::tearDown();
    }

    public function test_api_exception_is_sent_once_with_the_api_role_tag(): void
    {
        config()->set('sentry.role', 'api');
        Route::get('/__sentry-api-failure', static function (): never {
            throw new RuntimeException('API operation failed');
        });

        $this->get('/__sentry-api-failure')->assertInternalServerError();

        self::assertCount(1, $this->transport->events);
        self::assertSame('api', $this->transport->events[0]->getTags()['role'] ?? null);
    }

    public function test_failed_crawl_job_is_sent_once_with_source_error_code_and_fingerprint(): void
    {
        config()->set('sentry.role', 'worker');
        Source::factory()->create(['slug' => 'sample-source']);
        $row = CrawlQueue::factory()->create([
            'source_slug' => 'sample-source',
            'kind' => CrawlQueue::KIND_DETAIL,
            'status' => CrawlQueue::STATUS_CLAIMED,
            'attempts' => 1,
            'max_attempts' => 3,
        ]);

        (new FetchDetailJob($row->id))->failed(new RuntimeException('Queue job failed'));
        logger()->error('Queue job failure was recorded.');

        self::assertCount(1, $this->transport->events);
        $event = $this->transport->events[0];
        self::assertSame('worker', $event->getTags()['role'] ?? null);
        self::assertSame('sample-source', $event->getTags()['source'] ?? null);
        self::assertSame('worker_failed', $event->getTags()['error_code'] ?? null);
        self::assertSame(['worker_failed', 'sample-source'], $event->getFingerprint());
    }

    public function test_timeout_failure_uses_timeout_error_code(): void
    {
        Source::factory()->create(['slug' => 'timeout-source']);
        $row = CrawlQueue::factory()->create([
            'source_slug' => 'timeout-source',
            'status' => CrawlQueue::STATUS_CLAIMED,
            'attempts' => 1,
            'max_attempts' => 3,
        ]);

        (new FetchDetailJob($row->id))->failed(new TimeoutExceededException('Worker timed out'));

        self::assertCount(1, $this->transport->events);
        self::assertSame('timeout', $this->transport->events[0]->getTags()['error_code'] ?? null);
    }

    public function test_non_claimed_crawl_row_does_not_report_an_event(): void
    {
        Source::factory()->create(['slug' => 'pending-source']);
        $row = CrawlQueue::factory()->create([
            'source_slug' => 'pending-source',
            'status' => CrawlQueue::STATUS_PENDING,
        ]);

        (new FetchDetailJob($row->id))->failed(new RuntimeException('stale queue job'));

        self::assertCount(0, $this->transport->events);
    }

    public function test_scheduler_command_exception_is_sent_with_scheduler_role(): void
    {
        config()->set('sentry.role', 'scheduler');
        Artisan::command('javmeta:test-scheduler-failure', function (): never {
            throw new RuntimeException('Scheduled operation failed');
        });

        $this->artisan('javmeta:test-scheduler-failure')->assertFailed();

        self::assertCount(1, $this->transport->events);
        self::assertSame('scheduler', $this->transport->events[0]->getTags()['role'] ?? null);
    }

    public function test_empty_dsn_leaves_client_unbound_and_reporting_sends_no_http_event(): void
    {
        config()->set('sentry.dsn', '');
        $hub = new Hub();
        SentrySdk::setCurrentHub($hub);
        $this->app->instance(HubInterface::class, $hub);

        self::assertNull($hub->getClient());
        report(new RuntimeException('Sentry is disabled.'));
        $this->artisan('javmeta:sentry-test')
            ->expectsOutputToContain('Sentry is disabled; no event was sent.')
            ->assertFailed();

        self::assertCount(0, $this->transport->events);
    }

    public function test_sentry_test_command_fails_without_an_event_id(): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $hub = Mockery::mock(HubInterface::class);
        $hub->shouldReceive('getClient')->once()->andReturn($client);
        $hub->shouldReceive('captureMessage')->once()->with('Javmeta Sentry integration test event')->andReturn(null);
        $this->app->instance(HubInterface::class, $hub);

        $this->artisan('javmeta:sentry-test')
            ->expectsOutputToContain('Sentry did not return an event ID.')
            ->assertFailed();
    }

    public function test_sentry_test_command_prints_event_id_without_the_dsn(): void
    {
        $this->artisan('javmeta:sentry-test')
            ->expectsOutputToContain('Sentry test event ID:')
            ->assertSuccessful();

        self::assertCount(1, $this->transport->events);
        self::assertStringNotContainsString('https://public@example.test/1', Artisan::output());
    }
}
