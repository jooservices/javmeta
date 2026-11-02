<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Models\AlertEvent;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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
        if (! (bool) config('javmeta_alerts.enabled', true) || ! $this->intervalElapsed()) {
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

        $this->pruneHistory();
        Cache::put(self::LAST_EVALUATED_KEY, now()->getTimestamp(), now()->addDay());

        return true;
    }

    /** @param array{severity: string, value: float|int, threshold: float|int, context: array<string, mixed>} $condition */
    private function notifyIfDue(AlertEvent $event, string $rule, array $condition): void
    {
        if (! $this->notificationDue($event)) {
            return;
        }

        $message = $this->messages->format($rule, $condition);
        $delivered = $this->dispatcher->dispatchChannels(
            $message['subject'],
            $message['body'],
            $message['context'],
        );
        if ($delivered !== []) {
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

        $event->fill(['value' => $value, 'threshold' => $threshold, 'resolved_at' => now()])->save();
        if ((bool) config('javmeta_alerts.recovery_notice', true) && $event->getAttribute('notified_at') !== null) {
            $message = $this->messages->format($rule, $condition, true);
            $this->dispatcher->dispatchChannels($message['subject'], $message['body'], $message['context']);
        }
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

    private function notificationDue(AlertEvent $event): bool
    {
        $notifiedAt = $event->getAttribute('notified_at');
        if (! $notifiedAt instanceof CarbonInterface) {
            return true;
        }

        $cooldown = max(1, (int) config('javmeta_alerts.cooldown_minutes', 30));

        return $notifiedAt->lessThanOrEqualTo(now()->subMinutes($cooldown));
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
