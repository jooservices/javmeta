<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Contracts\AlertAuditNotifier;
use App\Observability\TelemetrySanitizer;
use JOOservices\LaravelLogging\Facades\ActivityLog;
use Throwable;

/**
 * Durable alert audit via jooservices/laravel-logging (system adapter).
 * Fail-open so a Mongo outage never blocks Telegram delivery.
 */
final class ActivityLogAlertNotifier implements AlertAuditNotifier
{
    /**
     * Record an alert or recovery with severity; audit storage failures do not block delivery.
     *
     * @param  array<string, mixed>  $context
     */
    public function notify(string $subject, string $body, array $context = []): bool
    {
        $sanitizer = app(TelemetrySanitizer::class);
        $subject = $sanitizer->sanitizeText($subject) ?? '';
        $body = $sanitizer->sanitizeText($body) ?? '';
        $context = $sanitizer->sanitizeContext($context);
        $resolved = strtolower((string) ($context['alert_state'] ?? 'open')) === 'resolved';
        $severity = strtolower((string) ($context['severity'] ?? ($resolved ? 'info' : 'warning')));
        $level = match ($severity) {
            'info' => 'info',
            'critical' => 'error',
            default => 'warning',
        };

        try {
            ActivityLog::system()
                ->level($level)
                ->action($resolved ? 'alert.resolved' : 'alert.sent')
                ->message($subject)
                ->context([
                    'subject' => $subject,
                    'body' => $body,
                    'alert_context' => $context,
                ])
                ->bySystem()
                ->save();
            return true;
        } catch (Throwable) {
            // Fail-open: alert delivery must not depend on the activity-log store.
            return false;
        }
    }
}
