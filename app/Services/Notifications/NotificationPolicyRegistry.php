<?php

declare(strict_types=1);

namespace App\Services\Notifications;

/** Policy defaults are kept on the same registry entries as their message templates. */
final class NotificationPolicyRegistry
{
    public function __construct(private readonly NotificationRegistry $templates) {}

    /** @return array<string, array{enabled: bool, tier: string, cooldown_minutes: int, escalation_minutes: int, include_periodic_report: bool, include_daily_report: bool, send_resolved: bool}> */
    public function all(): array
    {
        $policies = [];
        foreach ($this->templates->all() as $key => $template) {
            $policies[$key] = [
                'enabled' => (bool) $template['enabled'],
                'tier' => $template['tier'],
                'cooldown_minutes' => (int) $template['cooldown_minutes'],
                'escalation_minutes' => (int) ($template['escalation_minutes'] ?? 0),
                'include_periodic_report' => (bool) $template['include_in_periodic_report'],
                'include_daily_report' => (bool) $template['include_in_daily_report'],
                'send_resolved' => (bool) $template['send_resolved'],
            ];
        }

        return $policies;
    }

    /** @return array{enabled: bool, tier: string, cooldown_minutes: int, escalation_minutes: int, include_periodic_report: bool, include_daily_report: bool, send_resolved: bool}|null */
    public function get(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }
}
