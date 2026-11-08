<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Repositories\NotificationTemplateServiceRepository;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class NotificationTemplateService
{
    private const ENABLED_CACHE_PREFIX = 'javmeta:notification-policy-enabled:v2:';

    public function __construct(
        private readonly NotificationRegistry $registry,
        private readonly NotificationTemplateValidator $validator,
        private readonly NotificationTemplateServiceRepository $repository,
    ) {}

    /** Insert only missing registry entries; existing edits are never overwritten. */
    public function seed(): int
    {
        return $this->repository->seed($this->registry->all());
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $stored = $this->repository->templatesByKey();
        $rows = [];
        foreach ($this->registry->all() as $key => $definition) {
            $row = $stored[$key] ?? null;
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

        $row = $this->repository->template($key);
        $template = $row === null ? $this->defaultRow($key, $definition) : $this->hydrate($row, $definition);
        $this->rememberEnabled($key, (bool) $template['enabled'], (int) $template['version']);

        return $template;
    }

    public function lastKnownEnabled(string $key): ?bool
    {
        try {
            $enabled = Cache::get(self::ENABLED_CACHE_PREFIX . hash('sha256', $key));
        } catch (Throwable) {
            return null;
        }

        if (! is_array($enabled) || ! is_bool($enabled['enabled'] ?? null)) {
            return null;
        }

        return $enabled['enabled'];
    }

    /** @return array<string, mixed>|null */
    public function findStored(string $key): ?array
    {
        $row = $this->repository->template($key);
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

        $this->repository->saveTemplate($key, $definition, $title, $body, $enabled, $severity, $updatedBy, $policy === null ? [] : $this->repository->policyFields($policy));

        return $this->get($key) ?? throw new RuntimeException('The saved notification template could not be reloaded.');
    }

    /** @return list<array<string, mixed>> */
    public function revisions(string $key): array
    {
        return $this->repository->revisions($key);
    }

    public function restore(string $key, int $revisionId, ?int $updatedBy): array
    {
        $definition = $this->registry->get($key);
        if ($definition === null) {
            throw new InvalidArgumentException('The notification type is not supported.');
        }

        $revision = $this->repository->revision($key, $revisionId);
        if ($revision === null) {
            throw new InvalidArgumentException('The selected revision does not exist for this notification.');
        }
        $this->validator->assertValid($key, (string) $revision['title_template'], (string) $revision['body_template']);
        $this->repository->restoreTemplate($key, $revisionId, $definition, $updatedBy);

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
            $this->repository->policyFields($definition),
        );
    }

    public function count(): int
    {
        return $this->repository->countTemplates();
    }

    public function markSent(string $key): void
    {
        try {
            $this->repository->incrementSentCount($key);
        } catch (Throwable) {
            // Delivery must continue even if template audit metadata is unavailable.
        }
    }

    private function rememberEnabled(string $key, bool $enabled, int $version): void
    {
        $cacheKey = self::ENABLED_CACHE_PREFIX . hash('sha256', $key);
        try {
            Cache::lock($cacheKey . ':lock', 10)->block(5, function () use ($cacheKey, $enabled, $version): void {
                $cached = Cache::get($cacheKey);
                $cachedVersion = is_array($cached) ? (int) ($cached['version'] ?? -1) : -1;
                if ($cachedVersion > $version) {
                    return;
                }

                Cache::forever($cacheKey, ['version' => $version, 'enabled' => $enabled]);
            });
        } catch (Throwable) {
            // The policy database remains authoritative when the cache is unavailable.
        }
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
            ...$this->repository->policyFields($definition),
            'variables' => $definition['variables'],
            'version' => 1,
            'sent_count' => 0,
            'updated_by' => null,
            'last_sent_at' => null,
            'updated_at' => null,
        ];
    }

    /** @param array<string, mixed> $row
     *  @param array<string, mixed> $definition
     *  @return array<string, mixed>
     */
    private function hydrate(array $row, array $definition): array
    {
        return [
            'id' => (int) $row['id'],
            'key' => (string) $row['key'],
            'name' => $definition['name'],
            'usage' => $definition['usage'],
            'channel' => $definition['channel'],
            'title_template' => (string) $row['title_template'],
            'body_template' => (string) $row['body_template'],
            'enabled' => (bool) $row['enabled'],
            'default_severity' => (string) $row['default_severity'],
            'tier' => (string) $row['tier'],
            'cooldown_minutes' => (int) $row['cooldown_minutes'],
            'escalation_minutes' => $row['escalation_minutes'] === null ? null : (int) $row['escalation_minutes'],
            'include_in_periodic_report' => (bool) $row['include_in_periodic_report'],
            'include_in_daily_report' => (bool) $row['include_in_daily_report'],
            'send_resolved' => (bool) $row['send_resolved'],
            'variables' => $definition['variables'],
            'version' => (int) $row['version'],
            'sent_count' => (int) $row['sent_count'],
            'updated_by' => $row['updated_by'] === null ? null : (int) $row['updated_by'],
            'last_sent_at' => $row['last_sent_at'],
            'updated_at' => $row['updated_at'],
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
