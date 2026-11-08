<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Services\Embedding\OllamaClient;
use Mockery\MockInterface;
use Tests\TestCase;

/** Gap coverage for ai:warm (the chat-model path lives in OllamaProviderTest). */
final class AiCommandsTest extends TestCase
{
    public function test_it_has_nothing_to_warm_when_nl_search_is_off_and_embeddings_are_not_ollama(): void
    {
        config(['javmeta_ai.nl_enabled' => false, 'javmeta_ai.embedding_provider' => 'embedder']);
        $this->mock(OllamaClient::class, static function (MockInterface $ollama): void {
            $ollama->shouldNotReceive('warm');
        });

        $this->artisan('ai:warm')
            ->expectsOutputToContain('Nothing to keep warm.')
            ->assertSuccessful();
    }

    public function test_it_also_warms_the_embedding_model_when_ollama_is_the_provider(): void
    {
        config([
            'javmeta_ai.nl_enabled' => true,
            'javmeta_ai.embedding_provider' => 'ollama',
            'javmeta_ai.ollama_chat_model' => 'chat-model',
            'javmeta_ai.ollama_embed_model' => 'embed-model',
        ]);
        $this->mock(OllamaClient::class, static function (MockInterface $ollama): void {
            $ollama->shouldReceive('warm')->once()->with('chat-model')->andReturn(true);
            $ollama->shouldReceive('warm')->once()->with('embed-model')->andReturn(false);
        });

        $this->artisan('ai:warm')
            ->expectsOutputToContain('chat-model: loaded')
            ->expectsOutputToContain('embed-model: not reachable')
            ->assertSuccessful();
    }

    public function test_it_warms_only_the_embedding_model_when_nl_search_is_off(): void
    {
        config([
            'javmeta_ai.nl_enabled' => false,
            'javmeta_ai.embedding_provider' => 'ollama',
            'javmeta_ai.ollama_chat_model' => '',
            'javmeta_ai.ollama_embed_model' => 'embed-model',
        ]);
        $this->mock(OllamaClient::class, static function (MockInterface $ollama): void {
            $ollama->shouldReceive('warm')->once()->with('embed-model')->andReturn(true);
        });

        $this->artisan('ai:warm')
            ->expectsOutputToContain('embed-model: loaded')
            ->assertSuccessful();
    }
}
