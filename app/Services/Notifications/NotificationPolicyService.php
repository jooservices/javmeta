<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use DateTimeInterface;
use App\Repositories\NotificationPolicyServiceRepository;
use InvalidArgumentException;

/** Persists policy settings on notification templates and tracks open notification causes. */
final class NotificationPolicyService
{
    public function __construct(
        private readonly NotificationTemplateService $templates,
        private readonly NotificationPolicyServiceRepository $repository,
    ) {}

    public function seed(): int
    {
        return $this->templates->seed();
    }

    /** @return array<string, mixed>|null */
    public function get(string $key): ?array
    {
        $template = $this->templates->get($key);

        return $template === null ? null : $this->policy($template);
    }

    public function lastKnownEnabled(string $key): ?bool
    {
        return $this->templates->lastKnownEnabled($key);
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_map(fn(array $template): array => $this->policy($template), $this->templates->all());
    }

    /** @param array<string, mixed> $policy
     *  @return array<string, mixed>
     */
    public function save(string $key, array $policy, ?int $updatedBy): array
    {
        $current = $this->templates->get($key);
        if ($current === null) {
            throw new InvalidArgumentException('The notification type is not supported.');
        }
        $data = $this->validatePolicy($policy, $current);
        $saved = $this->templates->save(
            $key,
            (string) $current['title_template'],
            (string) $current['body_template'],
            $data['enabled'],
            (string) $current['default_severity'],
            $updatedBy,
            [
                'tier' => $data['tier'],
                'cooldown_minutes' => $data['cooldown_minutes'],
                'escalation_minutes' => $data['escalation_minutes'],
                'include_in_periodic_report' => $data['include_periodic_report'],
                'include_in_daily_report' => $data['include_daily_report'],
                'send_resolved' => $data['send_resolved'],
            ],
        );

        return $this->policy($saved);
    }

    /** @param array<string, mixed> $template
     *  @return array<string, mixed>
     */
    private function policy(array $template): array
    {
        return [
            'id' => $template['id'], 'key' => $template['key'], 'name' => $template['name'], 'usage' => $template['usage'],
            'enabled' => (bool) $template['enabled'], 'tier' => $template['tier'],
            'cooldown_minutes' => (int) $template['cooldown_minutes'],
            'escalation_minutes' => (int) ($template['escalation_minutes'] ?? 0),
            'include_periodic_report' => (bool) $template['include_in_periodic_report'],
            'include_daily_report' => (bool) $template['include_in_daily_report'], 'send_resolved' => (bool) $template['send_resolved'],
            'version' => $template['version'], 'updated_by' => $template['updated_by'], 'updated_at' => $template['updated_at'],
        ];
    }

    /** @param array<string, mixed> $input
     *  @param array<string, mixed> $current
     *  @return array{enabled: bool, tier: string, cooldown_minutes: int, escalation_minutes: int|null, include_periodic_report: bool, include_daily_report: bool, send_resolved: bool}
     */
    private function validatePolicy(array $input, array $current): array
    {
        $tier = $input['tier'] ?? $current['tier'];
        if (! in_array($tier, ['critical', 'warning', 'notice', 'info'], true)) {
            throw new InvalidArgumentException('Choose a supported notification tier.');
        }
        $cooldown = $input['cooldown_minutes'] ?? $current['cooldown_minutes'];
        $escalation = $input['escalation_minutes'] ?? ($current['escalation_minutes'] ?? 0);
        foreach (['cooldown' => $cooldown, 'escalation' => $escalation] as $value) {
            if (! is_int($value) || $value < 0 || $value > 10080) {
                throw new InvalidArgumentException('Notification cooldown and escalation must be between 0 and 10080 minutes.');
            }
        }
        $periodic = $input['include_periodic_report'] ?? $input['include_in_periodic_report'] ?? $current['include_in_periodic_report'];
        $daily = $input['include_daily_report'] ?? $input['include_in_daily_report'] ?? $current['include_in_daily_report'];
        $enabled = $input['enabled'] ?? $current['enabled'];
        $resolved = $input['send_resolved'] ?? $current['send_resolved'];
        foreach ([$periodic, $daily, $enabled, $resolved] as $value) {
            if (! is_bool($value)) {
                throw new InvalidArgumentException('Notification policy switches must be boolean.');
            }
        }

        return [
            'enabled' => $enabled,
            'tier' => $tier,
            'cooldown_minutes' => $cooldown,
            'escalation_minutes' => $escalation === 0 ? null : $escalation,
            'include_periodic_report' => $periodic,
            'include_daily_report' => $daily,
            'send_resolved' => $resolved,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function revisions(string $key): array
    {
        return $this->templates->revisions($key);
    }

    /** @return array<string, mixed> */
    public function restore(string $key, int $revisionId, ?int $updatedBy): array
    {
        return $this->policy($this->templates->restore($key, $revisionId, $updatedBy));
    }

    /** @return array<string, mixed> */
    public function reset(string $key, ?int $updatedBy): array
    {
        return $this->policy($this->templates->reset($key, $updatedBy));
    }

    /** @param array<string, mixed> $context */
    public function touchCause(string $key, string $causeHash, array $context): array
    {
        return $this->repository->touchCause($key, $causeHash, $context, $this->safeContext($context));
    }

    /** @return array<string, mixed>|null */
    public function event(string $key, string $causeHash): ?array
    {
        return $this->repository->event($key, $causeHash);
    }

    public function reserve(string $key, string $causeHash, string $tier): bool
    {
        return $this->repository->reserve($key, $causeHash, $tier);
    }

    public function release(string $key, string $causeHash): void
    {
        $this->repository->release($key, $causeHash);
    }

    public function recordSent(string $key, string $causeHash, string $tier, bool $resolved): void
    {
        $this->repository->recordSent($key, $causeHash, $tier, $resolved);
    }

    public function resolveCause(string $key, string $causeHash): void
    {
        $this->repository->resolveCause($key, $causeHash);
    }

    /** @return list<array{key: string, name: string, tier: string, opened_at: string, last_seen_at: string, value: int|float|string|null, threshold: int|float|string|null, context: array<string, mixed>}> */
    public function openWarnings(): array
    {
        return $this->digestItems(false, null, true);
    }

    /** @return list<array{key: string, name: string, tier: string, opened_at: string, last_seen_at: string, value: int|float|string|null, threshold: int|float|string|null, context: array<string, mixed>}> */
    public function digestItems(bool $daily, ?DateTimeInterface $since = null, bool $warningsOnly = false, ?DateTimeInterface $until = null): array
    {
        return $this->repository->digestItems($daily, $since, $warningsOnly, $until);
    }

    /** @return list<array<string, mixed>> */
    public function recentDeployments(DateTimeInterface $since): array
    {
        return array_values(array_filter(
            $this->digestItems(true, $since),
            static fn(array $item): bool => in_array($item['key'], ['deploy.ok', 'deploy.success'], true),
        ));
    }

    /** @param array<string, mixed> $context
     *  @return array<string, mixed>
     */
    private function safeContext(array $context): array
    {
        $allowed = [
            'value', 'threshold', 'notification_cause', 'cause_key', 'rule', 'debounce_key',
            'node', 'release', 'node_count', 'nodes', 'source_slug', 'dependency', 'service',
            'backup_age_seconds', 'threshold_seconds', 'current_ip', 'previous_ip', 'details',
        ];

        return array_intersect_key($context, array_flip($allowed));
    }
}
