<?php

declare(strict_types=1);

// Defaults only: the values are settings in the database (docs/03-operations/settings.md) and are edited in the UI.
// Policy: docs/decisions/cloud-ai-policy-decision.md (local models by default, cloud off).
return [
    // "embedder" is the multilingual E5 service; "ollama" uses the Ollama embedding model below.
    'embedding_provider' => 'embedder',
    'ollama_url' => 'http://127.0.0.1:11434',
    'ollama_embed_model' => 'nomic-embed-text',
    'ollama_chat_model' => 'qwen2.5:7b',
    'ollama_timeout' => 30,
    // A request accepts a model answer for at most two seconds; the rules remain the fallback.
    'nl_timeout_seconds' => 2,
    // How long Ollama keeps a model in memory after use; ai:warm reloads it before it expires.
    'ollama_keep_alive' => '30m',
    // Read the filters out of a typed question (rules always; the local Ollama model first when it answers).
    'nl_enabled' => true,
    'nl_cache_seconds' => 60,
    'cloud_enabled' => false,
];
