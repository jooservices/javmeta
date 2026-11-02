<?php

declare(strict_types=1);

/*
| Scheduled logical database backups run by `javmeta:backup-run` (the app scheduler on the main node only). They write a
| PostgreSQL custom-format dump and a MongoDB gzip archive into `logical_dir`, refresh the manifest that
| `javmeta:backup-status` reads, and keep the newest `logical_keep` runs. These are database settings edited on the
| Settings page; the values here are only defaults (no env()).
*/
return [
    // Nothing runs until the owner turns this on. The virtual Synology job remains the real backup in the meantime.
    'logical_enabled' => false,
    // How often a scheduled run is due, measured against the manifest's created_at timestamp.
    'logical_interval_hours' => 24,
    // Folder for the dated dump directories and the manifest. Never print paths or contents.
    'logical_dir' => storage_path('app/private/backups'),
    // Number of newest dump directories to keep; older ones are removed after a successful run.
    'logical_keep' => 7,
    // When false, `javmeta:backup-status` still publishes the age metric but sends no manifest alert, and readiness
    // reports backup as skipped instead of FAIL.
    'manifest_alert_enabled' => true,
];
