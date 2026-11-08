<?php

declare(strict_types=1);

namespace App\Services\Observability;

use Throwable;

/** Turns browser-service and Playwright failures into short, safe reasons that never carry URLs or raw output. */
final class EgressFailureReason
{
    public function playwright(int $exitCode, mixed $stderr): string
    {
        $details = is_string($stderr) ? trim($stderr) : '';
        if ($details === '') {
            return 'Playwright fetch exited with code ' . $exitCode . '.';
        }

        $details = preg_replace('~https?://\S+~i', '[URL]', $details) ?? $details;
        $details = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $details) ?? $details;
        $details = trim(preg_replace('/\s+/', ' ', $details) ?? $details);

        $details = strtolower($details);
        $reason = match (true) {
            str_contains($details, 'timeout'), str_contains($details, 'timed out') => 'Playwright fetch timed out',
            str_contains($details, 'browser launch failed'), str_contains($details, 'could not launch') => 'Playwright browser could not start',
            str_contains($details, 'ssrf_blocked') => 'Playwright fetch target was blocked',
            str_contains($details, 'err_name_not_resolved'), str_contains($details, 'could not resolve') => 'Playwright could not resolve the echo host',
            str_contains($details, 'err_connection_refused'), str_contains($details, 'connection refused') => 'Playwright could not connect to the echo host',
            default => 'Playwright fetch failed',
        };

        return $reason . ' (exit code ' . $exitCode . ').';
    }

    public function exception(Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) {
            return 'Browser service request timed out.';
        }
        if (str_contains($message, 'connection refused')) {
            return 'Browser service refused the connection.';
        }
        if (str_contains($message, 'could not resolve') || str_contains($message, 'name or service not known')) {
            return 'Browser service host could not be resolved.';
        }

        return 'Browser service request failed (' . class_basename($exception) . ').';
    }
}
