<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dependencies\Probes;

use App\Services\Dependencies\Probes\TelegramProbe;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class TelegramProbeTest extends TestCase
{
    public function test_it_uses_the_bot_health_api_without_returning_the_token(): void
    {
        config(['javmeta_alerts.telegram.bot_token' => 'test-token', 'javmeta_alerts.telegram.api_base' => 'https://telegram.example.test']);
        Http::fake(['telegram.example.test/*' => Http::response(['ok' => true], 200)]);

        $result = (new TelegramProbe())->check();

        self::assertTrue($result->available);
        self::assertStringNotContainsString('test-token', $result->message);
    }

    public function test_it_reports_missing_bot_configuration_as_disabled(): void
    {
        config(['javmeta_alerts.telegram.bot_token' => '', 'laravel-notifications.channels.telegram.bot_token' => '']);

        self::assertNull((new TelegramProbe())->check()->available);
    }
}
