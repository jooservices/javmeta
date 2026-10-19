<?php

declare(strict_types=1);

return [
    'host' => env('MONGO_HOST', 'mongo'),
    'port' => (int) env('MONGO_PORT', 27017),
    'database' => env('MONGODB_DATABASE', env('MONGO_DATABASE', 'javmeta_archive')),
    'uri' => env('MONGODB_URI') ?: env('MONGO_URI'),
];
