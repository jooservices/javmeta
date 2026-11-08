<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ui;

use App\Contracts\ReportChannel;
use App\Services\Notifications\NotificationRenderer;
use App\Services\Notifications\NotificationTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Owner-only editor for notification templates and their revision history. */
final class UiNotificationTemplateController
{
    public function index(NotificationTemplateService $templates): JsonResponse
    {
        return response()->json(['data' => $templates->all()]);
    }

    public function show(string $key, NotificationTemplateService $templates): JsonResponse
    {
        $template = $templates->get($key);
        if ($template === null) {
            return response()->json(['message' => 'Không tìm thấy loại thông báo.'], 404);
        }

        return response()->json(['data' => $template]);
    }

    public function update(string $key, Request $request, NotificationTemplateService $templates): JsonResponse
    {
        $data = $request->validate([
            'title_template' => ['required', 'string', 'max:10000'],
            'body_template' => ['required', 'string', 'max:30000'],
            'enabled' => ['required', 'boolean'],
            'default_severity' => ['required', 'in:critical,warning,notice,info'],
            'tier' => ['sometimes', 'required', 'in:critical,warning,notice,info'],
            'cooldown_minutes' => ['sometimes', 'required', 'integer', 'min:0', 'max:10080'],
            'escalation_minutes' => ['sometimes', 'required', 'integer', 'min:0', 'max:10080'],
            'include_in_periodic_report' => ['sometimes', 'required', 'boolean'],
            'include_in_daily_report' => ['sometimes', 'required', 'boolean'],
            'send_resolved' => ['sometimes', 'required', 'boolean'],
        ]);

        $policyFields = ['tier', 'cooldown_minutes', 'escalation_minutes', 'include_in_periodic_report', 'include_in_daily_report', 'send_resolved'];
        $policy = array_intersect_key($data, array_flip($policyFields));
        if ($policy !== [] && count($policy) !== count($policyFields)) {
            return response()->json(['message' => 'All notification policy fields must be provided together.'], 422);
        }
        if (($policy['escalation_minutes'] ?? null) === 0) {
            $policy['escalation_minutes'] = null;
        }

        try {
            $template = $templates->save(
                $key,
                $data['title_template'],
                $data['body_template'],
                (bool) $data['enabled'],
                $data['default_severity'],
                $request->user()?->id,
                $policy === [] ? null : $policy,
            );
        } catch (InvalidArgumentException $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }

        return response()->json(['data' => $template]);
    }

    public function preview(string $key, Request $request, NotificationTemplateService $templates, NotificationRenderer $renderer): JsonResponse
    {
        $current = $templates->get($key);
        if ($current === null) {
            return response()->json(['message' => 'Không tìm thấy loại thông báo.'], 404);
        }
        $data = $request->validate([
            'title_template' => ['sometimes', 'string', 'max:10000'],
            'body_template' => ['sometimes', 'string', 'max:30000'],
            'default_severity' => ['sometimes', 'in:critical,warning,notice,info'],
        ]);

        try {
            $preview = $renderer->preview(
                $key,
                $data['title_template'] ?? $current['title_template'],
                $data['body_template'] ?? $current['body_template'],
                ['severity' => $data['default_severity'] ?? $current['default_severity']],
            );
        } catch (InvalidArgumentException $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }

        return response()->json(['data' => ['title' => $preview['title'], 'body' => $preview['body']]]);
    }

    public function testSend(string $key, Request $request, NotificationTemplateService $templates, NotificationRenderer $renderer, ReportChannel $channel): JsonResponse
    {
        $current = $templates->get($key);
        if ($current === null) {
            return response()->json(['message' => 'Không tìm thấy loại thông báo.'], 404);
        }
        $data = $request->validate([
            'title_template' => ['sometimes', 'string', 'max:10000'],
            'body_template' => ['sometimes', 'string', 'max:30000'],
            'default_severity' => ['sometimes', 'in:critical,warning,notice,info'],
        ]);

        try {
            $preview = $renderer->preview(
                $key,
                $data['title_template'] ?? $current['title_template'],
                $data['body_template'] ?? $current['body_template'],
                ['severity' => $data['default_severity'] ?? $current['default_severity']],
            );
        } catch (InvalidArgumentException $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }

        if ($preview['channel'] !== 'telegram') {
            $preview['title'] = htmlspecialchars($preview['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $preview['body'] = htmlspecialchars($preview['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        $sent = $channel->deliver($preview['title'], $preview['body'], [
            'template_key' => $key,
            'template_rendered' => true,
            'test_send' => true,
        ]);
        if ($sent) {
            $renderer->markSent($key);
        }
        return response()->json(['data' => ['sent' => $sent]], $sent ? 200 : 503);
    }

    public function reset(string $key, Request $request, NotificationTemplateService $templates): JsonResponse
    {
        try {
            return response()->json(['data' => $templates->reset($key, $request->user()?->id)]);
        } catch (InvalidArgumentException $error) {
            return response()->json(['message' => $error->getMessage()], 404);
        }
    }

    public function revisions(string $key, NotificationTemplateService $templates): JsonResponse
    {
        if ($templates->get($key) === null) {
            return response()->json(['message' => 'Không tìm thấy loại thông báo.'], 404);
        }

        return response()->json(['data' => $templates->revisions($key)]);
    }

    public function restore(string $key, int $revision, Request $request, NotificationTemplateService $templates): JsonResponse
    {
        try {
            $template = $templates->restore($key, $revision, $request->user()?->id);
        } catch (InvalidArgumentException $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }

        return response()->json(['data' => $template]);
    }
}
