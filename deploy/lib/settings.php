<?php

declare(strict_types=1);

namespace JavMeta\Deploy;

use App\Services\Settings\SettingsService;

/** @param array<string, mixed> $manifest
 * @param array<string, mixed> $saved
 * @param array<string, string> $answers
 */
function importSettings(SettingsService $settings, array $manifest, array $saved, array $answers): void
{
    foreach ($manifest['services'] as $service) {
        if ($service['optional'] && ($answers['SERVICE_' . strtoupper($service['id']) . '_ENABLED'] ?? '') !== '1') {
            continue;
        }
        foreach ($service['fields'] as $field) {
            if (isset($field['setting']) && !array_key_exists($field['setting'], $saved)) {
                $settings->set($field['setting'], $answers[$field['key']]);
            }
        }
    }
}
