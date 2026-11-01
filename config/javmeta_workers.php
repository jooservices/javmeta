<?php

declare(strict_types=1);

return [
    // Node identity is needed before the database is available and remains a bootstrap value.
    'role' => env('JAVMETA_NODE_ROLE', 'main'),
    'reconcile_cooldown_seconds' => 600,
    'reconcile_jitter_seconds' => 300,
];
