<?php

declare(strict_types=1);

return [
    'host' => env('MONGO_HOST', 'mongo'),
    'port' => (int) env('MONGO_PORT', 27017),
    'database' => env('MONGO_DATABASE', 'javmeta_archive'),
    'uri' => env('MONGO_URI'),
];
