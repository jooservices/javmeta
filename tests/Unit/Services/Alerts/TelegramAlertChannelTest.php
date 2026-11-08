<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Alerts;

use App\Notifications\TelegramAlertNotifier;
use App\Services\Alerts\AlertChannel;
use App\Services\Alerts\TelegramAlertChannel;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(TelegramAlertChannel::class)]
final class TelegramAlertChannelTest extends TestCase
{
    public function test_it_is_an_alert_channel_that_hands_the_message_to_the_telegram_notifier(): void
    {
        config(['javmeta_alerts.telegram.bot_token' => '', 'laravel-notifications.channels.telegram.bot_token' => '']);
        Http::fake();
        $channel = new TelegramAlertChannel(new TelegramAlertNotifier());

        self::assertInstanceOf(AlertChannel::class, $channel);
        self::assertFalse($channel->send('Subject', 'Body'), 'Without a bot token nothing can be delivered.');
        Http::assertNothingSent();
    }
}
