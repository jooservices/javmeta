<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Contracts\AlertNotifier;
use Illuminate\Support\Facades\Cache;

/**
 * Fan-out alerts with per-key debounce so noisy crawl/API events do not flood Telegram.
 */
final class AlertDispatcher
{
    public function __construct(private readonly AlertNotifier $notifier) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function send(string $debounceKey, string $subject, string $body, array $context = [], ?int $debounceSeconds = null): bool
    {
        if (! (bool) config('javmeta_alerts.enabled', true)) {
            return false;
        }

        $ttl = $debounceSeconds ?? (int) config('javmeta_alerts.debounce_seconds', 900);
        $cacheKey = 'javmeta:alert:' . hash('sha256', $debounceKey);

        if ($ttl > 0 && ! Cache::add($cacheKey, 1, $ttl)) {
            return false;
        }

        $this->notifier->notify($subject, $body, $context);

        return true;
    }
}
