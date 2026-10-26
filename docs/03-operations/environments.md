# Environments: staging and UAT

Two environments share the WSL workstation and the shared data services. Every name that could make one touch the other differs, and `deploy/environments.sh` is the single place that lists them (`bash deploy/environments.sh` prints the table; a test proves the names differ).

| | staging | uat |
|---|---|---|
| Purpose | the live crawl, continuously | UI and API tests on the sample dataset; may be reset at any time |
| Clone | `~/Workspaces/JOOservices/projects/javmeta` | `~/Workspaces/JOOservices/projects/javmeta-uat` |
| PostgreSQL | `javmeta` | `javmeta_uat` |
| MongoDB | `javmeta` | `javmeta_uat` |
| Elasticsearch prefix | `javmeta` | `javmeta_uat` |
| Redis prefix | `javmeta_` | `javmeta_uat_` |
| Web port and host | 18485, jav.soulevil.com | 18486, jav-uat.soulevil.com |
| systemd units | `javmeta-horizon`, `javmeta-scheduler`, `javmeta-backup.timer` | `javmeta-uat-horizon`, `javmeta-uat-scheduler` |

## Deploy

```bash
deploy/staging.sh              # staging (the default)
deploy/staging.sh uat          # UAT: same steps, its own clone, database and units
deploy/staging.sh uat --dry-run
```

## Reset UAT

```bash
deploy/uat-reset.sh uat            # empties the UAT database, then loads the sample dataset with the search index
deploy/uat-reset.sh uat --dry-run  # prints what it would do
```

The reset refuses any target but `uat`, missing/unreachable app directories and overrides outside the canonical approved UAT clone. Before stopping services, even in dry-run mode, it asks that clone through the same PHP runtime to run the read-only `ops:uat-targets` check. The check requires effective environment `uat`, PostgreSQL driver/host/port/database `pgsql` / `postgres` / `5432` / `javmeta_uat`, Mongo database `javmeta_uat` in both the Laravel connection and archive configuration, and ES indices `javmeta_uat_movies` / `javmeta_uat_performers`. Resolved database URLs and cached configuration are checked, so staging configuration with UAT shell variables is refused before any side effect. Cached config is accepted only when these effective targets and its environment match. The dataset load uses `--force`; see `sample-dataset.md`.

## Roll back

Both targets take a quick PostgreSQL backup before they pull, return to the previous commit when a step fails and restore the backup when the migration failed (see `deployment.md`).

## Setting UAT up the first time (not done yet)

The scripts and names exist; the UAT instance itself must be provisioned once: clone the repository to the UAT path, create the databases `javmeta_uat` (PostgreSQL, MongoDB), copy `.env` with the UAT names from the table (keep its ACL), add the web virtual host on port 18486 and the DNS name, then run `deploy/staging.sh uat` and `deploy/uat-reset.sh uat`. Nothing in UAT writes to a staging store and the reverse, because no name is shared.
