<?php

declare(strict_types=1);

namespace App\Services\Dependencies\Probes;

use App\Services\Dependencies\DetailedDependencyProbe;
use App\Services\Dependencies\ProbeResult;
use Illuminate\Support\Facades\Http;
use Throwable;

final class TelegramProbe implements DetailedDependencyProbe
{
    public function probe(): bool
    {
        return $this->check()->available !== false;
    }

    public function check(): ProbeResult
    {
        $token = (string) (config('laravel-notifications.channels.telegram.bot_token') ?: config('javmeta_alerts.telegram.bot_token'));
        if ($token === '') {
            return new ProbeResult(null, 'Telegram is not configured.');
        }

        $url = rtrim((string) config('javmeta_alerts.telegram.api_base', 'https://api.telegram.org'), '/') . '/bot' . $token . '/getMe';
        $timeout = (int) config('javmeta_health.startup.timeout_seconds', 2);

        try {
            $response = Http::withoutRedirecting()->connectTimeout($timeout)->timeout($timeout)->get($url);
            $available = $response->successful() && $response->json('ok') === true;

            return new ProbeResult($available, $available ? 'Telegram bot API responded.' : 'Telegram bot API check failed.');
        } catch (Throwable) {
            return new ProbeResult(false, 'Telegram bot API check failed.');
        }
    }
}
