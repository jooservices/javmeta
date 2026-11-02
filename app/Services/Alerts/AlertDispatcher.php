<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Contracts\AlertNotifier;
use App\Services\Notifications\NotificationRenderer;
use Illuminate\Support\Facades\Cache;

/**
 * Fan-out alerts with per-key debounce so noisy crawl/API events do not flood Telegram.
 */
final class AlertDispatcher
{
    public function __construct(
        private readonly AlertNotifier $notifier,
        private readonly ?AlertChannelDispatcher $channels = null,
        private readonly ?NotificationRenderer $renderer = null,
    ) {}

    /**
     * Send an alert through the configured delivery channels. Channel failures are isolated.
     *
     * @param array<string, mixed> $context
     * @return list<string>
     */
    public function dispatchChannels(string $subject, string $body, array $context = [], bool $force = false, ?array $onlyChannels = null): array
    {
        return $this->channels?->dispatch($subject, $body, $context, $force, $onlyChannels) ?? [];
    }

    /** @return list<string> */
    public function enabledChannels(): array
    {
        return $this->channels?->enabledNames() ?? [];
    }

    /**
     * Debounce and send an open warning, recording the active cause for a future recovery.
     *
     * @param  array<string, mixed>  $context
     */
    public function send(string $debounceKey, string $subject, string $body, array $context = [], ?int $debounceSeconds = null, ?string $templateKey = null): bool
    {
        if (! (bool) config('javmeta_alerts.enabled', true)) {
            return false;
        }

        $ttl = $debounceSeconds ?? (int) config('javmeta_alerts.debounce_seconds', 900);
        $cacheKey = 'javmeta:alert:' . hash('sha256', $debounceKey);

        if ($ttl > 0 && ! Cache::add($cacheKey, 1, $ttl)) {
            return false;
        }

        // Only an alert that was really sent makes the cause active, so a throttled
        // recurrence can never lead to a recovery message without a prior alert.
        Cache::put($this->activeKey($debounceKey), 1, now()->addDays(7));

        $notificationContext = ['alert_state' => 'open'] + $context;
        if ($templateKey === null) {
            $notificationContext += ['severity' => 'warning'];
        }
        if ($templateKey !== null) {
            $rendered = ($this->renderer ?? app(NotificationRenderer::class))->render($templateKey, $notificationContext);
            $subject = $rendered['title'];
            $body = $rendered['body'];
            $notificationContext['severity'] ??= $rendered['severity'];
            $notificationContext['template_key'] = $templateKey;
            $notificationContext['template_rendered'] = true;
        }
        $this->notifier->notify($subject, $body, $notificationContext);

        return true;
    }

    /** @param array<string, mixed> $context */
    public function sendTemplate(string $debounceKey, string $templateKey, array $context = [], ?int $debounceSeconds = null): bool
    {
        return $this->send($debounceKey, '', '', $context, $debounceSeconds, $templateKey);
    }

    /**
     * Send a recovery message once, when the cause behind $debounceKey was alerted before.
     *
     * @param  array<string, mixed>  $context
     */
    public function resolve(string $debounceKey, string $subject, string $body, array $context = [], ?string $templateKey = null): bool
    {
        if (! (bool) config('javmeta_alerts.enabled', true)) {
            return false;
        }

        if (! Cache::pull($this->activeKey($debounceKey))) {
            return false;
        }

        $notificationContext = ['alert_state' => 'resolved', 'severity' => 'info'] + $context;
        if ($templateKey !== null) {
            $rendered = ($this->renderer ?? app(NotificationRenderer::class))->render($templateKey, $notificationContext);
            $subject = $rendered['title'];
            $body = $rendered['body'];
            $notificationContext['template_key'] = $templateKey;
            $notificationContext['template_rendered'] = true;
        }
        $this->notifier->notify($subject, $body, $notificationContext);

        return true;
    }

    /** @param array<string, mixed> $context */
    public function resolveTemplate(string $debounceKey, string $templateKey, array $context = []): bool
    {
        return $this->resolve($debounceKey, '', '', $context, $templateKey);
    }

    private function activeKey(string $debounceKey): string
    {
        return 'javmeta:alert-active:' . hash('sha256', $debounceKey);
    }
}
