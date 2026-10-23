# Database backup and restore

## Scope and targets

The backup image contains the PostgreSQL 18.6 client, MongoDB Database Tools
100.19.1, mongosh 2.8.1, curl, jq, tar, and age. It is a one-shot utility; it
does not start or manage database services. Connection values and the age
recipient/identity are supplied through private environment files.

<code>deploy/backup/backup.sh</code> writes a dated <code>daily/</code> or <code>weekly/</code> folder.
It creates a PostgreSQL custom-format logical dump, a MongoDB gzip archive,
and an Elasticsearch filesystem snapshot archive. Each artifact is encrypted
with the configured public <code>AGE_RECIPIENT</code> before it is retained. The folder
has a JSON manifest and SHA-256 checksums. The <code>latest.json</code> manifest pointer
drives the backup-age command.

The PostgreSQL WAL/PITR procedure is an operational follow-up to the logical
dump: archive WAL files to the same encrypted backup store and test recovery
with the database operator. This ticket does not configure production hosts.

## Schedule and retention

The sample host crontab is <code>deploy/backup/backup.cron</code>: daily backup at 02:00
UTC and weekly backup at 03:00 UTC Sunday. Edit the env-file path, UID/GID,
backup mount, snapshot mount, and image tag for the target host before
installation. Do not commit the private env file.

Defaults retain seven daily folders and four weekly folders. Retention removes
only timestamp-named folders whose manifest namespace matches
<code>BACKUP_NAMESPACE</code>. Set <code>BACKUP_RETENTION_DAILY</code> and
<code>BACKUP_RETENTION_WEEKLY</code> to positive integers to change those limits.

## Restore guard

Run <code>deploy/backup/restore.sh</code> with an explicit <code>--backup-dir</code> and
<code>--namespace</code>. The command validates that the manifest namespace and target
database/index names match the requested namespace, then verifies each
encrypted artifact checksum before connecting. If a target database or
namespace already contains user data, restore stops unless
<code>--force-namespace=&lt;exact-name&gt;</code> matches <code>--namespace</code>.

The restore process decrypts into a private temporary directory, uses
<code>pg_restore</code>, <code>mongorestore</code>, and the Elasticsearch snapshot restore API,
then removes its temporary plaintext files and snapshot staging path. Keep the
age identity outside the repository and backup store.

## Elasticsearch filesystem repository

Set <code>path.repo</code> on every Elasticsearch node and mount the snapshot directory
at the same path in each node and the backup container. The Elasticsearch
filesystem repository API only accepts locations underneath this static
setting ([Elasticsearch documentation](https://www.elastic.co/docs/reference/elasticsearch/configuration-reference/fs-repository-settings)).
Live full backup, restore, and rehearsal commands check the node settings first
and stop with an explicit JOOS-114 message if the configured path is not allowed.
The Workspaces shared Elasticsearch service currently has no <code>path.repo</code> value
or snapshot mount; JOOS-114 tracks that shared infrastructure prerequisite.
Do not add a repository to shared Elasticsearch until that isolated
<code>javmeta_it*</code> path is provisioned.

## Freshness metric and alert

<code>javmeta:backup-status</code> reads <code>JAVMETA_BACKUP_MANIFEST</code> (default
<code>storage/app/private/backups/latest.json</code>) every minute and publishes
<code>javmeta_backup_last_success_age_seconds</code> through the existing OpenObserve
client. If no manifest exists, the metric uses <code>-1</code> and the command sends a
debounced Telegram alert. When the manifest age exceeds
<code>JAVMETA_BACKUP_STALE_AFTER_SECONDS</code> (default 93,600 seconds), it publishes the
age and sends the existing debounced Telegram alert.

The target RPO is 24 hours. RTO remains unverified until a successful rehearsal
can record the full restore duration and per-service counts. The shared
Elasticsearch filesystem snapshot path tracked by JOOS-114 is a prerequisite.

## Test rehearsal

Build the backup image, provide an age test identity and the shared-service variables for
<code>javmeta_test</code> or the worktree database <code>javmeta_wt&lt;id&gt;</code> (from <code>docker/dev/shared-run</code>), and run <code>rehearse.sh --namespace=javmeta_test</code>.
The script seeds uniquely named fixtures, encrypts and restores them, compares
PostgreSQL row, MongoDB document, and Elasticsearch index counts, prints a
timing/result table, then removes only those fixtures and its own test backup.
Use Workspaces shared Docker services only; do not start this repository's
database/search compose stack for the rehearsal.

Until JOOS-114 provisions Elasticsearch <code>path.repo</code> and its shared snapshot
mount, the full rehearsal must stop before writing fixtures. PostgreSQL and
MongoDB can be rehearsed with
<code>rehearse.sh --namespace=javmeta_it --postgres-mongo-only</code>; this mode
reports Elasticsearch as pending and cannot publish a partial manifest as the
latest complete backup.


## Staging on WSL (daily backup, no extra container)

`deploy/backup/staging-backup.sh` writes a custom-format `pg_dump` of the staging database to
`Workspaces/.tmp/javmeta-backups/daily/<UTC time>/`, keeps the newest 7 and publishes the manifest
`storage/app/private/backups/latest.json` that `javmeta:backup-status` reads. The systemd user timer
`javmeta-backup.timer` (installed by `deploy/staging.sh`) runs it every day at 02:30 local time.
MongoDB (raw page archive; the shared Mongo image has no dump tool) and Elasticsearch (rebuildable from PostgreSQL) are
listed as `pending` in the manifest. `deploy/backup/staging-restore-check.sh` restores the newest dump into the scratch
database `javmeta_restore_check`, counts tables and rows and drops it. `javmeta:backup-status --lenient` (the scheduled
form) publishes the age metric and raises the alert without making the scheduler log an error every minute.
