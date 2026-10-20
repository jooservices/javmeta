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

        // Remember the cause as active so resolve() can send one recovery message.
        Cache::put($this->activeKey($debounceKey), 1, now()->addDays(7));

        $ttl = $debounceSeconds ?? (int) config('javmeta_alerts.debounce_seconds', 900);
        $cacheKey = 'javmeta:alert:' . hash('sha256', $debounceKey);

        if ($ttl > 0 && ! Cache::add($cacheKey, 1, $ttl)) {
            return false;
        }

        $this->notifier->notify($subject, $body, $context);

        return true;
    }

    /**
     * Send a recovery message once, when the cause behind $debounceKey was alerted before.
     *
     * @param  array<string, mixed>  $context
     */
    public function resolve(string $debounceKey, string $subject, string $body, array $context = []): bool
    {
        if (! (bool) config('javmeta_alerts.enabled', true)) {
            return false;
        }

        if (! Cache::pull($this->activeKey($debounceKey))) {
            return false;
        }

        $this->notifier->notify($subject, $body, $context);

        return true;
    }

    private function activeKey(string $debounceKey): string
    {
        return 'javmeta:alert-active:' . hash('sha256', $debounceKey);
    }
}
