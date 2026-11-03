<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Notifications\TelegramAlertNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use JOOservices\Client\Client\ClientBuilder;
use JOOservices\Client\Testing\TestResponse;
use JOOservices\Client\Testing\TestResponseSequence;
use Tests\TestCase;

final class NotificationPolicyDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ClientBuilder::clearFake();
        parent::tearDown();
    }

    public function test_direct_template_delivery_suppresses_a_warning_by_default(): void
    {
        $notifier = $this->notifier();

        $sent = $notifier->deliver('Warning', 'A warning occurred.', [
            'template_key' => 'crawl.success_rate_low',
            'notification_cause' => 'success_rate',
            'alert_state' => 'open',
            'value' => 82,
            'threshold' => 85,
        ]);

        self::assertFalse($sent);
        self::assertSame([], ClientBuilder::recorded());
    }

    public function test_direct_template_delivery_sends_a_critical_cause_only_once_per_cooldown(): void
    {
        $notifier = $this->notifier();
        $context = [
            'template_key' => 'crawl.stalled',
            'notification_cause' => 'crawl_stalled',
            'alert_state' => 'open',
        ];

        self::assertTrue($notifier->deliver('Crawl stalled', 'No progress.', $context));
        self::assertFalse($notifier->deliver('Crawl stalled', 'Still no progress.', $context));
        self::assertCount(1, ClientBuilder::recorded());
    }

    private function notifier(): TelegramAlertNotifier
    {
        config([
            'javmeta_alerts.telegram.bot_token' => 'tok',
            'javmeta_alerts.telegram.chat_id' => '42',
            'javmeta_alerts.telegram.api_base' => 'https://api.telegram.org',
            'laravel-notifications.channels.telegram.bot_token' => 'tok',
            'laravel-notifications.channels.telegram.chat_id' => '42',
            'laravel-notifications.channels.telegram.api_base' => 'https://api.telegram.org',
        ]);
        ClientBuilder::fake()->respond(
            'POST',
            '*',
            (new TestResponseSequence())->push(TestResponse::json(['ok' => true])),
        );

        return new TelegramAlertNotifier(ClientBuilder::create()->withBaseUri('https://api.telegram.org/')->build());
    }
}
