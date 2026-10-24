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
    'cloud_enabled' => false,
];
