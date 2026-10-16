# jvmeta to javmeta: persisted namespaces and rollout

Status: plan. Owner decision (PO, 2026-10-16): the repository, package and environment names are renamed to `javmeta`; the database, domain and Docker volume data renames are deferred to the first new deployment (Epic 7). Production is shut down, so the recommended rollout is a **fresh start with the new names** (no live data migration).

## What changed in the code (merged in PR #11)

| Area | Old | New | Effect when an existing environment is upgraded in place |
|---|---|---|---|
| Environment variables | `JVMETA_*` | `JAVMETA_*` | The app reads defaults when the new name is missing. `JAVMETA_OWNER_ADMIN_TOKEN` empty makes every admin route answer 401 (safe). `JAVMETA_QUEUE_*`, `JAVMETA_WORKER*`, `JAVMETA_MCP_API_KEY`, `JAVMETA_ALERTS_*` silently fall back to defaults or to disabled. |
| Config files | `config/jvmeta_*.php` | `config/javmeta_*.php` | None at runtime; cached config must be rebuilt (`config:cache`). |
| Mongo database (default) | `jvmeta_archive` | `javmeta_archive` | A new empty database is used unless `MONGO_DATABASE` is set explicitly. Raw HTML snapshots live 15 days and are not the system of record. |
| Elasticsearch indices (default) | `jvmeta_movies`, `jvmeta_performers` | `javmeta_movies`, `javmeta_performers` | Search returns no hits until the new indices are created and filled from Postgres. Lookup by code is unaffected (Postgres only). |
| OpenObserve streams | `jvmeta_logs`, `jvmeta_metrics` | `javmeta_logs`, `javmeta_metrics` | History is split across two streams; dashboards and alerts that name the old streams or the `jvmeta_*` metric names stop updating. |
| Metric names | `jvmeta_*` (for example `jvmeta_crawl_queue_depth`, `jvmeta_dep_up`) | `javmeta_*` | Same as streams. |
| Cache keys | `jvmeta:alert:*`, `jvmeta:worker:heartbeat`, `jvmeta:worker:instances` | `javmeta:*` | Ephemeral. After the release the watchdog may report a missing heartbeat for about one scheduler tick and alert debouncing restarts, so one duplicate alert is possible. |
| Artisan command names | `jvmeta:watchdog`, `jvmeta:obs-publish-metrics` | `javmeta:*` (the `crawler:*` names are unchanged) | External callers (cron, scripts, runbooks) using the old names fail with "command not found". The in-repo scheduler already uses the new names. |
| Compose volume (production compose) | `jvmeta-storage` | `javmeta-storage` | A new empty volume is mounted at `storage/`. Only logs and framework cache live there. |
| Image and container user | `jvmeta` (uid 10001) | `javmeta` (uid 10001) | Same uid, so existing volume ownership still works. |
| Docker Compose project, image and container names | `jvmeta*` | `javmeta*` | Containers and networks are recreated; local development volumes with the old project prefix are no longer used. |

## Intentionally not renamed (Epic 7, backlog until deployment planning)

- Postgres database, user and password names (`jvmeta`, `jvmeta_it`) and the migration file name `2026_09_18_000001_create_jvmeta_schema.php`.
- Domain `jvmeta.soulevil.com` and its Cloudflare tunnel; Telegram bot naming.
- API key prefix `jvm_` (consumers hold such keys).

## Outside the repository (inventory)

- Host and CI secrets: any `JVMETA_*` variable in shell profiles, MCP client configs and secret stores (for example `JVMETA_MCP_API_KEY`, tracked in JOOS-30).
- Deployment VMs: `git remote set-url origin git@github.com:jooservices/javmeta.git` (GitHub redirects the old URL, but pin the new one).
- Dashboards, alert rules and saved searches in OpenObserve and Sentry that mention `jvmeta`.
- Documentation in the JOOservices docs repository under `docs/01-projects/jvmeta/` (pointer files; rename together with the docs repo).

## Recommended rollout (first new deployment)

1. Start with a new environment file generated from `.env.example` / `deploy/production.env.example`; copy only values, never the old keys. A leftover `JVMETA_*` name is a mistake, so fail fast: the follow-up ticket adds a preflight check that refuses to boot when a legacy variable is present.
2. Create the new data namespaces (Mongo database, Elasticsearch indices via `es:setup`, OpenObserve streams) and fill Elasticsearch from Postgres with the indexing backfill; no data copy is needed.
3. Re-crawl to rebuild the raw archive (retention is 15 days anyway).
4. Recreate dashboards and alerts on the `javmeta_*` metric names; keep the old stream read-only for history until it expires.
5. Cut external callers over to the new command names in the same change that updates the deploy scripts.

## If an existing environment must be kept (not expected)

- Postgres: no change (database name unchanged).
- Elasticsearch: create `javmeta_*` indices with `es:setup`, reindex from Postgres, verify counts, then drop `jvmeta_*` after one release.
- Mongo: `mongodump --db jvmeta_archive` then `mongorestore --nsFrom 'jvmeta_archive.*' --nsTo 'javmeta_archive.*'`, or point `MONGO_DATABASE=jvmeta_archive` explicitly and rename later.
- Volume: `docker run --rm -v jvmeta-storage:/from -v javmeta-storage:/to alpine cp -a /from/. /to/`.
- Keep the old OpenObserve stream until its retention ends.

## Verification checklist

- `php artisan about` and `GET /api/health` are green with only `JAVMETA_*` variables set.
- `GET /api/v1/movies?q=...` returns hits after the indexing backfill.
- No `JVMETA_` token remains in the process environment (`env | grep ^JVMETA_` is empty).
- New dashboards show `javmeta_*` metrics.
