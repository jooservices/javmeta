<?php

declare(strict_types=1);

return [
    'cache_enabled' => env('CONFIG_STORE_CACHE_ENABLED', true),

    'cache_store' => env('CONFIG_STORE_CACHE_STORE'),

    'cache_ttl' => (int) env('CONFIG_STORE_CACHE_TTL', 3600),

    'cache_key' => env('CONFIG_STORE_CACHE_KEY', 'jooservices_config_all'),

    'cache_version_key' => env('CONFIG_STORE_CACHE_VERSION_KEY'),
];
