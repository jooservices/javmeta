<?php

declare(strict_types=1);

return [
    // The response cache is deliberately pinned to Redis even when another cache store is used by the app.
    'store' => env('APP_ENV') === 'testing' ? 'array' : 'redis',
    'enabled' => true,
    'defaults' => [
        'lists' => 5,
        'facets' => 30,
        'catalog' => 1_800,
        'suggestions' => 10,
        'status' => 5,
        'movie_detail' => 60,
    ],
    'ttl' => [
        'lists' => 5,
        'facets' => 30,
        'catalog' => 1_800,
        'suggestions' => 10,
        'status' => 5,
        'movie_detail' => 60,
    ],
    'stale_seconds' => 10,
    'max_entry_bytes' => 131_072,
    'max_query_bytes' => 2_048,
    'slots_per_class' => 64,
    'lock_seconds' => 20,
    'lock_wait_milliseconds' => 750,
    'epoch_ttl_seconds' => 31_536_000,
    'metrics_ttl_seconds' => 7_776_000,
];
