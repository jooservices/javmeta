<?php

declare(strict_types=1);

return [
    'enabled' => true,
    'embedder_enabled' => true,
    'host' => env('ELASTICSEARCH_HOST', 'http://elasticsearch:9200'),
    'username' => (string) env('ELASTICSEARCH_USERNAME', ''),
    'password' => (string) env('ELASTICSEARCH_PASSWORD', ''),
    'api_key' => (string) env('ELASTICSEARCH_API_KEY', ''),
    // Internal ES often uses a self-signed cert; disable verification by default.
    'verify_ssl' => false,
    'embedder_url' => 'http://embedder:8000',
    'embed_dim' => 384,
    'movies_index' => 'javmeta_movies',
    'performers_index' => 'javmeta_performers',
];
