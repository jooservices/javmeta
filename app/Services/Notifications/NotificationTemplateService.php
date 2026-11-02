<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class NotificationTemplateService
{
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

        return $row === null ? $this->defaultRow($key, $definition) : $this->hydrate($row, $definition);
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
    public function save(string $key, string $title, string $body, bool $enabled, string $severity, ?int $updatedBy): array
    {
        $definition = $this->registry->get($key);
        if ($definition === null) {
            throw new InvalidArgumentException('The notification type is not supported.');
        }
        if (! in_array($severity, ['critical', 'warning', 'notice', 'info'], true)) {
            throw new InvalidArgumentException('Choose a supported default severity.');
        }
        $this->validator->assertValid($key, $title, $body);

        DB::transaction(function () use ($key, $title, $body, $enabled, $severity, $updatedBy, $definition): void {
            $this->insertIfMissing($key, $definition);
            $current = DB::table('notification_templates')->where('key', $key)->lockForUpdate()->first();
            if ($current === null) {
                throw new RuntimeException('The notification template could not be loaded.');
            }

            if ($current->title_template !== $title
                || $current->body_template !== $body
                || (bool) $current->enabled !== $enabled
                || $current->default_severity !== $severity) {
                $this->recordRevision($current);
                DB::table('notification_templates')->where('id', $current->id)->update([
                    'title_template' => $title,
                    'body_template' => $body,
                    'enabled' => $enabled,
                    'default_severity' => $severity,
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
            ->get(['id', 'title_template', 'body_template', 'enabled', 'default_severity', 'version', 'updated_by', 'created_at'])
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
            DB::table('notification_templates')->where('id', $template->id)->update([
                'title_template' => $revision->title_template,
                'body_template' => $revision->body_template,
                'enabled' => (bool) $revision->enabled,
                'default_severity' => $revision->default_severity,
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
            'variables' => json_encode($definition['variables'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function recordRevision(object $current): void
    {
        DB::table('notification_template_revisions')->insert([
            'notification_template_id' => $current->id,
            'title_template' => $current->title_template,
            'body_template' => $current->body_template,
            'enabled' => (bool) $current->enabled,
            'default_severity' => $current->default_severity,
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
            'variables' => $definition['variables'],
            'version' => (int) $row->version,
            'sent_count' => (int) $row->sent_count,
            'updated_by' => $row->updated_by === null ? null : (int) $row->updated_by,
            'last_sent_at' => $row->last_sent_at,
            'updated_at' => $row->updated_at,
        ];
    }
}
