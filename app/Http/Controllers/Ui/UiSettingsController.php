<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ui;

use App\Observability\ObservabilityEmitter;
use App\Services\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** The system settings page: read every setting, save one or several. Secrets are write-only. */
final class UiSettingsController
{
    public function index(SettingsService $settings): JsonResponse
    {
        return response()->json(['data' => ['groups' => $settings->groups(), 'settings' => $settings->all()]]);
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

        return response()->json(['data' => ['applied' => $applied, 'errors' => $errors]], $errors === [] ? 200 : 422);
    }
}
