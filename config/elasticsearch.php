<?php

declare(strict_types=1);

return [
    'host' => env('ELASTICSEARCH_HOST', 'http://elasticsearch:9200'),
    // Internal ES often uses a self-signed cert; disable verification by default.
    'verify_ssl' => (bool) env('ELASTICSEARCH_VERIFY_SSL', false),
    'embedder_url' => env('EMBEDDER_URL', 'http://embedder:8000'),
    'embed_dim' => (int) env('EMBEDDER_DIM', 384),
    'movies_index' => env('ELASTICSEARCH_MOVIES_INDEX', 'javmeta_movies'),
    'performers_index' => env('ELASTICSEARCH_PERFORMERS_INDEX', 'javmeta_performers'),
];
