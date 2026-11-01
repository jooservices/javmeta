<?php

declare(strict_types=1);

namespace App\Services\Dependencies\Probes;

use App\Services\Dependencies\DetailedDependencyProbe;
use App\Services\Dependencies\ProbeResult;
use Illuminate\Support\Facades\Http;
use Throwable;

final class EmbedderProbe implements DetailedDependencyProbe
{
    public function probe(): bool
    {
        return $this->check()->available !== false;
    }

    public function check(): ProbeResult
    {
        // Switched off on purpose: nothing to wait for, nothing to alert about.
        if (! (bool) config('elasticsearch.enabled', true) || ! (bool) config('elasticsearch.embedder_enabled', true)) {
            return new ProbeResult(null, 'Embedding is disabled.');
        }

        $url = rtrim((string) config('elasticsearch.embedder_url', 'http://embedder:8000'), '/');

        try {
            $seconds = (int) config('javmeta_health.startup.timeout_seconds', 2);
            if ($seconds <= 0) {
                return new ProbeResult(false, 'Health endpoint failed.');
            }

            $available = Http::connectTimeout(min(1, $seconds))->timeout($seconds)->get($url . '/healthz')->successful();

            return new ProbeResult($available, $available ? 'Health endpoint responded.' : 'Health endpoint failed.');
        } catch (Throwable) {
            return new ProbeResult(false, 'Health endpoint failed.');
        }
    }
}
