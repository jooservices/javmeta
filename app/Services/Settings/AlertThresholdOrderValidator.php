<?php

declare(strict_types=1);

namespace App\Services\Settings;

use InvalidArgumentException;

/** Cross-field checks for the alert thresholds: a critical level must never be looser than its warning level. */
final class AlertThresholdOrderValidator
{
    /** @param callable(string): mixed $candidate reads the value a setting would have after the change */
    public function validate(callable $candidate, bool $validateSuccessRatePair = true): void
    {
        $dispatchWarning = (int) $candidate('alerts.crawl_dispatch_warning_seconds');
        $dispatchCritical = (int) $candidate('alerts.crawl_dispatch_critical_seconds');
        if ($dispatchCritical < $dispatchWarning) {
            throw new InvalidArgumentException('alerts.crawl_dispatch_critical_seconds must be at least alerts.crawl_dispatch_warning_seconds.');
        }

        if ($validateSuccessRatePair) {
            $successRateWarning = (int) $candidate('alerts.success_rate_min_percent');
            $successRateCritical = (int) $candidate('alerts.success_rate_critical_percent');
            if ($successRateCritical > $successRateWarning) {
                throw new InvalidArgumentException('alerts.success_rate_critical_percent must be at most alerts.success_rate_min_percent.');
            }
        }
    }
}
