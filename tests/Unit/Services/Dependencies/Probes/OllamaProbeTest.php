<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dependencies\Probes;

use App\Services\Dependencies\Probes\OllamaProbe;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OllamaProbeTest extends TestCase
{
    public function test_it_checks_the_configured_local_api(): void
    {
        config(['javmeta_ai.nl_enabled' => true, 'javmeta_ai.ollama_url' => 'http://ollama.example.test']);
        Http::fake(['ollama.example.test/api/version' => Http::response(['version' => '0.1'], 200)]);

        self::assertTrue((new OllamaProbe())->probe());
    }

    public function test_it_skips_ollama_when_all_ollama_features_are_disabled(): void
    {
        config(['javmeta_ai.nl_enabled' => false, 'javmeta_ai.embedding_provider' => 'embedder']);

        self::assertNull((new OllamaProbe())->check()->available);
    }
}
