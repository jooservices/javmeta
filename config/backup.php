<?php

declare(strict_types=1);

return [
    'manifest_path' => (string) env('JAVMETA_BACKUP_MANIFEST', storage_path('app/private/backups/latest.json')),
    'stale_after_seconds' => (int) env('JAVMETA_BACKUP_STALE_AFTER_SECONDS', 26 * 60 * 60),
];
