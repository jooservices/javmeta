<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Administration;

use App\Contracts\ReportChannel;
use App\Http\Api\V2\ProblemException;
use App\Services\Api\V2\Contracts\AuditLog;
use App\Services\Notifications\NotificationRegistry;
use App\Services\Notifications\NotificationRenderer;
use App\Services\Notifications\NotificationTemplateService;
use InvalidArgumentException;

/** Notification templates as the v2 API shows them: edit, history, restore, preview, test delivery, reset. */
final class TemplateAdministration
{
    public function __construct(
        private readonly NotificationTemplateService $templates,
        private readonly NotificationRegistry $registry,
        private readonly NotificationRenderer $renderer,
        private readonly ReportChannel $channel,
        private readonly AuditLog $audit,
    ) {}

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_map($this->view(...), $this->templates->all());
    }

    /** @return array<string, mixed> */
    public function one(string $key): array
    {
        return $this->view($this->templates->get($key) ?? throw $this->missing());
    }

    /**
     * Saves the listed fields over the current template; the previous content becomes a revision.
     *
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function update(string $actor, ?int $actorId, string $key, array $changes): array
    {
        $current = $this->templates->get($key) ?? throw $this->missing();
        $title = (string) ($changes['title_template'] ?? $current['title_template']);
        $body = (string) ($changes['body_template'] ?? $current['body_template']);
        $this->assertKnownVariables($key, ['title_template' => $title, 'body_template' => $body]);

        $policy = [
            'tier' => (string) ($changes['tier'] ?? $current['tier']),
            'cooldown_minutes' => (int) ($changes['cooldown_minutes'] ?? $current['cooldown_minutes']),
            'escalation_minutes' => array_key_exists('escalation_minutes', $changes) ? $changes['escalation_minutes'] : $current['escalation_minutes'],
            'include_in_periodic_report' => (bool) ($changes['include_in_periodic_report'] ?? $current['include_in_periodic_report']),
            'include_in_daily_report' => (bool) ($changes['include_in_daily_report'] ?? $current['include_in_daily_report']),
            'send_resolved' => (bool) ($changes['send_resolved'] ?? $current['send_resolved']),
        ];

        try {
            $saved = $this->templates->save(
                $key,
                $title,
                $body,
                (bool) ($changes['enabled'] ?? $current['enabled']),
                (string) ($changes['default_severity'] ?? $current['default_severity']),
                $actorId,
                $policy,
            );
        } catch (InvalidArgumentException $error) {
            throw ProblemException::invalid($error->getMessage(), [['field' => 'title_template', 'code' => 'invalid']]);
        }
        $this->audit->record($actor, 'notification_template.update', "notification-templates/{$key}", ['fields' => array_keys($changes)]);

        return $this->view($saved);
    }

    /** @return list<array<string, mixed>> */
    public function revisions(string $key): array
    {
        $this->templates->get($key) ?? throw $this->missing();

        return array_map(static fn(array $revision): array => [
            'id' => (int) $revision['id'],
            'version' => (int) $revision['version'],
            'title_template' => (string) $revision['title_template'],
            'body_template' => (string) $revision['body_template'],
            'enabled' => (bool) $revision['enabled'],
            'severity' => (string) $revision['default_severity'],
            'tier' => $revision['tier'] === null ? null : (string) $revision['tier'],
            'cooldown_minutes' => $revision['cooldown_minutes'] === null ? null : (int) $revision['cooldown_minutes'],
            'created_at' => Timestamps::iso($revision['created_at']),
            'updated_by' => $revision['updated_by'] === null ? null : (int) $revision['updated_by'],
        ], $this->templates->revisions($key));
    }

    /** @return array<string, mixed> */
    public function restore(string $actor, ?int $actorId, string $key, int $revision): array
    {
        $this->templates->get($key) ?? throw $this->missing();
        $known = array_column($this->templates->revisions($key), 'id');
        if (! in_array($revision, array_map('intval', $known), true)) {
            throw ProblemException::notFound('revision_not_found', 'No such revision.');
        }

        try {
            $restored = $this->templates->restore($key, $revision, $actorId);
        } catch (InvalidArgumentException $error) {
            throw ProblemException::invalid($error->getMessage(), [['field' => 'rev', 'code' => 'invalid']]);
        }
        $this->audit->record($actor, 'notification_template.restore', "notification-templates/{$key}", ['revision' => $revision]);

        return $this->view($restored);
    }

    /**
     * Renders the template with sample values; computation only. Unknown {variables} are listed and shown as [name].
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function preview(string $key, array $input): array
    {
        $current = $this->templates->get($key) ?? throw $this->missing();
        $title = (string) ($input['title_template'] ?? $current['title_template']);
        $body = (string) ($input['body_template'] ?? $current['body_template']);
        $allowed = $this->allowedVariables($key);
        $unknown = array_values(array_diff($this->placeholders($title . "\n" . $body), $allowed));
        $variables = $this->sampleVariables($key, (array) ($input['variables'] ?? []));

        try {
            $rendered = $this->renderer->preview($key, $this->hideUnknown($title, $unknown), $this->hideUnknown($body, $unknown), $variables);
        } catch (InvalidArgumentException $error) {
            throw ProblemException::invalid($error->getMessage(), [['field' => 'title_template', 'code' => 'invalid']]);
        }

        return ['title' => $rendered['title'], 'body' => $rendered['body'], 'unknown_variables' => $unknown, 'variables' => $variables];
    }

    /** @return array<string, mixed> */
    public function testDelivery(string $actor, string $key): array
    {
        $current = $this->templates->get($key) ?? throw $this->missing();
        $rendered = $this->renderer->preview($key, (string) $current['title_template'], (string) $current['body_template'], ['severity' => $current['default_severity']]);

        if (! $this->channel->deliver($rendered['title'], $rendered['body'], ['template_key' => $key, 'template_rendered' => true, 'test_send' => true])) {
            throw ProblemException::conflict('delivery_failed', 'The channel did not accept the test message.');
        }
        $this->renderer->markSent($key);
        $this->audit->record($actor, 'notification_template.test_delivery', "notification-templates/{$key}");

        return ['state' => 'queued', 'note' => 'One test message was sent to the configured channel.', 'preview' => ['title' => $rendered['title'], 'body' => $rendered['body']]];
    }

    public function reset(string $actor, ?int $actorId, string $key): void
    {
        $this->templates->get($key) ?? throw $this->missing();
        $this->templates->reset($key, $actorId);
        $this->audit->record($actor, 'notification_template.reset', "notification-templates/{$key}");
    }

    /** @param array<string, string> $texts */
    private function assertKnownVariables(string $key, array $texts): void
    {
        $allowed = $this->allowedVariables($key);
        $errors = [];
        foreach ($texts as $field => $text) {
            foreach ($this->placeholders($text) as $name) {
                if (! in_array($name, $allowed, true)) {
                    $errors[] = ['field' => $field, 'code' => 'unknown_variable'];
                    break;
                }
            }
        }

        if ($errors !== []) {
            throw ProblemException::invalid('The template uses unknown variables.', $errors);
        }
    }

    /** @return list<string> */
    private function allowedVariables(string $key): array
    {
        return array_map('strval', array_column($this->registry->get($key)['variables'] ?? [], 'name'));
    }

    /** @return list<string> */
    private function placeholders(string $text): array
    {
        preg_match_all('/\{([^{}]+)\}/', $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** @param list<string> $unknown */
    private function hideUnknown(string $text, array $unknown): string
    {
        foreach ($unknown as $name) {
            $text = str_replace('{' . $name . '}', '[' . $name . ']', $text);
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function sampleVariables(string $key, array $overrides): array
    {
        $variables = $this->registry->samples($key);
        foreach ($overrides as $name => $value) {
            if (array_key_exists((string) $name, $variables) && (is_scalar($value) || $value === null)) {
                $variables[(string) $name] = $value;
            }
        }

        return $variables;
    }

    private function missing(): ProblemException
    {
        return ProblemException::notFound('template_not_found', 'No such notification template.');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function view(array $row): array
    {
        return [
            'key' => (string) $row['key'],
            'name' => (string) $row['name'],
            'usage' => (string) $row['usage'],
            'channel' => (string) $row['channel'],
            'title_template' => (string) $row['title_template'],
            'body_template' => (string) $row['body_template'],
            'enabled' => (bool) $row['enabled'],
            'severity' => (string) $row['default_severity'],
            'tier' => (string) $row['tier'],
            'cooldown_minutes' => (int) $row['cooldown_minutes'],
            'escalation_minutes' => $row['escalation_minutes'] === null ? null : (int) $row['escalation_minutes'],
            'include_in_periodic_report' => (bool) $row['include_in_periodic_report'],
            'include_in_daily_report' => (bool) $row['include_in_daily_report'],
            'send_resolved' => (bool) $row['send_resolved'],
            'version' => (int) $row['version'],
            'sent_count' => (int) $row['sent_count'],
            'last_sent_at' => Timestamps::iso($row['last_sent_at']),
            'updated_at' => Timestamps::iso($row['updated_at']),
            'allowed_variables' => $this->allowedVariables((string) $row['key']),
        ];
    }
}
