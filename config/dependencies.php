<?php

declare(strict_types=1);

$matrix = [
    'postgres' => 'hard',
    'redis' => 'hard',
    'elasticsearch' => 'soft',
    'mongo' => 'soft',
    'embedder' => 'soft',
    'observability' => 'soft',
];

return [
    'role' => env('JAVMETA_ROLE', 'api'),
    'roles' => [
        'api' => $matrix,
        'mcp' => $matrix,
        'scheduler' => $matrix,
        'worker' => $matrix,
    ],
];
