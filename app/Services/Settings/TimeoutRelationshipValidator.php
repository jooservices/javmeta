<?php

declare(strict_types=1);

namespace App\Services\Settings;

use InvalidArgumentException;

/** Queue/browser timeout ordering rules shared by SettingsService writes. */
final class TimeoutRelationshipValidator
{
    /**
     * @param callable(string): mixed $candidate
     */
    public function validate(callable $candidate): void
    {
        $fetchClient = (int) $candidate('queue.worker_timeout');
        $fetchService = (int) $candidate('timeouts.fetch_service_seconds');
        if ($fetchClient <= $fetchService) {
            throw new InvalidArgumentException(__('settings.validation.queue_worker_timeout'));
        }
        $stopGrace = (int) $candidate('worker.stop_grace_seconds');
        if ($stopGrace < $fetchClient) {
            throw new InvalidArgumentException(__('settings.validation.worker_stop_grace'));
        }

        $retryAfter = (int) $candidate('queue.retry_after_seconds');
        if ($retryAfter <= $fetchClient) {
            throw new InvalidArgumentException(__('settings.validation.queue_retry_after'));
        }

        $gatewayClient = (int) $candidate('browser.gateway_client_timeout_ms');
        $gatewayWait = (int) $candidate('browser.gateway_wait_timeout_ms');
        $playwright = (int) $candidate('browser.playwright_request_timeout_ms');
        $flareSolverr = (int) $candidate('browser.flaresolverr_max_timeout_ms');
        if ($gatewayWait <= max($playwright, $flareSolverr)) {
            throw new InvalidArgumentException(__('settings.validation.browser_gateway_wait'));
        }
        if ($gatewayClient <= $gatewayWait) {
            throw new InvalidArgumentException(__('settings.validation.browser_gateway_client'));
        }
    }
}
