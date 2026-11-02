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
        $templateKey = is_string($context['template_key'] ?? null) ? $context['template_key'] : null;
        $policy = null;
        if ($templateKey !== null && ! ($context['policy_prepared'] ?? false)) {
            $policy = app(\App\Services\Notifications\NotificationPolicyDispatcher::class);
            $decision = $policy->prepare($templateKey, $context);
            if ($decision !== null && ! $decision['allowed']) {
                return [];
            }
            if ($decision !== null) {
                if (! $decision['resolved']) {
                    $context['severity'] = $decision['tier'];
                }
                $context['notification_policy_decision'] = $decision;
                if ($this->renderer !== null) {
                    $rendered = $this->renderer->render($templateKey, $context);
                    $subject = $rendered['title'];
                    $body = $rendered['body'];
                    $context['template_rendered'] = true;
                }
            }
        }
        $sent = $this->channels?->dispatch($subject, $body, $context, $force, $onlyChannels) ?? [];
        if ($policy !== null && isset($context['notification_policy_decision'])) {
            if ($sent !== []) {
                $policy->recordSent($context['notification_policy_decision']);
                if ($context['notification_policy_decision']['resolved']) {
                    $policy->resolveCause($context['notification_policy_decision']['policy_key'], (string) ($context['notification_cause'] ?? $context['rule'] ?? ''));
                }
            } else {
                $policy->release($context['notification_policy_decision']);
            }
        }

        return $sent;
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

        if ($templateKey === null && $ttl > 0 && ! Cache::add($cacheKey, 1, $ttl)) {
            return false;
        }

        // Only an alert that was really sent makes the cause active, so a throttled
        // recurrence can never lead to a recovery message without a prior alert.
        $notificationContext = ['alert_state' => 'open', 'notification_cause' => $debounceKey] + $context;
        if ($templateKey === null) {
            $notificationContext += ['severity' => 'warning'];
        }
        if ($templateKey !== null) {
            $decision = null;
            $policy = app(\App\Services\Notifications\NotificationPolicyDispatcher::class);
            $decision = $policy->prepare($templateKey, $notificationContext);
            if ($decision !== null && ! $decision['allowed']) {
                return false;
            }
            if ($decision !== null) {
                $notificationContext['severity'] = $decision['tier'];
            }
            $rendered = ($this->renderer ?? app(NotificationRenderer::class))->render($templateKey, $notificationContext);
            $subject = $rendered['title'];
            $body = $rendered['body'];
            $notificationContext['severity'] ??= $rendered['severity'];
            $notificationContext['template_key'] = $templateKey;
            $notificationContext['template_rendered'] = true;
        }
        $this->notifier->notify($subject, $body, $notificationContext);
        if ($templateKey !== null && isset($decision)) {
            app(\App\Services\Notifications\NotificationPolicyDispatcher::class)->recordSent($decision);
        }
        Cache::put($this->activeKey($debounceKey), 1, now()->addDays(7));

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

        $notificationContext = ['alert_state' => 'resolved', 'severity' => 'info', 'notification_cause' => $debounceKey] + $context;
        $policy = null;
        $decision = null;
        if ($templateKey !== null) {
            $policy = app(\App\Services\Notifications\NotificationPolicyDispatcher::class);
            $decision = $policy->prepare($templateKey, $notificationContext);
            if ($decision !== null && ! $decision['allowed']) {
                return false;
            }
            $rendered = ($this->renderer ?? app(NotificationRenderer::class))->render($templateKey, $notificationContext);
            $subject = $rendered['title'];
            $body = $rendered['body'];
            $notificationContext['template_key'] = $templateKey;
            $notificationContext['template_rendered'] = true;
        }
        if (! Cache::pull($this->activeKey($debounceKey))) {
            if ($policy !== null && $decision !== null) {
                $policy->release($decision);
            }

            return false;
        }
        $this->notifier->notify($subject, $body, $notificationContext);
        if ($policy !== null && $decision !== null) {
            $policy->recordSent($decision);
            $policy->resolveCause((string) $decision['policy_key'], $debounceKey);
        }

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
