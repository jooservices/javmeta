<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Embedding\OllamaClient;
use Illuminate\Console\Command;

final class AiWarmCommand extends Command
{
    protected $signature = 'ai:warm';

    protected $description = 'Keep the Ollama chat model (and the embedding model when Ollama is the provider) loaded, so the first question is not slow.';

    public function handle(OllamaClient $ollama): int
    {
        if (! (bool) config('javmeta_ai.nl_enabled', true) && (string) config('javmeta_ai.embedding_provider', 'embedder') !== 'ollama') {
            $this->line('Nothing to keep warm.');

            return self::SUCCESS;
        }
        $models = [(string) config('javmeta_ai.ollama_chat_model')];
        if ((string) config('javmeta_ai.embedding_provider', 'embedder') === 'ollama') {
            $models[] = (string) config('javmeta_ai.ollama_embed_model');
        }
        foreach (array_filter($models) as $model) {
            $this->line(sprintf('%s: %s', $model, $ollama->warm($model) ? 'loaded' : 'not reachable'));
        }

        return self::SUCCESS;
    }
}
