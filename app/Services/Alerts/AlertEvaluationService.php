<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Models\AlertEvent;
use App\Models\Source;
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
        $active = AlertEvent::query()->whereNull('resolved_at')->get()->keyBy('rule');

        foreach ($firing as $rule => $condition) {
            $event = $active->get($rule);
            if (! $event instanceof AlertEvent) {
                $event = AlertEvent::query()->create([
                    'rule' => $rule,
                    'severity' => $condition['severity'],
                    'value' => $condition['value'],
                    'threshold' => $condition['threshold'],
                    'started_at' => now(),
                ]);
            } else {
                $event->fill([
                    'severity' => $condition['severity'],
                    'value' => $condition['value'],
                    'threshold' => $condition['threshold'],
                ])->save();
            }

            if (! str_starts_with($rule, 'source_success_rate:') || ! array_key_exists('success_rate', $firing)) {
                $this->notifyIfDue($event, $rule, $condition);
            }
            $active->forget($rule);
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
    private function notifyIfDue(AlertEvent $event, string $rule, array $condition): void
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

        $event->setAttribute('channel_state', $channelState);
        $event->save();
        $message = $this->messages->format($rule, $condition);
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
            $event->setAttribute('channel_state', $channelState);
            $event->setAttribute('notified_at', now());
            $event->save();
        }
    }

    /** @param array{attempts_by_source: array<string, array{attempts: int, successes: int, blocked: int}>, enabled_sources: list<string>, hot_sources: list<string>, due_by_source: array<string, int>, postgres_connections: int|null, dispatch_age_seconds: int|null, has_due_work: bool} $snapshot */
    private function resolve(AlertEvent $event, string $rule, array $snapshot): void
    {
        $measurement = $this->rules->measurement($rule, $snapshot);
        if ($measurement === null && $this->needsRateSample($rule, $snapshot)) {
            return;
        }
        $value = $measurement['value'] ?? (float) $event->getAttribute('value');
        $threshold = $measurement['threshold'] ?? (float) $event->getAttribute('threshold');
        $severity = (string) $event->getAttribute('severity');
        $context = $measurement['context'] ?? [];
        $condition = [
            'severity' => $severity,
            'value' => $value,
            'threshold' => $threshold,
            'context' => $context,
        ];

        $event->fill(['value' => $value, 'threshold' => $threshold, 'resolved_at' => now()]);
        if (! (bool) config('javmeta_alerts.recovery_notice', true)) {
            $event->setAttribute('recovery_completed_at', now());
        }
        $event->save();
        if ((bool) config('javmeta_alerts.recovery_notice', true)) {
            $this->attemptRecovery($event, $rule, $condition);
        }
    }

    /** @param array{severity: string, value: float|int, threshold: float|int, context: array<string, mixed>} $condition */
    private function attemptRecovery(AlertEvent $event, string $rule, array $condition): void
    {
        $channelState = $this->channelState($event);
        $openChannels = array_values(array_filter(
            array_keys($channelState),
            static fn(string $channel): bool => is_numeric($channelState[$channel]['open_notified_at'] ?? null),
        ));

        if ($openChannels === []) {
            $event->setAttribute('recovery_completed_at', now());
            $event->save();

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
            $event->setAttribute('channel_state', $channelState);
            $event->save();
            $condition['severity'] = 'info';
            $message = $this->messages->format($rule, $condition, true);
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
        $event->setAttribute('channel_state', $channelState);
        if ($complete) {
            $event->setAttribute('recovery_completed_at', now());
        }
        $event->save();
    }

    private function retryPendingRecoveries(): void
    {
        AlertEvent::query()
            ->whereNotNull('resolved_at')
            ->whereNull('recovery_completed_at')
            ->orderBy('resolved_at')
            ->get()
            ->each(function (AlertEvent $event): void {
                $rule = (string) $event->getAttribute('rule');
                $condition = [
                    'severity' => 'info',
                    'value' => (float) $event->getAttribute('value'),
                    'threshold' => (float) $event->getAttribute('threshold'),
                    'context' => [],
                ];
                $this->attemptRecovery($event, $rule, $condition);
            });
    }

    /** @param array{attempts_by_source: array<string, array{attempts: int, successes: int, blocked: int}>, enabled_sources: list<string>, hot_sources: list<string>, due_by_source: array<string, int>, postgres_connections: int|null, dispatch_age_seconds: int|null, has_due_work: bool} $snapshot */
    private function needsRateSample(string $rule, array $snapshot): bool
    {
        if (in_array($rule, ['success_rate', 'blocked_ratio'], true)) {
            return true;
        }
        if (! str_starts_with($rule, 'source_success_rate:')) {
            return false;
        }

        $source = substr($rule, strlen('source_success_rate:'));

        return in_array($source, $snapshot['enabled_sources'], true);
    }

    /** @return array<string, array<string, int>> */
    private function channelState(AlertEvent $event): array
    {
        $state = $event->getAttribute('channel_state');

        return is_array($state) ? $state : [];
    }

    private function cooldownSeconds(): int
    {
        return max(1, (int) config('javmeta_alerts.cooldown_minutes', 30)) * 60;
    }

    private function lockLeaseSeconds(): int
    {
        $sources = Source::query()->where('enabled', true)->count();
        $pendingRecoveries = AlertEvent::query()
            ->whereNotNull('resolved_at')
            ->whereNull('recovery_completed_at')
            ->count();
        $channelCount = max(1, count($this->dispatcher->enabledChannels()));
        $timeout = max(
            1,
            (int) config('javmeta_alerts.webhook_timeout_seconds', 5),
            (int) config('javmeta_alerts.telegram.timeout', 5),
        );
        $possibleDispatches = $sources * 2 + 4 + $pendingRecoveries;

        return max(60, $possibleDispatches * $channelCount * $timeout + 60);
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
        AlertEvent::query()
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '<', now()->subDays($retentionDays))
            ->delete();
    }
}
