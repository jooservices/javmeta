<?php

declare(strict_types=1);

namespace App\Services\Dependencies\Probes;

use App\Services\Dependencies\DetailedDependencyProbe;
use App\Services\Dependencies\ProbeResult;
use Illuminate\Support\Facades\Http;
use Throwable;

final class ObservabilityProbe implements DetailedDependencyProbe
{
    public function probe(): bool
    {
        return $this->check()->available !== false;
    }

    public function check(): ProbeResult
    {
        if (! (bool) config('openobserve.enabled', false)) {
            return new ProbeResult(null, 'OpenObserve is disabled.');
        }

        $url = rtrim((string) config('openobserve.url', 'http://openobserve:5080'), '/');

        try {
            $timeout = (int) config('javmeta_health.startup.timeout_seconds', 2);
            $available = Http::connectTimeout($timeout)->timeout($timeout)->get($url . '/healthz')->successful();

            return new ProbeResult($available, $available ? 'Health endpoint responded.' : 'Health endpoint failed.');
        } catch (Throwable) {
            return new ProbeResult(false, 'Health endpoint failed.');
        }
    }
}
