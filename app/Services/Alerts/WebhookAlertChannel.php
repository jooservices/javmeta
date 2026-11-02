<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use Illuminate\Support\Facades\Http;

final class WebhookAlertChannel implements AlertChannel
{
    /** @param array<string, mixed> $context */
    public function send(string $subject, string $body, array $context = []): bool
    {
        $url = trim((string) config('javmeta_alerts.webhook_url', ''));
        if ($url === '') {
            return false;
        }

        Http::acceptJson()
            ->timeout(max(1, (int) config('javmeta_alerts.webhook_timeout_seconds', 5)))
            ->post($url, ['subject' => $subject, 'message' => $body, 'context' => $context])
            ->throw();

        return true;
    }
}
