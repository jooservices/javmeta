<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ui;

use App\Observability\ObservabilityEmitter;
use App\Services\Settings\SettingsService;
use App\Services\Settings\SettingsTranslator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** The system settings page: read every setting, save one or several. Secrets are write-only. */
final class UiSettingsController
{
    public function index(SettingsService $settings, SettingsTranslator $translator): JsonResponse
    {
        $rows = [];
        foreach ($settings->all() as $row) {
            $key = (string) $row['key'];
            $row['label'] = $translator->label($key, (string) $row['label']);
            $row['description'] = $translator->description($key, (string) $row['description']);
            $rows[] = $row;
        }

        return response()->json([
            'data' => [
                'groups' => $translator->groups($settings->groups()),
                'settings' => $rows,
            ],
        ]);
    }

    public function update(Request $request, SettingsService $settings, ObservabilityEmitter $observability): JsonResponse
    {
        $changes = $request->input('settings');
        if (! is_array($changes) || $changes === []) {
            return response()->json(['message' => 'Send {"settings": {"key": value}}.'], 422);
        }

        $errors = [];
        $applied = [];
        foreach ($changes as $key => $value) {
            try {
                $result = $settings->set((string) $key, $value);
                $applied[] = (string) $key;
                $observability->emitOps('setting_changed', ['key' => $result['key'], 'by' => $request->user()?->email, 'old' => $result['old'], 'new' => $result['new'], 'secret' => $result['secret']]);
            } catch (InvalidArgumentException $e) {
                $errors[(string) $key] = $e->getMessage();
            }
        }

        return response()->json([
            'data' => [
                'applied' => $applied,
                'errors' => $errors,
                'apply_after_seconds' => $settings->applyAfterSeconds($applied),
            ],
        ], $errors === [] ? 200 : 422);
    }
}
