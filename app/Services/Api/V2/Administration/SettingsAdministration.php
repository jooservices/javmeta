<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Administration;

use App\Http\Api\V2\ProblemException;
use App\Repositories\Api\V2\AdministrationRepository;
use App\Services\Api\V2\Contracts\AuditLog;
use App\Services\Settings\SettingsService;
use App\Services\Settings\SettingsTranslator;
use InvalidArgumentException;

/** The system settings as the v2 API shows them: groups of items, changed one group at a time. */
final class SettingsAdministration
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly SettingsTranslator $translator,
        private readonly AdministrationRepository $repository,
        private readonly AuditLog $audit,
    ) {}

    /** @return list<array{group: string, items: list<array<string, mixed>>}> */
    public function groups(): array
    {
        $rows = $this->settings->all();
        $revisions = $this->repository->settingRevisions(array_map(static fn(array $row): string => (string) $row['key'], $rows));
        $names = $this->translator->groups($this->settings->groups());
        $byGroup = [];
        foreach ($rows as $row) {
            $id = (string) $row['group'];
            $byGroup[$id][] = $this->item($row, $revisions[(string) $row['key']] ?? null);
        }

        $out = [];
        foreach ($byGroup as $id => $items) {
            $out[] = ['group' => $names[$id] ?? (string) $id, 'items' => $items];
        }

        return $out;
    }

    /**
     * One group, found by its name or by its short id.
     *
     * @return array{group: string, items: list<array<string, mixed>>}
     */
    public function group(string $name): array
    {
        $labels = $this->translator->groups($this->settings->groups());
        $label = $labels[$name] ?? (in_array($name, $labels, true) ? $name : null);

        foreach ($this->groups() as $group) {
            if ($group['group'] === $label) {
                return $group;
            }
        }

        throw ProblemException::notFound('settings_group_not_found', 'No such settings group.');
    }

    /**
     * Applies the listed keys of a group; nothing is saved when one value is wrong.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function update(string $actor, string $name, array $values): array
    {
        $group = $this->group($name);
        $items = [];
        foreach ($group['items'] as $item) {
            $items[(string) $item['key']] = $item;
        }

        $errors = [];
        foreach ($values as $key => $value) {
            $error = $this->problemWith((string) $key, $value, $items[(string) $key] ?? null);
            if ($error !== null) {
                $errors[] = $error;
            }
        }
        if ($errors !== []) {
            throw ProblemException::invalid('Some values are invalid.', $errors);
        }

        $applied = [];
        foreach ($values as $key => $value) {
            $result = $this->settings->set((string) $key, $value);
            if ($result['old'] !== $result['new']) {
                $this->repository->bumpSettingRevision((string) $key);
            }
            $restart = $items[(string) $key]['restart_required'] === true;
            $applied[] = [
                'key' => (string) $key,
                'apply_after_seconds' => $restart ? null : $result['apply_after_seconds'],
                'restart_required' => $restart,
            ];
        }
        $this->audit->record($actor, 'settings.update', 'settings/' . $group['group'], ['keys' => array_keys($values)]);

        return $this->group($name) + ['applied' => $applied];
    }

    /**
     * @param array<string, mixed>|null $item
     * @return array<string, mixed>|null
     */
    private function problemWith(string $key, mixed $value, ?array $item): ?array
    {
        if ($item === null) {
            return ['field' => $key, 'code' => 'unknown_key'];
        }
        if ($item['secret'] === true) {
            return ['field' => $key, 'code' => 'secret_not_editable'];
        }
        if ($item['type'] === 'bool' && ! is_bool($value)) {
            return ['field' => $key, 'code' => 'not_boolean'];
        }
        if (in_array($item['type'], ['int', 'float'], true)) {
            if (! is_numeric($value)) {
                return ['field' => $key, 'code' => 'invalid'];
            }
            if (($item['min'] !== null && $value < $item['min']) || ($item['max'] !== null && $value > $item['max'])) {
                return ['field' => $key, 'code' => 'out_of_range', 'min' => $item['min'], 'max' => $item['max']];
            }
        }

        try {
            $this->settings->assertValid($key, $value);
        } catch (InvalidArgumentException) {
            return ['field' => $key, 'code' => 'invalid'];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array{revision: int, updated_at: string|null}|null $revision
     * @return array<string, mixed>
     */
    private function item(array $row, ?array $revision): array
    {
        $key = (string) $row['key'];
        $secret = $row['type'] === 'secret';
        $restart = $row['restart'] ?? null;

        return [
            'key' => $key,
            'label' => $this->translator->label($key, (string) $row['label']),
            'description' => $this->translator->description($key, (string) $row['description']),
            'type' => (string) $row['type'],
            'min' => $row['min'],
            'max' => $row['max'],
            'options' => $row['options'] ?? $row['item_options'],
            'restart' => $restart,
            'secret' => $secret,
            'default' => $secret ? null : $this->settings->defaultValue($key),
            'value' => $secret ? null : $row['value'],
            'revision' => $revision['revision'] ?? 1,
            'updated_at' => Timestamps::iso($revision['updated_at'] ?? null),
            'restart_required' => $restart !== null && $restart !== false,
        ];
    }
}
