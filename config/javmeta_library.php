<?php

declare(strict_types=1);

// Defaults only: the values are settings in the database (docs/03-operations/settings.md) and are edited in the UI.
return [
    'enabled' => false,
    'folders' => [],
    'extensions' => ['mp4', 'mkv', 'avi', 'wmv', 'mov', 'ts', 'm2ts', 'flv', 'mpg', 'mpeg', 'webm'],
    'scan_hour' => 3,
    'hash_kilobytes' => 1024,
];
