<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\NotificationTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use JOOservices\LaravelRepository\Contracts\RepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasCrud;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;
use RuntimeException;

/** Persistence operations for notification templates and their revision history. */
final class NotificationTemplateServiceRepository extends EloquentRepository implements RepositoryInterface
{
    use HasCrud;
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;

    public function __construct(NotificationTemplate $model)
    {
        parent::__construct($model);
    }

    /** @param array<string, array<string, mixed>> $definitions */
    public function seed(array $definitions): int
    {
        $inserted = 0;
        foreach ($definitions as $key => $definition) {
            $inserted += DB::table('notification_templates')->insertOrIgnore($this->templateValues($key, $definition, now()));
        }

        return $inserted;
    }

    /** @return array<string, array<string, mixed>> */
    public function templatesByKey(string $locale = 'en'): array
    {
        $rows = [];
        foreach (DB::table('notification_templates')->where('locale', $locale)->get() as $row) {
            $data = (array) $row;
            $rows[(string) $data['key']] = $data;
        }

        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function template(string $key, string $locale = 'en'): ?array
    {
        $row = DB::table('notification_templates')->where('key', $key)->where('locale', $locale)->first();
        if ($row === null && $locale !== 'en') {
            $row = DB::table('notification_templates')->where('key', $key)->where('locale', 'en')->first();
        }

        return $row === null ? null : (array) $row;
    }

    /** @return array<string, mixed>|null */
    public function revision(string $key, int $revisionId): ?array
    {
        $templateId = DB::table('notification_templates')->where('key', $key)->value('id');
        if ($templateId === null) {
            return null;
        }
        $revision = DB::table('notification_template_revisions')
            ->where('notification_template_id', $templateId)
            ->where('id', $revisionId)
            ->first();

        return $revision === null ? null : (array) $revision;
    }

    /** @return list<array<string, mixed>> */
    public function revisions(string $key): array
    {
        $templateId = DB::table('notification_templates')->where('key', $key)->value('id');
        if ($templateId === null) {
            return [];
        }

        return DB::table('notification_template_revisions')
            ->where('notification_template_id', $templateId)
            ->orderByDesc('id')->limit(50)
            ->get(['id', 'title_template', 'body_template', 'enabled', 'default_severity', 'tier', 'cooldown_minutes', 'escalation_minutes', 'include_in_periodic_report', 'include_in_daily_report', 'send_resolved', 'version', 'updated_by', 'created_at'])
            ->map(static fn(object $revision): array => (array) $revision)
            ->all();
    }

    /** @param array<string, mixed> $definition
     *  @param array<string, mixed> $policyFields
     */
    public function saveTemplate(string $key, array $definition, string $title, string $body, bool $enabled, string $severity, ?int $updatedBy, array $policyFields, string $locale = 'en'): void
    {
        DB::transaction(function () use ($key, $definition, $title, $body, $enabled, $severity, $updatedBy, $policyFields, $locale): void {
            DB::table('notification_templates')->insertOrIgnore($this->templateValues($key, $definition, now(), $locale));
            $current = DB::table('notification_templates')->where('key', $key)->where('locale', $locale)->lockForUpdate()->first();
            if ($current === null) {
                throw new RuntimeException('The notification template could not be loaded.');
            }

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
    }

    /** @param array<string, mixed> $definition */
    public function restoreTemplate(string $key, int $revisionId, array $definition, ?int $updatedBy): void
    {
        DB::transaction(function () use ($key, $revisionId, $definition, $updatedBy): void {
            DB::table('notification_templates')->insertOrIgnore($this->templateValues($key, $definition, now()));
            $template = DB::table('notification_templates')->where('key', $key)->lockForUpdate()->first();
            if ($template === null) {
                throw new RuntimeException('The notification template could not be loaded.');
            }
            $revision = DB::table('notification_template_revisions')
                ->where('notification_template_id', $template->id)
                ->where('id', $revisionId)
                ->first();
            if ($revision === null) {
                throw new InvalidArgumentException('The selected revision does not exist for this notification.');
            }

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
    }

    /** @param array<string, mixed> $definition
     *  @return array{}|array{tier: string, cooldown_minutes: int, escalation_minutes: int|null, include_in_periodic_report: bool, include_in_daily_report: bool, send_resolved: bool}
     */
    public function policyFields(array $definition): array
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

    public function countTemplates(): int
    {
        return DB::table('notification_templates')->count();
    }

    public function incrementSentCount(string $key): void
    {
        DB::table('notification_templates')->where('key', $key)->increment('sent_count', 1, ['last_sent_at' => now()]);
    }

    /** @param array<string, mixed> $definition
     *  @return array<string, mixed>
     */
    /** A null locale leaves the column out, so older migrations can seed before the locale column exists (its default is en). */
    private function templateValues(string $key, array $definition, mixed $now, ?string $locale = null): array
    {
        $values = [
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
        ];
        if ($locale !== null) {
            $values['locale'] = $locale;
        }

        return $values;
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
}
