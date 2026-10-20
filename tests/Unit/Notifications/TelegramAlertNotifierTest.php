<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Notifications\TelegramAlertNotifier;
use Illuminate\Support\Facades\Log;
use JOOservices\Client\Client\ClientBuilder;
use JOOservices\Client\Testing\TestResponse;
use JOOservices\Client\Testing\TestResponseSequence;
use Tests\TestCase;

final class TelegramAlertNotifierTest extends TestCase
{
    protected function tearDown(): void
    {
        ClientBuilder::clearFake();
        parent::tearDown();
    }

    public function test_skips_when_env_missing(): void
    {
        config([
            'javmeta_alerts.telegram.bot_token' => '',
            'javmeta_alerts.telegram.chat_id' => '',
            'laravel-notifications.channels.telegram.bot_token' => '',
            'laravel-notifications.channels.telegram.chat_id' => '',
        ]);
        Log::spy();

        (new TelegramAlertNotifier())->notify('Subject', 'Body', ['a' => 1]);

        self::assertSame([], ClientBuilder::recorded());
        Log::shouldHaveReceived('warning')->withArgs(static function (string $message): bool {
            return $message === 'javmeta.telegram_skipped';
        });
    }

    public function test_posts_telegram_message_when_configured(): void
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

        $client = ClientBuilder::create()
            ->withBaseUri('https://api.telegram.org/')
            ->build();

        (new TelegramAlertNotifier($client))->notify(
            'Circuit open',
            'javdb failed',
            ['source_slug' => 'javdb'],
        );

        $recorded = ClientBuilder::recorded();
        self::assertCount(1, $recorded);
        self::assertStringContainsString('/bottok/sendMessage', (string) $recorded[0]->request->getUri());
        $body = (string) $recorded[0]->request->getBody();
        self::assertStringContainsString('[javmeta:testing] Circuit open', $body);
        self::assertStringContainsString('source_slug: javdb', $body);
        self::assertStringContainsString('"chat_id":"42"', $body);
    }

    public function test_posts_telegram_message_when_bot_token_contains_colon(): void
    {
        config([
            'javmeta_alerts.telegram.bot_token' => '123456:ABC-DEF',
            'javmeta_alerts.telegram.chat_id' => '42',
            'javmeta_alerts.telegram.api_base' => 'https://api.telegram.org',
            'laravel-notifications.channels.telegram.bot_token' => '123456:ABC-DEF',
            'laravel-notifications.channels.telegram.chat_id' => '42',
            'laravel-notifications.channels.telegram.api_base' => 'https://api.telegram.org',
        ]);

        ClientBuilder::fake()->respond(
            'POST',
            '*',
            (new TestResponseSequence())->push(TestResponse::json(['ok' => true])),
        );

        $client = ClientBuilder::create()
            ->withBaseUri('https://api.telegram.org/')
            ->build();

        (new TelegramAlertNotifier($client))->notify('Subject', 'Body');

        $recorded = ClientBuilder::recorded();
        self::assertCount(1, $recorded);
        self::assertStringContainsString('/bot123456:ABC-DEF/sendMessage', (string) $recorded[0]->request->getUri());
    }
}
