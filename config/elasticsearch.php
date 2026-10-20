<?php

declare(strict_types=1);

return [
    // Switches: JAVMETA_SEARCH_ENABLED=false skips indexing into Elasticsearch when a movie or performer is saved;
    // JAVMETA_EMBEDDER_ENABLED=false skips the embedding call (documents are indexed without vectors).
    'enabled' => (bool) env('JAVMETA_SEARCH_ENABLED', true),
    'embedder_enabled' => (bool) env('JAVMETA_EMBEDDER_ENABLED', true),
    'host' => env('ELASTICSEARCH_HOST', 'http://elasticsearch:9200'),
    'username' => (string) env('ELASTICSEARCH_USERNAME', ''),
    'password' => (string) env('ELASTICSEARCH_PASSWORD', ''),
    'api_key' => (string) env('ELASTICSEARCH_API_KEY', ''),
    // Internal ES often uses a self-signed cert; disable verification by default.
    'verify_ssl' => (bool) env('ELASTICSEARCH_VERIFY_SSL', false),
    'embedder_url' => env('EMBEDDER_URL', 'http://embedder:8000'),
    'embed_dim' => (int) env('EMBEDDER_DIM', 384),
    'movies_index' => env('ELASTICSEARCH_MOVIES_INDEX', 'javmeta_movies'),
    'performers_index' => env('ELASTICSEARCH_PERFORMERS_INDEX', 'javmeta_performers'),
];
