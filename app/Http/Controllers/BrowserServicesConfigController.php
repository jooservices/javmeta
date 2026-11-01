<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BrowserServicesConfigController
{
    public function __invoke(Request $request, SettingsService $settings): JsonResponse
    {
        $values = [];
        foreach ($settings->all() as $setting) {
            $values[$setting['key']] = $setting['value'];
        }
        $data = [
            'concurrency' => ['browser_max' => $values['browser.max_concurrency']],
            'queues' => ['browser_max' => $values['browser.max_queue'], 'crawl_depth_max' => $values['queue.browser_depth_limit']],
            'timeouts' => [
                'playwright_request_ms' => $values['browser.playwright_request_timeout_ms'],
                'flaresolverr_max_ms' => $values['browser.flaresolverr_max_timeout_ms'],
                'gateway_wait_ms' => $values['browser.gateway_wait_timeout_ms'],
                'gateway_client_ms' => $values['browser.gateway_client_timeout_ms'],
                'config_client_ms' => $values['browser.config_api_client_ms'],
            ],
            'thresholds' => [
                'max_requests_per_browser' => $values['browser.max_requests_per_browser'],
                'client_inflight' => $values['queue.browser_inflight'],
                'lane_browser_percent' => $values['crawl.lane_browser_percent'],
                'lane_min_attempts' => $values['crawl.lane_min_attempts'],
            ],
            'retry' => [
                'queue_tries' => $values['worker.queue_tries'],
                'backoff_seconds' => $values['worker.queue_backoff_seconds'],
                'retry_after_seconds' => $values['queue.retry_after_seconds'],
                'crawl_max_attempts' => $values['crawl.max_attempts'],
            ],
            'cleanup' => [
                'temp_data_max_age_hours' => $values['cleanup.temp_data_max_age_hours'],
                'watchdog_stall_after_minutes' => $values['cleanup.watchdog_stall_after_minutes'],
            ],
            'poll_interval_seconds' => $values['browser.config_poll_interval_seconds'],
        ];
        $etag = '"' . hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) . '"';
        $data['version'] = trim($etag, '"');

        foreach (explode(',', (string) $request->header('If-None-Match', '')) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*' || $candidate === $etag || $candidate === 'W/' . $etag) {
                return response()->json(null, 304)->header('ETag', $etag)->header('Cache-Control', 'private, must-revalidate');
            }
        }

        return response()->json(['data' => $data])->header('ETag', $etag)->header('Cache-Control', 'private, must-revalidate');
    }
}
