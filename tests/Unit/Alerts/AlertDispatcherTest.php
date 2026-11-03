<?php

declare(strict_types=1);

namespace Tests\Unit\Alerts;

use App\Contracts\AlertNotifier;
use App\Notifications\TelegramAlertNotifier;
use App\Services\Alerts\AlertDispatcher;
use App\Services\Notifications\NotificationTemplateService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
        CarbonImmutable::setTestNow();
        ClientBuilder::clearFake();
        parent::tearDown();
    }

    private function notifierSpy(): AlertNotifier
    {
        return new class implements AlertNotifier {
            public bool $called = false;

            public function notify(string $subject, string $body, array $context = []): bool
            {
                $this->called = true;

                return true;
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

    public function test_failed_critical_delivery_releases_policy_reservation_and_can_retry(): void
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
                ->push(TestResponse::json(['ok' => false], 503))
                ->push(TestResponse::json(['ok' => true])),
        );
        $client = ClientBuilder::create()->withBaseUri('https://api.telegram.org/')->build();
        $dispatcher = new AlertDispatcher(new TelegramAlertNotifier($client));

        self::assertFalse($dispatcher->sendTemplate('crawl-stalled', 'crawl.stalled'));
        self::assertNull(DB::table('notification_policy_events')->value('critical_sent_at'));
        self::assertTrue($dispatcher->sendTemplate('crawl-stalled', 'crawl.stalled'));
        self::assertNotNull(DB::table('notification_policy_events')->value('critical_sent_at'));
        self::assertCount(2, ClientBuilder::recorded());
    }

    public function test_failed_recovery_delivery_keeps_the_cause_open_and_can_retry(): void
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
                ->push(TestResponse::json(['ok' => false], 503))
                ->push(TestResponse::json(['ok' => true])),
        );
        $client = ClientBuilder::create()->withBaseUri('https://api.telegram.org/')->build();
        $dispatcher = new AlertDispatcher(new TelegramAlertNotifier($client));

        self::assertTrue($dispatcher->sendTemplate('crawl-stalled', 'crawl.stalled'));
        self::assertFalse($dispatcher->resolveTemplate('crawl-stalled', 'crawl.stalled_recovered'));
        self::assertNull(DB::table('notification_policy_events')->value('resolved_at'));
        self::assertTrue($dispatcher->resolveTemplate('crawl-stalled', 'crawl.stalled_recovered'));
        self::assertNotNull(DB::table('notification_policy_events')->value('resolved_at'));
        self::assertCount(3, ClientBuilder::recorded());
    }

    public function test_failed_template_delivery_refreshes_an_existing_active_cause(): void
    {
        config([
            'cache.default' => 'array',
            'javmeta_alerts.telegram.bot_token' => 'tok',
            'javmeta_alerts.telegram.chat_id' => '42',
            'laravel-notifications.channels.telegram.bot_token' => 'tok',
            'laravel-notifications.channels.telegram.chat_id' => '42',
        ]);
        Cache::store('array')->flush();
        CarbonImmutable::setTestNow('2026-11-03 12:00:00 UTC');
        ClientBuilder::fake()->respond(
            'POST',
            '*',
            (new TestResponseSequence())
                ->push(TestResponse::json(['ok' => true]))
                ->push(TestResponse::json(['ok' => false], 503))
                ->push(TestResponse::json(['ok' => true])),
        );
        $client = ClientBuilder::create()->withBaseUri('https://api.telegram.org/')->build();
        $dispatcher = new AlertDispatcher(new TelegramAlertNotifier($client));

        self::assertTrue($dispatcher->sendTemplate('crawl-stalled-ttl', 'crawl.stalled'));
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(6));
        self::assertFalse($dispatcher->sendTemplate('crawl-stalled-ttl', 'crawl.stalled'));
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(2));

        self::assertTrue($dispatcher->resolveTemplate('crawl-stalled-ttl', 'crawl.stalled_recovered'));
        self::assertCount(3, ClientBuilder::recorded());
        CarbonImmutable::setTestNow();
    }

    public function test_policy_denial_refreshes_an_existing_active_cause(): void
    {
        config([
            'cache.default' => 'array',
            'javmeta_alerts.telegram.bot_token' => 'tok',
            'javmeta_alerts.telegram.chat_id' => '42',
            'laravel-notifications.channels.telegram.bot_token' => 'tok',
            'laravel-notifications.channels.telegram.chat_id' => '42',
        ]);
        Cache::store('array')->flush();
        CarbonImmutable::setTestNow('2026-11-03 12:00:00 UTC');
        ClientBuilder::fake()->respond(
            'POST',
            '*',
            (new TestResponseSequence())
                ->push(TestResponse::json(['ok' => true]))
                ->push(TestResponse::json(['ok' => true])),
        );
        $templates = app(NotificationTemplateService::class);
        $template = $templates->get('crawl.stalled');
        self::assertNotNull($template);
        $client = ClientBuilder::create()->withBaseUri('https://api.telegram.org/')->build();
        $dispatcher = new AlertDispatcher(new TelegramAlertNotifier($client));

        self::assertTrue($dispatcher->sendTemplate('crawl-stalled-denied-ttl', 'crawl.stalled'));
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(6));
        $templates->save(
            'crawl.stalled',
            (string) $template['title_template'],
            (string) $template['body_template'],
            false,
            (string) $template['default_severity'],
            null,
        );
        self::assertFalse($dispatcher->sendTemplate('crawl-stalled-denied-ttl', 'crawl.stalled'));

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(2));
        $templates->save(
            'crawl.stalled',
            (string) $template['title_template'],
            (string) $template['body_template'],
            true,
            (string) $template['default_severity'],
            null,
        );

        self::assertTrue($dispatcher->resolveTemplate('crawl-stalled-denied-ttl', 'crawl.stalled_recovered'));
        self::assertCount(2, ClientBuilder::recorded());
        CarbonImmutable::setTestNow();
    }
}
