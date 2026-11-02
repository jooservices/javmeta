<?php

declare(strict_types=1);

namespace Tests\Unit\Alerts;

use App\Contracts\AlertNotifier;
use App\Notifications\TelegramAlertNotifier;
use App\Services\Alerts\AlertDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use JOOservices\Client\Client\ClientBuilder;
use JOOservices\Client\Testing\TestResponse;
use JOOservices\Client\Testing\TestResponseSequence;
use Tests\TestCase;

final class AlertDispatcherTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ClientBuilder::clearFake();
        parent::tearDown();
    }

    private function notifierSpy(): AlertNotifier
    {
        return new class implements AlertNotifier {
            public bool $called = false;

            public function notify(string $subject, string $body, array $context = []): void
            {
                $this->called = true;
            }
        };
    }

    public function test_send_skips_when_alerts_disabled(): void
    {
        config(['javmeta_alerts.enabled' => false]);

        $notifier = $this->notifierSpy();
        $result = (new AlertDispatcher($notifier))->send('k', 's', 'b', [], 0);

        self::assertFalse($result);
        self::assertFalse($notifier->called);
    }

    public function test_send_notifies_when_alerts_enabled(): void
    {
        config(['javmeta_alerts.enabled' => true]);

        $notifier = $this->notifierSpy();
        $result = (new AlertDispatcher($notifier))->send('k', 's', 'b', [], 0);

        self::assertTrue($result);
        self::assertTrue($notifier->called);
    }

    public function test_send_template_reserves_policy_once_and_records_a_delivered_critical_alert(): void
    {
        config([
            'javmeta_alerts.telegram.bot_token' => 'tok',
            'javmeta_alerts.telegram.chat_id' => '42',
            'laravel-notifications.channels.telegram.bot_token' => 'tok',
            'laravel-notifications.channels.telegram.chat_id' => '42',
        ]);
        ClientBuilder::fake()->respond(
            'POST',
            '*',
            (new TestResponseSequence())
                ->push(TestResponse::json(['ok' => true]))
                ->push(TestResponse::json(['ok' => true])),
        );
        $client = ClientBuilder::create()->withBaseUri('https://api.telegram.org/')->build();
        $dispatcher = new AlertDispatcher(new TelegramAlertNotifier($client));

        self::assertTrue($dispatcher->sendTemplate('crawl-stalled', 'crawl.stalled'));
        self::assertFalse($dispatcher->sendTemplate('crawl-stalled', 'crawl.stalled'));
        self::assertCount(1, ClientBuilder::recorded());
        self::assertNotNull(DB::table('notification_policy_events')->value('critical_sent_at'));
    }
}
