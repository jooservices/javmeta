# XCrawler native production kit

Only an operator with approved administrator access runs the commands below. This kit does not deploy from CI. Target: Ubuntu **24.04 LTS or newer**, hostname `xcrawler`, LAN `192.168.10.20`, HTTPS `xcrawler.net` and `cc.xcrawler.net`. No Docker is installed on the node. Existing staging names and defaults stay unchanged.

## 1. Prerequisites — operator

Production deploys directly from `origin/develop` after green CI for the exact commit. Staging and production both run `develop`; the production node is `192.168.10.20`. Stop crawling on staging at cutover to avoid double crawling. There is no master release or version tag prerequisite. Put the source clone at `/opt/xcrawler-source`, owned by `xcrawler` after installation, separate from immutable deployment directories.

```bash
SOURCE=/opt/xcrawler-source
ROOT=/var/www/xcrawler
# From an operator-owned parent directory, with GitHub CLI authenticated:
gh repo clone jooservices/javmeta "$SOURCE" -- --branch develop
```

Have VM administrator credentials, a tested backup/restore procedure, the Elasticsearch issuing CA, DNS control and the existing gatekeeper HTTPS frontend/certificate layout. Never pass passwords in command arguments or enable `set -x`. Firewall data ports to `192.168.10.20` and administrator addresses only; allow node HTTP/80 only from gatekeeper `192.168.10.4` and exporter/9100 only from Prometheus `192.168.10.10`. Do not flush shared Redis. Review the 14-day local backup retention, arrange an approved encrypted off-node copy, select an empty restore-rehearsal database, and decide the initial owner, Telegram bot/chat and Sentry team. The deploy command needs narrow `sudo` rights installed by the node script; it runs as `xcrawler`, never root.

## 2. Provision data VMs — operator with administrator credentials

Copy only `deploy/production/provision/` to an operator directory on each relevant VM. These scripts have no implicit LAN target: commands run locally on their VM. Use each VM's approved TLS/authentication options and private secret store.

**PostgreSQL 18 (`192.168.10.12:5432`)** — roles/database are fixed, no passwords have defaults. Run connected to `postgres` as its administrator:

```bash
read -rs -p 'App password: ' XCRAWLER_PASSWORD; echo
read -rs -p 'Read-only password: ' XCRAWLER_RO_PASSWORD; echo
export XCRAWLER_PASSWORD XCRAWLER_RO_PASSWORD
psql -X -h 127.0.0.1 -U postgres -d postgres -f provision/postgres.sql
unset XCRAWLER_PASSWORD XCRAWLER_RO_PASSWORD
```

The application role owns `xcrawler`; run all migrations as `xcrawler` so its default privileges cover future tables/sequences. `xcrawler_ro` has SELECT, not writes, including if a client disables its read-only transaction default. `pg_trgm` is the only extension declared by current migrations. Re-running reconciles role passwords/grants, without dropping objects. Existing objects created by another owner must be explicitly reviewed/transferred by the DBA; this script does not seize unrelated objects. Configure `pg_hba.conf` for these roles and this database only.

The production PostgreSQL template sets `DB_SSLMODE=require` (read by `config/database.php`): connections fail rather than falling back to plaintext. This encrypts transport; it does not authenticate the server certificate.

**Mongo (`192.168.10.13:27017`)** — user `xcrawler` already exists, authenticating against database `xcrawler` with readWrite on `xcrawler`. Fill the private `MONGO_URI` with the percent-encoded password and any VM-required TLS options. The template uses the keys read by `config/database.php`; username/password/authSource belong in the URI, not separate unused environment keys. The administrator-only `provision/mongodb.js` is for explicitly approved credential reconciliation; it is not required for normal deployment.

**Redis (`192.168.10.14:6379`)** — authenticate as admin without argv secrets:

```bash
read -rs -p 'Redis admin password: ' REDISCLI_AUTH; echo
export REDISCLI_AUTH
REDIS_HOST=127.0.0.1 bash provision/redis-acl.sh
# Only if the VM uses an aclfile (otherwise persist via its approved config-management workflow):
redis-cli --no-auth-warning -h 127.0.0.1 ACL SAVE
unset REDISCLI_AUTH
```

The script asks for the app password and resets only user `xcrawler`. Prefix `xcrawler:` applies to queue, cache, sessions, Horizon and pub/sub. It permits Lua, transactions, pipelines, KEYS and SCAN; denies admin/destructive commands including FLUSHDB/FLUSHALL/CONFIG/DEBUG. Redis ACL key patterns restrict key access, **not the names returned by KEYS/SCAN**; do not expose Redis to untrusted clients. Never run Laravel `cache:clear` with this user (it uses FLUSHDB).

**Elasticsearch (`192.168.10.15:9200`, HTTPS)** — install its CA in the operator trust store, do not disable TLS verification. Set `ELASTICSEARCH_HOST=https://192.168.10.15:9200`, `ES_ADMIN_USER` to the VM administrator and `ES_KEY_FILE` to a private absolute path **outside the checkout**. Run `bash provision/elasticsearch.sh`; it prompts for admin password. Copy the resulting encoded key privately into `ELASTICSEARCH_API_KEY`, then secure/delete the delivery file according to the secret-manager policy. Never print it. On reruns retain that private file to avoid key rotation; a lost file with an active named key fails safely and requires explicit operator recovery/invalidation.

The role and API key are named `xcrawler`: indices only `xcrawler_*`, index privileges read/write/create_index/manage; cluster `monitor` supports health probes, no security administration. Mappings match `EsSetupCommand` and `SceneSearchService`: E5 `intfloat/multilingual-e5-small` produces **384** dimensions. The three indices are `xcrawler_movies`, `xcrawler_performers`, `xcrawler_scenes`. Reruns update mappings and reconcile retained API-key privileges without deleting data or rotating the secret; a dimension mismatch fails rather than dropping an index.

**OpenObserve/Sentry** — follow [openobserve.md](provision/openobserve.md) and [sentry.md](provision/sentry.md). Do not modify the shared browser services (`192.168.10.19:8191/v1`, `:3000`) or Ollama bridge (`192.168.10.4:11434`).

## 3. Prepare node and gatekeeper — operator as root

On the node, from the approved source checkout:

```bash
sudo bash "$SOURCE/deploy/production/node/install-node.sh"
sudo chown -R xcrawler:xcrawler "$SOURCE"
sudo -u xcrawler -H editor "$ROOT/shared/.env"
```

The installer is re-runnable, preserves `.env`, creates the service user, PHP 8.5 CLI/FPM (Ondrej PPA), Composer with installer checksum, Node 24 LTS (NodeSource), PG18 client, nginx and native Python venv. PHP includes MongoDB, Redis, pgsql and pcntl. Review repository signing keys and package availability for the chosen LTS before running. It installs the backup service/timer and enables the timer; the timer waits for a release before its service can run. Application units do not start until a release exists. Python dependencies come from `embedder/requirements.production.txt`; the venv and model cache live under `shared/`. Before releases changing those requirements, update the venv under maintenance and retain a copy for rollback. No Playwright browser install is needed for the remote fetch service.

Fill every empty secret needed by the selected integrations in the private env file using [env.production.example](env.production.example). Generate `APP_KEY` privately before first boot; keep the same value across releases and backups (encrypted Settings rely on it). Provision it through the approved secret manager without printing it. `CRAWLERX_PLAYWRIGHT_SCRIPT` uses `$ROOT/current/vendor/jooservices/crawlerx/scripts/playwright-fetch.mjs`; adjust it if the release root differs. Do not copy staging `.env` or Mongo settings.

On the **gatekeeper**, merge [haproxy/xcrawler.cfg](haproxy/xcrawler.cfg) into its existing HTTPS frontend/backend structure, keeping existing domains. Create DNS records for both hosts. Follow its existing Certbot/Let's Encrypt HTTP-01 route: exempt `/.well-known/acme-challenge/` from redirects/denies on port 80, request both names, and rebuild the HAProxy PEM using the existing certificate-renewal hook. Confirm both SANs, `haproxy -c -f /etc/haproxy/haproxy.cfg`, then reload under the operator's change approval. Never replace the whole HAProxy config with this snippet. UI host hides ops/Horizon; CC allows only `/`, `/ops`, `/horizon`, `/vendor/horizon`, `/login`, `/logout` and their subpaths. Host-only session cookies require separate logins. Node nginx listens on port 80; HTTPS terminates on gatekeeper. Do not expose node port 80 publicly.

The node exporter installation is `apt-get install -y prometheus-node-exporter` (included in installer). Add `192.168.10.20:9100` to Prometheus on `.10`, firewall it to that source, and verify the `up` metric.

## 4. Deploy — operator invokes `deploy/production.sh`

Before each apply, run the node backup job and restore the PostgreSQL dump into an isolated, empty rehearsal database. The daily timer normally creates the backup at 02:00 UTC; for deployment, run it manually to meet the deploy script's 60-minute freshness check. `xcrawler` needs `CREATEDB` to create the rehearsal database; otherwise the DBA must create an empty one owned by `xcrawler` first. The restore test retains the target for inspection and never drops it. Set `XCRAWLER_BACKUP_FILE` to the dump from the new manifest and set `XCRAWLER_BACKUP_RESTORE_VERIFIED=1` only after the rehearsal succeeds. The deploy script checks freshness and `pg_restore --list`; the flag is the operator's attestation of a real restore. MongoDB is backed up only when `mongodump` is already installed; see the native backup section for its status and limitations.

```bash
sudo -u xcrawler -H "$ROOT/current/deploy/production/backup/xcrawler-backup.sh" \
  --env-file "$ROOT/shared/.env"
BACKUP="$(jq -er .postgres_dump "$ROOT/shared/storage/app/private/backups/latest.json")"
test -s "$BACKUP"
# DBA: create this empty rehearsal database owned by xcrawler when that role lacks CREATEDB.
sudo -u xcrawler -H "$ROOT/current/deploy/production/backup/xcrawler-restore-test.sh" \
  --env-file "$ROOT/shared/.env" --database xcrawler_restore_rehearsal
# Inspect restored schema and representative application rows before attesting.
sudo -u xcrawler -H "$SOURCE/deploy/production.sh"  # dry-run, cached refs only
sudo -u xcrawler -H env XCRAWLER_BACKUP_FILE="$BACKUP" XCRAWLER_BACKUP_RESTORE_VERIFIED=1 \
  "$SOURCE/deploy/production.sh" --apply
```

Before a dry run, refresh the cached branch as its owner: `git fetch origin +refs/heads/develop:refs/remotes/origin/develop`. Default deployment ref is `origin/develop`; optionally pass `--ref <full-commit-sha>` to select an ancestor of `origin/develop`. Other refs and dirty source trees are refused. Apply refreshes `origin/develop` before validating ancestry. Both modes check exact-commit GitHub statuses and check runs. All five CI jobs (`Lint and test`, `Integration (Postgres, Mongo, Elasticsearch, OpenObserve)`, `E2E journeys (isolated services)`, `Secrets scan`, `Build app image`) must be present, completed and successful; unrelated checks cannot satisfy this gate. Pending, failed or absent CI results block deployment. If `gh` is missing or offline, the script prints a warning and requires an explicit `--skip-ci-check`; this bypass also prints a warning and needs operator judgement.

Dry-run is the default and changes no files/services. Apply installs no-dev authoritative dependencies in `releases/<UTC timestamp>-<sha7>`, links shared storage/env, runs UI smoke, stops queue/scheduler, migrates **forward**, imports missing settings, clears non-cache bootstrap files, atomically swaps `current`, restarts FPM/embedder/Horizon then scheduler, and waits at most 90 seconds for `staging:readiness`. Separate source Git authentication from application credentials.

An empty installation cannot pass readiness until owner, API keys, alert channel, sources, movie ingestion and backup manifest exist. **For the first bootstrap only**, keep public traffic disabled and explicitly set `JAVMETA_SKIP_READINESS=1` on the apply invocation (logs BYPASSED), then complete bootstrap below before allowing traffic. `JAVMETA_SKIP_UI_SMOKE=1` is the same emergency-only convention as staging; never use it routinely. No bypass skips backup validation or migrations. For normal deploys both gates must pass.

From `current`, as `xcrawler`, run `php artisan operations:make-super-admin <owner-email>` and deliver its generated one-time password directly to a private secret-manager input (the existing command prints it; do not record the terminal); never use local/testing seeded credentials in production. Create scoped API keys in Quản trị. Run `php artisan crawler:sync-sources`, configure only approved sources, then `php artisan crawler:seed --limit=20`. The native backup timer publishes the UTC `created_at` manifest consumed by readiness; staging's Docker backup scripts do not run on this node.

### Set in the Settings page (exact registry keys)

| Key | Production value / operator action |
|---|---|
| `domains.ui_host` | `xcrawler.net` |
| `domains.command_center_host` | `cc.xcrawler.net` |
| `search.movies_index` | `xcrawler_movies` (imported from env on new DB) |
| `search.performers_index` | `xcrawler_performers` (imported from env on new DB) |
| `media.scenes_index` | `xcrawler_scenes` (must change before any scene job) |
| `search.embedder_url` | `http://127.0.0.1:8000` |
| `search.embedding_dimensions`, `media.embedding_dimensions` | `384` (do not change without rebuilding mappings) |
| `ai.embedding_provider` | `embedder` |
| `ai.ollama_url` | `http://192.168.10.4:11434` |
| `media.api_url` | `https://xcrawler.net` |
| `media.output_folder` | `/var/www/xcrawler/shared/storage/app/private/media/output` before enabling media jobs |
| `observability.openobserve_enabled` | `true` after ingestion proof |
| `telegram.bot_token`, `telegram.chat_id`, `telegram.owner_user_id` | Private operator values; user ID required for groups |
| `queue.workers_per_queue`, `queue.worker_timeout`, `queue.depth_limit`, `queue.browser_inflight`, `queue.per_source_inflight`, `queue.feed_pool_limit` | Start with registry defaults; operator tunes to node/browser capacity |
| `crawl.fresh_interval_minutes`, `crawl.loop_pause_seconds`, `crawl.stale_claim_timeout_seconds`, `crawl.block_delays_seconds` | Registry defaults; tune only in UI |

These are DB settings, **not env keys**. `settings:import-env` does not overwrite saved values: review them after restoring a staging Mongo database. Restart `xcrawler-horizon` (not `javmeta-horizon`) for settings marked restart; unit labels in the common UI still use staging names. No new feature defaults are introduced by this kit.

## 5. Verification — operator

```bash
cd "$ROOT/current"
sudo systemctl is-active php8.5-fpm nginx xcrawler-embedder xcrawler-horizon xcrawler-scheduler
sudo -u xcrawler php8.5 artisan javmeta:smoke --json
sudo -u xcrawler php8.5 artisan staging:readiness
curl --fail http://127.0.0.1:8000/healthz
curl --fail https://xcrawler.net/login
curl --fail https://cc.xcrawler.net/login
```

Require `Staging is READY` (the existing command wording is retained). Check UI login, settings persistence, catalog search, queue completion, CC `/ops` and `/horizon`; UI-host `/ops` and CC `/app` must return 404. Confirm FPM executes PHP 8.5, model warm-up produces 384-dimensional vectors, ES-key cannot create an unrelated index, logs/metrics arrive in the two streams, and Sentry project/environment are correct. Never weaken readiness thresholds to hide missing bootstrap work.

## 6. Rollback — operator

On failure, the deploy script automatically restores the previous symlink and restarts units. On a failed first deploy it removes `current` and stops app units. It **never** rolls the database back automatically. Migrations must be reviewed for old-code compatibility; if incompatible, stop ingress and have the DBA restore the rehearsed PG/Mongo backups under incident approval.

For a later operator-approved code rollback, choose an already verified deployment directory; with ingress paused, run:

```bash
read -r -p 'Previous verified directory (UTC timestamp-sha7): ' PREVIOUS_RELEASE
test -d "$ROOT/releases/$PREVIOUS_RELEASE"
sudo systemctl stop xcrawler-scheduler xcrawler-horizon
sudo -u xcrawler ln -s "$ROOT/releases/$PREVIOUS_RELEASE" "$ROOT/.rollback"
sudo -u xcrawler mv -Tf "$ROOT/.rollback" "$ROOT/current"
sudo systemctl restart php8.5-fpm xcrawler-embedder xcrawler-horizon xcrawler-scheduler
cd "$ROOT/current" && sudo -u xcrawler php8.5 artisan staging:readiness
```

 Keep the previous venv when requirements change. Retain failed release directories for investigation; remove only the known failed deployment directory before retrying it. Never reset a served release or automatically delete old releases/shared storage.

## Verification scope

CI commands: `bash tests/Shell/production.test.sh`; `COMPOSE_PROJECT_NAME=javmeta-int-jooxcr-278 ./docker/ci/integration --filter ProductionProvisioningTest`. The latter applies PG provisioning twice, proves current/future SELECT and denied writes, applies Redis ACL twice and runs the unchanged `RedisQueueTest` under the production user/prefix, and provisions ES security/mappings twice then indexes/searches using the scoped API key, including denied unrelated-index/security access. Use only disposable CI service data for these tests. The ES harness serves HTTP: a test-only curl adapter maps the HTTPS provisioning URL to that disposable endpoint, so this proves provisioning and authorization, not TLS negotiation. Shell regression tests separately prove that the production script rejects HTTP before any request.

### Verified only by reading

Native apt/PPA availability on the chosen Ubuntu release, nginx/FPM/systemd startup, the model download, real VM authentication/TLS, OpenObserve/Sentry UI steps, DNS/ACME/HAProxy routing, firewall policy, backup scheduling/restore and LAN throughput need operator verification. No production VM or `192.168.10.x` host was contacted for this task. `docs/03-operations/domains.md` is absent on this base; the existing user reference is `docs/user-guide/domains.md` and defaults are in `config/javmeta_domains.php`. The HAProxy snippet follows those host roles; it is not a copy of an observed live gatekeeper configuration.

Package references: [NodeSource Node 24 repository](https://github.com/nodesource/distributions/blob/master/scripts/deb/setup_24.x), [PostgreSQL psql](https://www.postgresql.org/docs/current/app-psql.html), [Elastic API-key role intersection](https://www.elastic.co/docs/api/doc/elasticsearch/operation/operation-security-create-api-key). Operator must review package sources before installation.

## Needs owner approval before install

No additional node software is introduced by this follow-up. If `gh` is unavailable, use the explicit warned CI bypass only after operator verification; installing it or any other missing software requires owner approval.

## Native node backups

The node installer registers `xcrawler-backup.timer` (02:00 UTC, up to 30 minutes random delay, persistent catch-up). It runs as `xcrawler`, using the current release and shared env; it does not install backup tools. For an existing node, the operator can install only the two unit files from `node/`, run `systemctl daemon-reload` and `systemctl enable --now xcrawler-backup.timer`; do not rerun package provisioning just to enable backups.

`backup/xcrawler-backup.sh --env-file "$ROOT/shared/.env"` reads Laravel dotenv syntax without executing it. `BACKUP_DIR` defaults to `backups` alongside the env file; `BACKUP_KEEP` defaults to 14 distinct UTC days (positive integer, maximum 9999). Flags `--backup-dir`, `--keep`, `--manifest` override these inputs. No host/database/branch is embedded in script logic. Set `DB_SSLMODE=require` or stronger; credentials are passed to PostgreSQL only through its environment. Tool error text is suppressed to avoid leaking connection secrets. No `jq`, `age` or `mongosh` is needed.

Backups live in private mode-0700 `shared/backups/xcrawler-backup-<day>-<unique>/` bundles. Each contains a verified custom-format PostgreSQL dump and manifest. A lock serializes backup/restore rehearsal. Temporary files and incomplete runs are never selected for restore. Only script-named bundle directories are eligible for retention; unrelated files, symlinks and neighbouring directories are preserved. Dump and readiness manifest publication use rename on their destination filesystem. A failed backup exits nonzero and retains the last successful manifest. Failures are logged to the unit journal; `ExecStopPost` runs `javmeta:backup-status --lenient`, whose existing stale/missing alerts continue to use `backup.stale_after_seconds`. A failed run does not reset backup freshness; alert delivery still requires the application's configured channel.

The manifest is published to `JAVMETA_BACKUP_MANIFEST` (default `storage/app/private/backups/latest.json` alongside the shared env). Keep that path at the shared storage location above so both `staging:readiness` and `javmeta:backup-status` read the same file; the former has a fixed storage path. A fresh `created_at` is written only after PostgreSQL dump verification and any available MongoDB dump succeed. MongoDB is explicitly `skipped (mongodump missing)` when unavailable, with a loud warning: a passing backup freshness check does **not** mean MongoDB is protected. If available, `mongodump` gets its URI through a private temporary YAML config, not argv. Elasticsearch is excluded because it is derived (`php artisan es:setup --reindex`). These dumps are not encrypted: restrict filesystem access and use the owner's approved encrypted off-node backup destination.

### Restore and rehearsal

Run only as an approved operator. The rehearsal target must be named `<app-database>_restore_<purpose>` (for example, `xcrawler_restore_rehearsal`). It creates the target database if absent, refuses other database names and any target containing user relations/functions, and never drops a database. The app role needs CREATE DATABASE permission for an absent target; otherwise have the DBA create an empty rehearsal database owned by the app role first.

```bash
sudo -u xcrawler "$ROOT/current/deploy/production/backup/xcrawler-restore-test.sh" \
  --env-file "$ROOT/shared/.env" --database xcrawler_restore_rehearsal
```

The script verifies the latest completed dump, restores with `--exit-on-error --no-owner --no-privileges`, compares table count with the archive listing and each COPY table's restored row count with the dump's actual COPY records (the TOC alone has no row counts). The target is retained for inspection even on failure; the DBA handles cleanup. Use only trusted backup archives: a PostgreSQL restore executes SQL. For incident recovery, stop ingress/workers, rehearse first, then have the DBA restore the selected verified dump using `pg_restore --exit-on-error --no-owner --no-privileges --dbname=<approved-empty-recovery-db> <bundle>/postgres.dump`, with connection/password supplied privately through the libpq environment. Switch the application to the recovered database only under the owner's incident approval. Keep `APP_KEY` available from the secret manager for encrypted MongoDB Settings; restore MongoDB separately with approved tools.

### Needs owner approval

- Install `mongodb-database-tools` on the node to enable `mongodump`/`mongorestore`. Until approved, MongoDB (including Settings/secrets) is skipped and is not protected by this job. No new software was installed for this task.
- Arrange VM-level backups and restore rehearsals for PostgreSQL and MongoDB data VMs. The node's logical backup does not replace those backups; retain an off-node copy and a secret-manager recovery procedure.
- Operator verification of unit startup, real TLS/authentication, storage capacity and restore permissions remains required. Development tests use stubs/disposable test data; no `192.168.10.x` system was accessed.
