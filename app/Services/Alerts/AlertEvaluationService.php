<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Repositories\AlertOperationsServiceRepository;
use App\Services\Notifications\NotificationRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class AlertEvaluationService
{
    private const LAST_EVALUATED_KEY = 'javmeta:alerts:last-evaluated-at';

    public function __construct(
        private readonly AlertSnapshotReader $snapshotReader,
        private readonly AlertRuleEvaluator $rules,
        private readonly AlertMessageFormatter $messages,
        private readonly AlertDispatcher $dispatcher,
        private readonly NotificationRenderer $notifications,
        private readonly AlertTemplateValues $templateValues,
        private readonly AlertOperationsServiceRepository $repository,
    ) {}

    public function evaluate(): bool
    {
        if (! (bool) config('javmeta_alerts.enabled', true)) {
            return false;
        }

        $lock = Cache::lock('javmeta:alerts:evaluation', $this->lockLeaseSeconds());
        if (! $lock->get()) {
            return false;
        }

        try {
            return $this->evaluateLocked();
        } finally {
            $lock->release();
        }
    }

    private function evaluateLocked(): bool
    {
        if (! $this->intervalElapsed()) {
            return false;
        }

        $window = max(1, (int) config('javmeta_alerts.window_minutes', 15));
        $snapshot = $this->snapshotReader->snapshot(CarbonImmutable::now()->subMinutes($window));
        $firing = $this->rules->evaluate($snapshot);
        $active = $this->repository->activeEvents();

        foreach ($firing as $rule => $condition) {
            $event = $active[$rule] ?? null;
            if (! is_array($event)) {
                $event = $this->repository->createEvent([
                    'rule' => $rule,
                    'severity' => $condition['severity'],
                    'value' => $condition['value'],
                    'threshold' => $condition['threshold'],
                    'started_at' => now(),
                ]);
            } else {
                $attributes = [
                    'severity' => $condition['severity'],
                    'value' => $condition['value'],
                    'threshold' => $condition['threshold'],
                ];
                $this->persistEvent($event, $attributes);
            }

            if (! str_starts_with($rule, 'source_success_rate:') || ! array_key_exists('success_rate', $firing)) {
                $this->notifyIfDue($event, $rule, $condition);
            }
            unset($active[$rule]);
        }

        foreach ($active as $rule => $event) {
            $this->resolve($event, (string) $rule, $snapshot);
        }

        $this->retryPendingRecoveries();
        $this->pruneHistory();
        Cache::put(self::LAST_EVALUATED_KEY, now()->getTimestamp(), now()->addDay());

        return true;
    }

    /** @param array{severity: string, value: float|int, threshold: float|int, context: array<string, mixed>} $condition */
    private function notifyIfDue(array &$event, string $rule, array $condition): void
    {
        $channelState = $this->channelState($event);
        $now = now()->getTimestamp();
        $cooldown = $this->cooldownSeconds();
        $dueChannels = [];

        foreach ($this->dispatcher->enabledChannels() as $channel) {
            $lastAttempt = $channelState[$channel]['open_attempted_at'] ?? null;
            if (is_numeric($lastAttempt) && $now - (int) $lastAttempt < $cooldown) {
                continue;
            }

            $channelState[$channel]['open_attempted_at'] = $now;
            $dueChannels[] = $channel;
        }

        if ($dueChannels === []) {
            return;
        }

        $this->persistEvent($event, ['channel_state' => $channelState]);
        $message = $this->notificationMessage($rule, $condition);
        $delivered = $this->dispatcher->dispatchChannels(
            $message['subject'],
            $message['body'],
            $message['context'],
            onlyChannels: $dueChannels,
        );
        if ($delivered !== []) {
            foreach ($delivered as $channel) {
                $channelState[$channel]['open_notified_at'] = now()->getTimestamp();
            }
            $this->persistEvent($event, ['channel_state' => $channelState, 'notified_at' => now()]);
        }
    }

    /** @param array{attempts_by_source: array<string, array{attempts: int, successes: int, blocked: int}>, enabled_sources: list<string>, hot_sources: list<string>, due_by_source: array<string, int>, postgres_connections: int|null, dispatch_age_seconds: int|null, has_due_work: bool} $snapshot */
    private function resolve(array &$event, string $rule, array $snapshot): void
    {
        $measurement = $this->rules->measurement($rule, $snapshot);
        if ($measurement === null && $this->needsRateSample($rule, $snapshot)) {
            return;
        }
        $value = $measurement['value'] ?? (float) $event['value'];
        $threshold = $measurement['threshold'] ?? (float) $event['threshold'];
        $severity = (string) $event['severity'];
        $context = $measurement['context'] ?? [];
        $condition = [
            'severity' => $severity,
            'value' => $value,
            'threshold' => $threshold,
            'context' => $context,
        ];

        $attributes = ['value' => $value, 'threshold' => $threshold, 'resolved_at' => now()];
        if (! (bool) config('javmeta_alerts.recovery_notice', true)) {
            $attributes['recovery_completed_at'] = now();
        }
        $this->persistEvent($event, $attributes);
        if ((bool) config('javmeta_alerts.recovery_notice', true)) {
            $this->attemptRecovery($event, $rule, $condition);
        }
        $templateKey = $this->messages->templateKey($rule);
        if ($templateKey !== null && $event['recovery_completed_at'] !== null) {
            app(\App\Services\Notifications\NotificationPolicyDispatcher::class)->resolveCause($templateKey, $rule);
        }
    }

    /** @param array{severity: string, value: float|int, threshold: float|int, context: array<string, mixed>} $condition */
    private function attemptRecovery(array &$event, string $rule, array $condition): void
    {
        $channelState = $this->channelState($event);
        $openChannels = array_values(array_filter(
            array_keys($channelState),
            static fn(string $channel): bool => is_numeric($channelState[$channel]['open_notified_at'] ?? null),
        ));

        if ($openChannels === []) {
            $this->persistEvent($event, ['recovery_completed_at' => now()]);

            return;
        }

        $maxAttempts = max(1, (int) config('javmeta_alerts.recovery_retry_max_attempts', 3));
        $cooldown = $this->cooldownSeconds();
        $now = now()->getTimestamp();
        $dueChannels = [];
        foreach ($openChannels as $channel) {
            $channelAttempts = (int) ($channelState[$channel]['recovery_attempts'] ?? 0);
            $lastAttempt = $channelState[$channel]['recovery_attempted_at'] ?? null;
            if (is_numeric($channelState[$channel]['recovery_notified_at'] ?? null)
                || $channelAttempts >= $maxAttempts
                || (is_numeric($lastAttempt) && $now - (int) $lastAttempt < $cooldown)) {
                continue;
            }

            $channelState[$channel]['recovery_attempts'] = $channelAttempts + 1;
            $channelState[$channel]['recovery_attempted_at'] = $now;
            $dueChannels[] = $channel;
        }

        if ($dueChannels !== []) {
            $this->persistEvent($event, ['channel_state' => $channelState]);
            $condition['severity'] = 'info';
            $message = $this->notificationMessage($rule, $condition, true);
            $delivered = $this->dispatcher->dispatchChannels(
                $message['subject'],
                $message['body'],
                $message['context'],
                onlyChannels: $dueChannels,
            );
            foreach ($delivered as $channel) {
                $channelState[$channel]['recovery_notified_at'] = now()->getTimestamp();
            }
        }

        $complete = true;
        foreach ($openChannels as $channel) {
            if (! is_numeric($channelState[$channel]['recovery_notified_at'] ?? null)
                && (int) ($channelState[$channel]['recovery_attempts'] ?? 0) < $maxAttempts) {
                $complete = false;
                break;
            }
        }
        $attributes = ['channel_state' => $channelState];
        if ($complete) {
            $attributes['recovery_completed_at'] = now();
        }
        $this->persistEvent($event, $attributes);
    }

    private function retryPendingRecoveries(): void
    {
        $openRules = array_fill_keys($this->repository->activeRules(), true);

        foreach ($this->repository->pendingRecoveries() as $event) {
            $rule = (string) $event['rule'];
            if (isset($openRules[$rule])) {
                $this->persistEvent($event, ['recovery_completed_at' => now()]);

                continue;
            }

            $condition = [
                'severity' => 'info',
                'value' => (float) $event['value'],
                'threshold' => (float) $event['threshold'],
                'context' => [],
            ];
            $this->attemptRecovery($event, $rule, $condition);
            $templateKey = $this->messages->templateKey($rule);
            if ($templateKey !== null && $event['recovery_completed_at'] !== null) {
                app(\App\Services\Notifications\NotificationPolicyDispatcher::class)->resolveCause($templateKey, $rule);
            }
        }
    }

    /** @param array{attempts_by_source: array<string, array{attempts: int, successes: int, blocked: int}>, enabled_sources: list<string>, hot_sources: list<string>, due_by_source: array<string, int>, postgres_connections: int|null, dispatch_age_seconds: int|null, has_due_work: bool} $snapshot */
    private function needsRateSample(string $rule, array $snapshot): bool
    {
        if (in_array($rule, ['success_rate', 'success_rate_critical', 'blocked_ratio'], true)) {
            return true;
        }
        if (! str_starts_with($rule, 'source_success_rate:')) {
            return false;
        }

        $source = substr($rule, strlen('source_success_rate:'));

        return in_array($source, $snapshot['enabled_sources'], true);
    }

    /** @return array<string, array<string, int>> */
    private function channelState(array $event): array
    {
        $state = $event['channel_state'] ?? null;

        return is_array($state) ? $state : [];
    }

    private function cooldownSeconds(): int
    {
        return max(1, (int) config('javmeta_alerts.cooldown_minutes', 30)) * 60;
    }

    private function lockLeaseSeconds(): int
    {
        $sources = $this->repository->enabledSourceCount();
        $pendingRecoveries = $this->repository->pendingRecoveryCount();
        $channelCount = max(1, count($this->dispatcher->enabledChannels()));
        $timeout = max(
            1,
            (int) config('javmeta_alerts.webhook_timeout_seconds', 5),
            (int) config('javmeta_alerts.telegram.timeout', 5),
        );
        $possibleDispatches = $sources * 2 + 4 + $pendingRecoveries;

        return max(60, $possibleDispatches * $channelCount * $timeout + 60);
    }

    /** @param array{severity: string, value: float|int, threshold: float|int, context: array<string, mixed>} $condition
     *  @return array{subject: string, body: string, context: array<string, mixed>}
     */
    private function notificationMessage(string $rule, array $condition, bool $resolved = false): array
    {
        $context = $condition['context'] + [
            'alert_state' => $resolved ? 'resolved' : 'open',
            'severity' => $resolved ? 'info' : $condition['severity'],
            'rule' => $rule,
            'value' => $condition['value'],
            'threshold' => $condition['threshold'],
        ];
        $key = $this->messages->templateKey($rule);
        if ($key === null) {
            return ['subject' => '', 'body' => '', 'context' => $context];
        }

        $values = $context;
        unset($values['severity']);
        $templateValues = $this->templateValues->for($rule, $condition, $resolved);
        $values += $templateValues;
        $rendered = $this->notifications->render($key, $values);
        $context['severity'] = $resolved ? 'info' : $rendered['severity'];
        $context += $templateValues;
        $context['template_key'] = $key;
        $context['template_rendered'] = true;

        return ['subject' => $rendered['title'], 'body' => $rendered['body'], 'context' => $context];
    }

    private function intervalElapsed(): bool
    {
        $lastEvaluated = Cache::get(self::LAST_EVALUATED_KEY);
        if (! is_numeric($lastEvaluated)) {
            return true;
        }

        $interval = max(60, (int) config('javmeta_alerts.evaluate_interval_seconds', 60));

        return now()->getTimestamp() - (int) $lastEvaluated >= $interval;
    }

    private function pruneHistory(): void
    {
        $retentionDays = max(1, (int) config('javmeta_alerts.history_retention_days', 90));
        $this->repository->pruneResolvedBefore(now()->subDays($retentionDays));
    }

    /** @param array<string, mixed> $event
     *  @param array<string, mixed> $attributes
     */
    private function persistEvent(array &$event, array $attributes): void
    {
        $event = array_replace($event, $attributes);
        $this->repository->updateEvent((int) $event['id'], $attributes);
    }
}
