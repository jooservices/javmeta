<?php

declare(strict_types=1);

namespace App\Services\Dependencies\Probes;

use App\Services\Dependencies\DetailedDependencyProbe;
use App\Services\Dependencies\ProbeResult;
use Illuminate\Support\Facades\Http;
use Throwable;

final class BrowserProbe implements DetailedDependencyProbe
{
    public function probe(): bool
    {
        return $this->check()->available !== false;
    }

    public function check(): ProbeResult
    {
        $endpoints = array_filter([
            (string) config('javmeta_health.startup.flaresolverr_url', ''),
            (string) config('javmeta_health.startup.playwright_url', ''),
        ], static fn(string $url): bool => trim($url) !== '');
        if ($endpoints === []) {
            return new ProbeResult(null, 'Browser services are not configured.');
        }

        $timeout = (int) config('javmeta_health.startup.timeout_seconds', 2);
        foreach ($endpoints as $endpoint) {
            try {
                $status = Http::withoutRedirecting()->connectTimeout($timeout)->timeout($timeout)->head(rtrim($endpoint, '/'))->status();
                if ($status < 200 || $status === 404 || $status >= 500) {
                    return new ProbeResult(false, 'A configured browser endpoint failed.');
                }
            } catch (Throwable) {
                return new ProbeResult(false, 'A configured browser endpoint failed.');
            }
        }

        return new ProbeResult(true, 'Configured browser endpoints responded.');
    }
}
