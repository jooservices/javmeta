<?php

declare(strict_types=1);

namespace App\Services\Dependencies\Probes;

use App\Services\Dependencies\DetailedDependencyProbe;
use App\Services\Dependencies\ProbeResult;
use Illuminate\Support\Facades\Http;
use Throwable;

final class OllamaProbe implements DetailedDependencyProbe
{
    public function probe(): bool
    {
        return $this->check()->available !== false;
    }

    public function check(): ProbeResult
    {
        $enabled = (bool) config('javmeta_ai.nl_enabled', true)
            || (string) config('javmeta_ai.embedding_provider', 'embedder') === 'ollama';
        if (! $enabled) {
            return new ProbeResult(null, 'Ollama is not enabled.');
        }

        $url = rtrim((string) config('javmeta_ai.ollama_url', ''), '/') . '/api/version';
        $timeout = (int) config('javmeta_health.startup.timeout_seconds', 2);

        try {
            $available = Http::withoutRedirecting()->connectTimeout($timeout)->timeout($timeout)->get($url)->successful();

            return new ProbeResult($available, $available ? 'Ollama API responded.' : 'Ollama API check failed.');
        } catch (Throwable) {
            return new ProbeResult(false, 'Ollama API check failed.');
        }
    }
}
