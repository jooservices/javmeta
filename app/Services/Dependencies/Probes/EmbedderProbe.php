<?php

declare(strict_types=1);

namespace App\Services\Dependencies\Probes;

use App\Services\Dependencies\DependencyProbe;
use Illuminate\Support\Facades\Http;
use Throwable;

final class EmbedderProbe implements DependencyProbe
{
    public function probe(): bool
    {
        // Switched off on purpose: nothing to wait for, nothing to alert about.
        if (! (bool) config('elasticsearch.enabled', true) || ! (bool) config('elasticsearch.embedder_enabled', true)) {
            return true;
        }

        $url = rtrim((string) config('elasticsearch.embedder_url', 'http://embedder:8000'), '/');

        try {
            return Http::connectTimeout(1)->timeout(2)->get($url . '/healthz')->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
