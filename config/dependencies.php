<?php

declare(strict_types=1);

$matrix = [
    'postgres' => 'hard',
    'redis' => 'hard',
    'elasticsearch' => 'soft',
    'mongo' => 'hard',
    'embedder' => 'soft',
    'observability' => 'soft',
    'sentry' => 'soft',
    'browser' => 'soft',
    'telegram' => 'soft',
    'ollama' => 'soft',
    'qbittorrent' => 'soft',
];
$web = array_replace($matrix, ['elasticsearch' => 'hard']);

return [
    'role' => env('JAVMETA_ROLE', 'api'),
    'roles' => [
        'api' => $web,
        'web' => $web,
        'mcp' => $matrix,
        'scheduler' => $matrix,
        'worker' => $matrix,
    ],
];
