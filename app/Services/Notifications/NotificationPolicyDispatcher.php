<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Observability\NotificationPolicyMetrics;
use App\Services\Settings\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class NotificationPolicyDispatcher
{
    public function __construct(
        private readonly NotificationPolicyService $policies,
        private readonly NotificationPolicyRegistry $registry,
        private readonly SettingsService $settings,
        private readonly NotificationPolicyMetrics $metrics,
    ) {}

    /**
     * @param array<string, mixed> $context
     * @return array{allowed: bool, key: string, policy_key: string, cause_key: string, tier: string, resolved: bool, reason: string|null}|null
     */
    public function prepare(?string $templateKey, array $context = []): ?array
    {
        if (($context['test_send'] ?? false) === true || ($context['policy_bypass'] ?? false) === true) {
            return null;
        }
        $key = is_string($context['notification_key'] ?? null) ? $context['notification_key'] : $templateKey;
        if ($key === null || $key === '') {
            return null;
        }
        $policyKey = $this->policyKey($key, $context);
        $isResolved = strtolower((string) ($context['alert_state'] ?? '')) === 'resolved'
            || strtolower((string) ($context['severity'] ?? '')) === 'resolved';
        $causeKey = $this->causeKey($context, $policyKey);

        try {
            $policy = $this->policies->get($policyKey);
            if ($policy === null) {
                return $this->suppress($key, $policyKey, $causeKey, 'registry_missing', $isResolved);
            }
            if (! $policy['enabled']) {
                return $this->suppress($key, $policyKey, $causeKey, 'disabled', $isResolved);
            }

            $security = str_starts_with($policyKey, 'security.') || ($context['security_event'] ?? false) === true;
            $quiet = $this->quietMode();
            if ($quiet && ! $security && (! $isResolved && $policy['tier'] !== 'critical' || $isResolved)) {
                return $this->suppress($key, $policyKey, $causeKey, 'quiet_mode', $isResolved);
            }
            if ($quiet && $security && ! $isResolved) {
                $policy['tier'] = 'critical';
            }

            if ($isResolved) {
                return $this->prepareResolved($key, $policyKey, $causeKey, $policy, $security);
            }

            $event = $this->policies->touchCause($policyKey, $causeKey, $context);
            $digest = ($context['notification_digest'] ?? false) === true;
            $tier = (string) $policy['tier'];
            if ($digest) {
                return $this->reserveDecision($key, $policyKey, $causeKey, $tier, false);
            }

            if ($tier === 'warning') {
                $openedAt = CarbonImmutable::parse((string) $event->first_seen_at);
                if ($policy['escalation_minutes'] <= 0 || $openedAt->greaterThan(now()->subMinutes($policy['escalation_minutes']))) {
                    return $this->suppress($key, $policyKey, $causeKey, 'warning_digest', false);
                }
                $tier = 'critical';
            } elseif ($tier === 'info') {
                return $this->suppress($key, $policyKey, $causeKey, 'digest_only', false);
            }

            if ($tier === 'notice' && $event->notice_sent_at !== null) {
                return $this->suppress($key, $policyKey, $causeKey, 'duplicate_event', false);
            }
            if ($this->isCoolingDown($event, (int) $policy['cooldown_minutes'])) {
                return $this->suppress($key, $policyKey, $causeKey, 'cooldown', false);
            }
            if ($this->reservationActive($event)) {
                return $this->suppress($key, $policyKey, $causeKey, 'reserved', false);
            }

            return $this->reserveDecision($key, $policyKey, $causeKey, $tier, false);
        } catch (Throwable $exception) {
            Log::warning('javmeta.notification_policy_unavailable', ['reason' => $exception::class]);
            $fallback = $this->registry->get($policyKey);
            if ($fallback !== null && ($fallback['tier'] === 'critical' || str_starts_with($policyKey, 'security.')) && ! $isResolved) {
                return $this->decision(true, $key, $policyKey, $causeKey, 'critical', false, null);
            }

            return $this->suppress($key, $policyKey, $causeKey, 'policy_unavailable', $isResolved);
        }
    }

    /** @param array{allowed: bool, key: string, policy_key: string, cause_key: string, tier: string, resolved: bool, reason: string|null}|null $decision */
    public function recordSent(?array $decision): void
    {
        if ($decision === null || ! $decision['allowed']) {
            return;
        }
        try {
            $this->policies->recordSent($decision['policy_key'], $decision['cause_key'], $decision['tier'], $decision['resolved']);
            $this->metrics->sent($decision['tier'], $decision['key']);
        } catch (Throwable) {
            // A notification that has already been delivered must not fail because its metrics could not be saved.
        }
    }

    /** @param array{allowed: bool, policy_key: string, cause_key: string}|null $decision */
    public function release(?array $decision): void
    {
        if ($decision === null || ! $decision['allowed']) {
            return;
        }
        try {
            $this->policies->release($decision['policy_key'], $decision['cause_key']);
        } catch (Throwable) {
            // Reservation expiry remains a fallback if the policy store is unavailable.
        }
    }

    /** @param array<string, mixed> $context */
    public function resolveCause(string $key, string $cause, array $context = []): void
    {
        try {
            $policyKey = $this->policyKey($key, $context);
            $this->policies->resolveCause($policyKey, $this->hashCause($policyKey, $cause));
        } catch (Throwable) {
            // Alert recovery must continue when the policy state store is unavailable.
        }
    }

    /** @param array<string, mixed> $policy
     *  @return array{allowed: bool, key: string, policy_key: string, cause_key: string, tier: string, resolved: bool, reason: string|null}
     */
    private function prepareResolved(string $key, string $policyKey, string $causeKey, array $policy, bool $security): array
    {
        $event = $this->policies->event($policyKey, $causeKey);
        if (! $policy['send_resolved']) {
            return $this->suppress($key, $policyKey, $causeKey, 'resolved_disabled', true);
        }
        if ($event !== null && $event->resolved_at !== null) {
            return $this->suppress($key, $policyKey, $causeKey, 'already_resolved', true);
        }
        if ($event === null || $event->critical_sent_at === null) {
            return $this->suppress($key, $policyKey, $causeKey, 'resolved_without_critical', true);
        }
        if ($this->reservationActive($event)) {
            return $this->suppress($key, $policyKey, $causeKey, 'reserved', true);
        }

        return $this->reserveDecision($key, $policyKey, $causeKey, $security ? 'critical' : 'critical', true);
    }

    /** @return array{allowed: bool, key: string, policy_key: string, cause_key: string, tier: string, resolved: bool, reason: string|null} */
    private function reserveDecision(string $key, string $policyKey, string $causeKey, string $tier, bool $resolved): array
    {
        if (! $this->policies->reserve($policyKey, $causeKey, $tier)) {
            return $this->suppress($key, $policyKey, $causeKey, 'reserved', $resolved);
        }

        return $this->decision(true, $key, $policyKey, $causeKey, $tier, $resolved, null);
    }

    /** @return array{allowed: bool, key: string, policy_key: string, cause_key: string, tier: string, resolved: bool, reason: string|null} */
    private function suppress(string $key, string $policyKey, string $causeKey, string $reason, bool $resolved): array
    {
        try {
            $this->metrics->suppressed($reason);
        } catch (Throwable) {
            // Suppression metrics are best effort.
        }

        return $this->decision(false, $key, $policyKey, $causeKey, 'info', $resolved, $reason);
    }

    /** @return array{allowed: bool, key: string, policy_key: string, cause_key: string, tier: string, resolved: bool, reason: string|null} */
    private function decision(bool $allowed, string $key, string $policyKey, string $causeKey, string $tier, bool $resolved, ?string $reason): array
    {
        return [
            'allowed' => $allowed,
            'key' => $key,
            'policy_key' => $policyKey,
            'cause_key' => $causeKey,
            'tier' => $tier,
            'resolved' => $resolved,
            'reason' => $reason,
        ];
    }

    private function isCoolingDown(object $event, int $minutes): bool
    {
        if ($minutes <= 0 || $event->last_sent_at === null) {
            return false;
        }

        return CarbonImmutable::parse((string) $event->last_sent_at)->greaterThan(now()->subMinutes($minutes));
    }

    private function reservationActive(object $event): bool
    {
        if ($event->reserved_at === null) {
            return false;
        }

        return CarbonImmutable::parse((string) $event->reserved_at)->greaterThan(now()->subMinutes(5));
    }

    private function quietMode(): bool
    {
        try {
            return (bool) $this->settings->get('notifications.quiet_mode');
        } catch (Throwable) {
            return (bool) config('javmeta_notifications.policy.quiet_mode', false);
        }
    }

    /** @param array<string, mixed> $context */
    private function causeKey(array $context, string $key): string
    {
        foreach (['notification_cause', 'cause_key', 'rule', 'debounce_key'] as $field) {
            if (is_string($context[$field] ?? null) && $context[$field] !== '') {
                return $this->hashCause($key, $context[$field]);
            }
        }

        return $this->hashCause($key, $key);
    }

    private function hashCause(string $key, string $cause): string
    {
        return hash('sha256', $key . "\0" . $cause);
    }

    /** @param array<string, mixed> $context */
    private function policyKey(string $key, array $context = []): string
    {
        return match ($key) {
            'crawl.heartbeat_recovered' => 'crawl.heartbeat_stale',
            'crawl.source_recovered' => 'crawl.source_stale',
            'crawl.stalled_recovered' => 'crawl.stalled',
            'crawl.queue_recovered' => 'crawl.queue_stuck',
            'crawl.backlog_recovered' => 'crawl.backlog_high',
            'backup.recovered' => ($context['notification_cause'] ?? null) === 'backup:missing' ? 'backup.manifest_missing' : 'backup.stale',
            'source.health_recovered' => 'source.health_blocked',
            'staging.readiness_recovered' => 'staging.readiness_blocked',
            default => $key,
        };
    }
}
