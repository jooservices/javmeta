<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Services\Alerts\WebhookAlertChannel;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WebhookAlertChannelTest extends TestCase
{
    public function test_webhook_context_contains_only_scalar_values(): void
    {
        config([
            'javmeta_alerts.webhook_url' => 'https://hooks.example.test/alerts',
            'javmeta_alerts.webhook_timeout_seconds' => 5,
        ]);
        Http::fake(['hooks.example.test/*' => Http::response([], 200)]);

        self::assertTrue((new WebhookAlertChannel())->send('Cảnh báo', 'Tỷ lệ thấp', [
            'value' => 80,
            'top_sources' => ['javdb', 'onejav'],
            'severity' => 'warning',
        ]));

        Http::assertSent(static fn($request): bool => $request->data()['context'] === [
            'value' => 80,
            'severity' => 'warning',
        ]);
    }
}
