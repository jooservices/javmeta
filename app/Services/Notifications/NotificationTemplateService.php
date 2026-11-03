<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class NotificationTemplateService
{
    private const ENABLED_CACHE_PREFIX = 'javmeta:notification-policy-enabled:';

    public function __construct(
        private readonly NotificationRegistry $registry,
        private readonly NotificationTemplateValidator $validator,
    ) {}

    /** Insert only missing registry entries; existing edits are never overwritten. */
    public function seed(): int
    {
        $inserted = 0;
        $now = now();

        foreach ($this->registry->all() as $key => $definition) {
            $inserted += DB::table('notification_templates')->insertOrIgnore([
                'key' => $key,
                'name' => $definition['name'],
                'usage' => $definition['usage'],
                'channel' => $definition['channel'],
                'title_template' => $definition['title_template'],
                'body_template' => $definition['body_template'],
                'enabled' => $definition['enabled'],
                'default_severity' => $definition['default_severity'],
                ...$this->policyFields($definition),
                'variables' => json_encode($definition['variables'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $inserted;
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $stored = DB::table('notification_templates')->get()->keyBy('key');
        $rows = [];
        foreach ($this->registry->all() as $key => $definition) {
            $row = $stored->get($key);
            $rows[] = $row === null ? $this->defaultRow($key, $definition) : $this->hydrate($row, $definition);
        }

        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function get(string $key): ?array
    {
        $definition = $this->registry->get($key);
        if ($definition === null) {
            return null;
        }

        $row = DB::table('notification_templates')->where('key', $key)->first();
        $template = $row === null ? $this->defaultRow($key, $definition) : $this->hydrate($row, $definition);
        $this->rememberEnabled($key, (bool) $template['enabled']);

        return $template;
    }

    public function lastKnownEnabled(string $key): ?bool
    {
        try {
            $enabled = Cache::get(self::ENABLED_CACHE_PREFIX . hash('sha256', $key));
        } catch (Throwable) {
            return null;
        }

        return is_bool($enabled) ? $enabled : null;
    }

    /** @return array<string, mixed>|null */
    public function findStored(string $key): ?array
    {
        $row = DB::table('notification_templates')->where('key', $key)->first();
        if ($row === null) {
            return null;
        }
        $definition = $this->registry->get($key);

        return $definition === null ? null : $this->hydrate($row, $definition);
    }

    /** @return array<string, mixed> */
    public function save(string $key, string $title, string $body, bool $enabled, string $severity, ?int $updatedBy, ?array $policy = null): array
    {
        $definition = $this->registry->get($key);
        if ($definition === null) {
            throw new InvalidArgumentException('The notification type is not supported.');
        }
        if (! in_array($severity, ['critical', 'warning', 'notice', 'info'], true)) {
            throw new InvalidArgumentException('Choose a supported default severity.');
        }
        $this->validator->assertValid($key, $title, $body);
        if ($policy !== null) {
            $this->validatePolicy($policy);
        }

        DB::transaction(function () use ($key, $title, $body, $enabled, $severity, $updatedBy, $definition, $policy): void {
            $this->insertIfMissing($key, $definition);
            $current = DB::table('notification_templates')->where('key', $key)->lockForUpdate()->first();
            if ($current === null) {
                throw new RuntimeException('The notification template could not be loaded.');
            }

            $policyFields = $policy === null ? [] : $this->policyFields($policy);
            $changed = $current->title_template !== $title
                || $current->body_template !== $body
                || (bool) $current->enabled !== $enabled
                || $current->default_severity !== $severity;
            foreach ($policyFields as $field => $value) {
                $stored = $current->{$field};
                if ($field === 'tier') {
                    $stored = (string) $stored;
                } elseif (in_array($field, ['include_in_periodic_report', 'include_in_daily_report', 'send_resolved'], true)) {
                    $stored = (bool) $stored;
                } elseif ($field === 'escalation_minutes') {
                    $stored = $stored === null ? null : (int) $stored;
                } else {
                    $stored = (int) $stored;
                }
                if ($stored !== $value) {
                    $changed = true;
                }
            }
            if ($changed) {
                $this->recordRevision($current);
                DB::table('notification_templates')->where('id', $current->id)->update([
                    'title_template' => $title,
                    'body_template' => $body,
                    'enabled' => $enabled,
                    'default_severity' => $severity,
                    ...$policyFields,
                    'version' => (int) $current->version + 1,
                    'updated_by' => $updatedBy,
                    'updated_at' => now(),
                ]);
            }
        });

        return $this->get($key) ?? throw new RuntimeException('The saved notification template could not be reloaded.');
    }

    /** @return list<array<string, mixed>> */
    public function revisions(string $key): array
    {
        $template = DB::table('notification_templates')->where('key', $key)->first(['id']);
        if ($template === null) {
            return [];
        }

        return DB::table('notification_template_revisions')
            ->where('notification_template_id', $template->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'title_template', 'body_template', 'enabled', 'default_severity', 'tier', 'cooldown_minutes', 'escalation_minutes', 'include_in_periodic_report', 'include_in_daily_report', 'send_resolved', 'version', 'updated_by', 'created_at'])
            ->map(static fn(object $revision): array => (array) $revision)
            ->all();
    }

    public function restore(string $key, int $revisionId, ?int $updatedBy): array
    {
        $definition = $this->registry->get($key);
        if ($definition === null) {
            throw new InvalidArgumentException('The notification type is not supported.');
        }

        DB::transaction(function () use ($key, $revisionId, $updatedBy, $definition): void {
            $this->insertIfMissing($key, $definition);
            $template = DB::table('notification_templates')->where('key', $key)->lockForUpdate()->first();
            $revision = DB::table('notification_template_revisions')
                ->where('notification_template_id', $template->id)
                ->where('id', $revisionId)
                ->first();
            if ($revision === null) {
                throw new InvalidArgumentException('The selected revision does not exist for this notification.');
            }

            $this->validator->assertValid($key, (string) $revision->title_template, (string) $revision->body_template);
            $this->recordRevision($template);
            $hasPolicyFields = $revision->tier !== null;
            DB::table('notification_templates')->where('id', $template->id)->update([
                'title_template' => $revision->title_template,
                'body_template' => $revision->body_template,
                'enabled' => (bool) $revision->enabled,
                'default_severity' => $revision->default_severity,
                'tier' => $revision->tier ?? $definition['tier'],
                'cooldown_minutes' => $revision->cooldown_minutes ?? $definition['cooldown_minutes'],
                'escalation_minutes' => $hasPolicyFields ? $revision->escalation_minutes : $definition['escalation_minutes'],
                'include_in_periodic_report' => $revision->include_in_periodic_report ?? $definition['include_in_periodic_report'],
                'include_in_daily_report' => $revision->include_in_daily_report ?? $definition['include_in_daily_report'],
                'send_resolved' => $revision->send_resolved ?? $definition['send_resolved'],
                'version' => (int) $template->version + 1,
                'updated_by' => $updatedBy,
                'updated_at' => now(),
            ]);
        });

        return $this->get($key) ?? throw new RuntimeException('The restored notification template could not be reloaded.');
    }

    public function reset(string $key, ?int $updatedBy): array
    {
        $definition = $this->registry->get($key);
        if ($definition === null) {
            throw new InvalidArgumentException('The notification type is not supported.');
        }

        return $this->save(
            $key,
            $definition['title_template'],
            $definition['body_template'],
            $definition['enabled'],
            $definition['default_severity'],
            $updatedBy,
            $this->policyFields($definition),
        );
    }

    public function count(): int
    {
        return DB::table('notification_templates')->count();
    }

    public function markSent(string $key): void
    {
        try {
            DB::table('notification_templates')->where('key', $key)->increment('sent_count', 1, ['last_sent_at' => now()]);
        } catch (Throwable) {
            // Delivery must continue even if template audit metadata is unavailable.
        }
    }

    private function insertIfMissing(string $key, array $definition): void
    {
        DB::table('notification_templates')->insertOrIgnore([
            'key' => $key,
            'name' => $definition['name'],
            'usage' => $definition['usage'],
            'channel' => $definition['channel'],
            'title_template' => $definition['title_template'],
            'body_template' => $definition['body_template'],
            'enabled' => $definition['enabled'],
            'default_severity' => $definition['default_severity'],
            ...$this->policyFields($definition),
            'variables' => json_encode($definition['variables'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function rememberEnabled(string $key, bool $enabled): void
    {
        try {
            Cache::forever(self::ENABLED_CACHE_PREFIX . hash('sha256', $key), $enabled);
        } catch (Throwable) {
            // The policy database remains authoritative when the cache is unavailable.
        }
    }

    private function recordRevision(object $current): void
    {
        DB::table('notification_template_revisions')->insert([
            'notification_template_id' => $current->id,
            'title_template' => $current->title_template,
            'body_template' => $current->body_template,
            'enabled' => (bool) $current->enabled,
            'default_severity' => $current->default_severity,
            'tier' => $current->tier,
            'cooldown_minutes' => $current->cooldown_minutes,
            'escalation_minutes' => $current->escalation_minutes,
            'include_in_periodic_report' => (bool) $current->include_in_periodic_report,
            'include_in_daily_report' => (bool) $current->include_in_daily_report,
            'send_resolved' => (bool) $current->send_resolved,
            'version' => (int) $current->version,
            'updated_by' => $current->updated_by,
            'created_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $definition
     *  @return array<string, mixed>
     */
    private function defaultRow(string $key, array $definition): array
    {
        return [
            'id' => null,
            'key' => $key,
            'name' => $definition['name'],
            'usage' => $definition['usage'],
            'channel' => $definition['channel'],
            'title_template' => $definition['title_template'],
            'body_template' => $definition['body_template'],
            'enabled' => $definition['enabled'],
            'default_severity' => $definition['default_severity'],
            ...$this->policyFields($definition),
            'variables' => $definition['variables'],
            'version' => 1,
            'sent_count' => 0,
            'updated_by' => null,
            'last_sent_at' => null,
            'updated_at' => null,
        ];
    }

    /** @param object $row
     *  @param array<string, mixed> $definition
     *  @return array<string, mixed>
     */
    private function hydrate(object $row, array $definition): array
    {
        return [
            'id' => (int) $row->id,
            'key' => (string) $row->key,
            'name' => $definition['name'],
            'usage' => $definition['usage'],
            'channel' => $definition['channel'],
            'title_template' => (string) $row->title_template,
            'body_template' => (string) $row->body_template,
            'enabled' => (bool) $row->enabled,
            'default_severity' => (string) $row->default_severity,
            'tier' => (string) $row->tier,
            'cooldown_minutes' => (int) $row->cooldown_minutes,
            'escalation_minutes' => $row->escalation_minutes === null ? null : (int) $row->escalation_minutes,
            'include_in_periodic_report' => (bool) $row->include_in_periodic_report,
            'include_in_daily_report' => (bool) $row->include_in_daily_report,
            'send_resolved' => (bool) $row->send_resolved,
            'variables' => $definition['variables'],
            'version' => (int) $row->version,
            'sent_count' => (int) $row->sent_count,
            'updated_by' => $row->updated_by === null ? null : (int) $row->updated_by,
            'last_sent_at' => $row->last_sent_at,
            'updated_at' => $row->updated_at,
        ];
    }

    /** @param array<string, mixed> $definition
     *  @return array{}|array{tier: string, cooldown_minutes: int, escalation_minutes: int|null, include_in_periodic_report: bool, include_in_daily_report: bool, send_resolved: bool}
     */
    private function policyFields(array $definition): array
    {
        if (! Schema::hasColumn('notification_templates', 'tier')) {
            return [];
        }

        return [
            'tier' => (string) $definition['tier'],
            'cooldown_minutes' => (int) $definition['cooldown_minutes'],
            'escalation_minutes' => isset($definition['escalation_minutes']) ? (int) $definition['escalation_minutes'] : null,
            'include_in_periodic_report' => (bool) $definition['include_in_periodic_report'],
            'include_in_daily_report' => (bool) $definition['include_in_daily_report'],
            'send_resolved' => (bool) $definition['send_resolved'],
        ];
    }

    /** @param array<string, mixed> $policy */
    private function validatePolicy(array $policy): void
    {
        if (! in_array($policy['tier'] ?? null, ['critical', 'warning', 'notice', 'info'], true)
            || ! is_int($policy['cooldown_minutes'] ?? null) || $policy['cooldown_minutes'] < 0 || $policy['cooldown_minutes'] > 10080
            || (isset($policy['escalation_minutes']) && (! is_int($policy['escalation_minutes']) || $policy['escalation_minutes'] < 1 || $policy['escalation_minutes'] > 10080))
            || ! is_bool($policy['include_in_periodic_report'] ?? null)
            || ! is_bool($policy['include_in_daily_report'] ?? null)
            || ! is_bool($policy['send_resolved'] ?? null)) {
            throw new InvalidArgumentException('The notification policy values are invalid.');
        }
    }
}
