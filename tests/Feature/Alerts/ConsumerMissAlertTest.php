<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Contracts\AlertNotifier;
use App\Events\ConsumerMissedLookup;
use App\Events\WorkerHeartbeatStale;
use App\Logging\ArrayLogStore;
use App\Notifications\ActivityLogAlertNotifier;
use App\Notifications\CompositeAlertNotifier;
use App\Notifications\TelegramAlertNotifier;
use App\Services\Auth\ApiKeyService;
use App\Services\Crawl\WorkerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use JOOservices\Client\Client\ClientBuilder;
use JOOservices\Client\Testing\TestResponse;
use JOOservices\Client\Testing\TestResponseSequence;
use JOOservices\LaravelLogging\Contracts\LogStoreInterface;
use Tests\TestCase;

final class ConsumerMissAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ClientBuilder::clearFake();
        parent::tearDown();
    }

    public function test_movie_lookup_404_dispatches_miss_and_writes_crawl_event(): void
    {
        Event::fake([ConsumerMissedLookup::class]);
        $key = app(ApiKeyService::class)->create(fake()->words(2, true))->plaintext;

        $this->getJson('/api/v1/movies/XYZ-999', [
            'X-API-Key' => $key,
        ])->assertNotFound();

        Event::assertDispatched(ConsumerMissedLookup::class, static function (ConsumerMissedLookup $event): bool {
            return $event->entity === 'movie' && $event->query === 'XYZ-999';
        });
    }

    public function test_miss_listener_notifies_telegram_and_logs_event(): void
    {
        ClientBuilder::fake()->respond(
            'POST',
            '*',
            (new TestResponseSequence())->push(TestResponse::json(['ok' => true])),
        );

        $client = ClientBuilder::create()
            ->withBaseUri('https://api.telegram.org/')
            ->build();

        config([
            'javmeta_alerts.enabled' => true,
            'javmeta_alerts.telegram.bot_token' => 'test-token',
            'javmeta_alerts.telegram.chat_id' => '12345',
            'javmeta_alerts.telegram.api_base' => 'https://api.telegram.org',
            'javmeta_alerts.miss_debounce_seconds' => 0,
            'laravel-notifications.channels.telegram.bot_token' => 'test-token',
            'laravel-notifications.channels.telegram.chat_id' => '12345',
            'laravel-notifications.channels.telegram.api_base' => 'https://api.telegram.org',
        ]);

        /** @var ArrayLogStore $store */
        $store = $this->app->make(LogStoreInterface::class);
        self::assertInstanceOf(ArrayLogStore::class, $store);
        $store->flush();

        $this->app->forgetInstance(AlertNotifier::class);
        $this->app->forgetInstance(\App\Services\Alerts\AlertDispatcher::class);
        $this->app->instance(AlertNotifier::class, new CompositeAlertNotifier([
            $this->app->make(ActivityLogAlertNotifier::class),
            new TelegramAlertNotifier($client),
        ]));

        $key = app(ApiKeyService::class)->create(fake()->words(2, true))->plaintext;

        $this->getJson('/api/v1/movies/MISS-001', [
            'X-API-Key' => $key,
        ])->assertNotFound();

        $this->assertDatabaseHas('crawl_events', [
            'source_slug' => '_api',
            'kind' => 'consumer_miss',
        ]);

        $recorded = ClientBuilder::recorded();
        self::assertNotEmpty($recorded);
        self::assertStringContainsString('sendMessage', (string) $recorded[0]->request->getUri());
        self::assertStringContainsString('MISS-001', (string) $recorded[0]->request->getBody());

        self::assertNotEmpty($store->all());
        self::assertSame('alert.sent', $store->all()[0]->action);
    }

    public function test_watchdog_dispatches_worker_heartbeat_stale_event(): void
    {
        Event::fake([WorkerHeartbeatStale::class]);

        config(['javmeta_alerts.watchdog.heartbeat_stale_seconds' => 60]);
        cache()->forget('javmeta:worker:heartbeat');

        $this->artisan('javmeta:watchdog')->assertSuccessful();

        Event::assertDispatched(WorkerHeartbeatStale::class);
    }

    public function test_watchdog_skips_heartbeat_alert_when_fresh(): void
    {
        Event::fake([WorkerHeartbeatStale::class]);

        app(WorkerHeartbeat::class)->beat();

        $this->artisan('javmeta:watchdog')->assertSuccessful();

        Event::assertNotDispatched(WorkerHeartbeatStale::class);
    }
}
