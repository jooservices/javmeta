<?php

declare(strict_types=1);

namespace App\Services\Dependencies\Probes;

use App\Services\Dependencies\DetailedDependencyProbe;
use App\Services\Dependencies\ProbeResult;
use Illuminate\Support\Facades\Http;
use Throwable;

final class SentryProbe implements DetailedDependencyProbe
{
    public function probe(): bool
    {
        return $this->check()->available !== false;
    }

    public function check(): ProbeResult
    {
        $dsn = (string) config('sentry.dsn', '');
        $parts = parse_url($dsn);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return new ProbeResult(null, 'Sentry is not configured.');
        }

        $url = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $timeout = (int) config('javmeta_health.startup.timeout_seconds', 2);

        try {
            $status = Http::withoutRedirecting()->connectTimeout($timeout)->timeout($timeout)->head($url)->status();

            return new ProbeResult($status >= 200 && $status < 500, $status >= 200 && $status < 500 ? 'Sentry host responded.' : 'Sentry host failed.');
        } catch (Throwable) {
            return new ProbeResult(false, 'Sentry host failed.');
        }
    }
}
