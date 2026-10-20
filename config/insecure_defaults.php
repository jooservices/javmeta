<?php

declare(strict_types=1);

/**
 * Passwords that appeared in sample files and must never be used outside local development.
 * With APP_ENV staging or production the application refuses to boot when one of the listed settings holds one of them.
 */
return [
    'environments' => ['staging', 'production'],
    'settings' => ['DB_PASSWORD', 'OPENOBSERVE_PASSWORD', 'ZO_ROOT_USER_PASSWORD', 'REDIS_PASSWORD'],
    'values' => [
        'DB_PASSWORD' => env('DB_PASSWORD'),
        'OPENOBSERVE_PASSWORD' => env('OPENOBSERVE_PASSWORD'),
        'ZO_ROOT_USER_PASSWORD' => env('ZO_ROOT_USER_PASSWORD'),
        'REDIS_PASSWORD' => env('REDIS_PASSWORD'),
    ],
    'known_defaults' => ['jvmeta', 'Complexpass#123', 'local-Observe-2026!', 'Integration#123', 'change-me-before-use'],
];
