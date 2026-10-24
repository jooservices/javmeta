<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Embedding\EmbedderClient;
use App\Services\Embedding\OllamaClient;
use Illuminate\Console\Command;

final class AiCheckCommand extends Command
{
    protected $signature = 'ai:check';

    protected $description = 'Is the embedding provider reachable, and does Ollama answer with the configured models? Prints the vector size.';

    public function handle(OllamaClient $ollama, EmbedderClient $embedder): int
    {
        $provider = (string) config('javmeta_ai.embedding_provider', 'embedder');
        $this->line('Embedding provider: ' . $provider);

        $models = $ollama->models();
        $this->line('Ollama ' . $ollama->baseUrl() . ': ' . ($models === null ? 'NOT reachable' : count($models) . ' models (' . implode(', ', array_slice($models, 0, 8)) . ')'));
        if ($models !== null) {
            foreach (['ollama_embed_model', 'ollama_chat_model'] as $key) {
                $name = (string) config("javmeta_ai.{$key}");
                $present = in_array($name, $models, true) || in_array($name . ':latest', $models, true);
                $this->line(sprintf('  %s %s: %s', $key, $name, $present ? 'installed' : 'MISSING (ollama pull ' . $name . ')'));
            }
        }

        $vector = $embedder->embedOne('dimension probe');
        $this->line('Active provider embedding: ' . ($vector === null ? 'FAILED' : count($vector) . ' dimensions (index setting ' . (int) config('elasticsearch.embed_dim') . ')'));

        return $vector === null ? self::FAILURE : self::SUCCESS;
    }
}
