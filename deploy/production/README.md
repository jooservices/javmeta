# XCrawler native production kit

Only an operator with approved administrator access runs the commands below. This kit does not deploy from CI. Target: Ubuntu **24.04 LTS or newer**, hostname `xcrawler`, LAN `192.168.10.20`, HTTPS `xcrawler.net` and `cc.xcrawler.net`. No Docker is installed on the node. Existing staging names and defaults stay unchanged.

## 1. Prerequisites — operator

Choose an approved, released **annotated** `vX.Y.Z` tag on `origin/master` after green release CI. Put the repository on the node at `/opt/xcrawler-source`, owned by `xcrawler` after installation; authenticate Git as the approved deployment identity. The source clone is separate from immutable releases. Define these operator variables (choose the real approved tag):

```bash
SOURCE=/opt/xcrawler-source
ROOT=/var/www/xcrawler
read -r -p 'Approved annotated release tag: ' TAG
# From an operator-owned parent directory, with GitHub CLI authenticated:
gh repo clone jooservices/javmeta "$SOURCE" -- --branch master
```

Have VM administrator credentials, a tested backup/restore procedure, the Elasticsearch issuing CA, DNS control and the existing gatekeeper HTTPS frontend/certificate layout. Never pass passwords in command arguments or enable `set -x`. Firewall data ports to `192.168.10.20` and administrator addresses only; allow node HTTP/80 only from gatekeeper `192.168.10.4` and exporter/9100 only from Prometheus `192.168.10.10`. Do not flush shared Redis. Decide backup destination/retention, restore-rehearsal target, initial owner, Telegram bot/chat and the Sentry team. The deploy command needs narrow `sudo` rights installed by the node script; it runs as `xcrawler`, never root.

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

**Mongo (`192.168.10.13:27017`)** — run `mongosh --host 127.0.0.1 --username <admin-user> --authenticationDatabase admin provision/mongodb.js`; mongosh prompts for the admin password and the script prompts for the new user's password (or accepts `XCRAWLER_MONGO_PASSWORD`). It creates/updates `xcrawler` with readWrite only on database `xcrawler`. Fill the private `MONGO_URI` as `mongodb://xcrawler:<percent-encoded-password>@192.168.10.13:27017/xcrawler?authSource=xcrawler`, adding TLS options required by the VM; never commit that URI.

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

The installer is re-runnable, preserves `.env`, creates the service user, PHP 8.5 CLI/FPM (Ondrej PPA), Composer with installer checksum, Node 24 LTS (NodeSource), PG18 client, nginx and native Python venv. PHP includes MongoDB, Redis, pgsql and pcntl. Review repository signing keys and package availability for the chosen LTS before running. It installs and enables units but does not start application units until a release exists. Python dependencies come from `embedder/requirements.production.txt`; the venv and model cache live under `shared/`. Before releases changing those requirements, update the venv under maintenance and retain a copy for rollback. No Playwright browser install is needed for the remote fetch service.

Fill every empty secret needed by the selected integrations in the private env file using [env.production.example](env.production.example). Generate `APP_KEY` privately before first boot; keep the same value across releases and backups (encrypted Settings rely on it). Provision it through the approved secret manager without printing it. `CRAWLERX_PLAYWRIGHT_SCRIPT` uses `$ROOT/current/vendor/jooservices/crawlerx/scripts/playwright-fetch.mjs`; adjust it if the release root differs. Do not copy staging `.env` or Mongo settings.

On the **gatekeeper**, merge [haproxy/xcrawler.cfg](haproxy/xcrawler.cfg) into its existing HTTPS frontend/backend structure, keeping existing domains. Create DNS records for both hosts. Follow its existing Certbot/Let's Encrypt HTTP-01 route: exempt `/.well-known/acme-challenge/` from redirects/denies on port 80, request both names, and rebuild the HAProxy PEM using the existing certificate-renewal hook. Confirm both SANs, `haproxy -c -f /etc/haproxy/haproxy.cfg`, then reload under the operator's change approval. Never replace the whole HAProxy config with this snippet. UI host hides ops/Horizon; CC allows only `/`, `/ops`, `/horizon`, `/vendor/horizon`, `/login`, `/logout` and their subpaths. Host-only session cookies require separate logins. Node nginx listens on port 80; HTTPS terminates on gatekeeper. Do not expose node port 80 publicly.

The node exporter installation is `apt-get install -y prometheus-node-exporter` (included in installer). Add `192.168.10.20:9100` to Prometheus on `.10`, firewall it to that source, and verify the `up` metric.

## 4. First deploy — operator invokes `deploy/production.sh`

Take a fresh **custom-format** dump of database `xcrawler` using PG18 `pg_dump -Fc` authenticated as its owner, restore it into an isolated operator-approved rehearsal database, and confirm restored tables/data. Set `XCRAWLER_BACKUP_FILE` to that dump (nonempty, less than 60 minutes old), and `XCRAWLER_BACKUP_RESTORE_VERIFIED=1` only after that rehearsal. The script checks freshness and `pg_restore --list`; the flag is the operator's attestation of a real restore, not an automatic restore proof. Backup and protect MongoDB too: Settings and secrets are there.

```bash
sudo -u xcrawler install -d -m 0700 "$ROOT/shared/backups"
BACKUP="$ROOT/shared/backups/xcrawler-$(date -u +%Y%m%dT%H%M%SZ).dump"
sudo -u xcrawler pg_dump -h 192.168.10.12 -U xcrawler -d xcrawler --password -Fc -f "$BACKUP"
# DBA: restore into an already created, EMPTY rehearsal database; never the app database.
read -r -p 'Rehearsal host: ' REHEARSAL_HOST
read -r -p 'Rehearsal admin user: ' REHEARSAL_USER
read -r -p 'Empty rehearsal database: ' REHEARSAL_DB
test "$REHEARSAL_DB" != xcrawler
sudo -u xcrawler pg_restore --exit-on-error --no-owner --password -h "$REHEARSAL_HOST" -U "$REHEARSAL_USER" -d "$REHEARSAL_DB" "$BACKUP"
# Inspect restored schema and representative application rows before attesting.
sudo -u xcrawler -H "$SOURCE/deploy/production.sh" "$TAG"  # dry-run, cached refs only
sudo -u xcrawler -H env XCRAWLER_BACKUP_FILE="$BACKUP" XCRAWLER_BACKUP_RESTORE_VERIFIED=1 \
  "$SOURCE/deploy/production.sh" "$TAG" --apply
```

Before a dry run on a newly cloned source, fetch the approved tag and `master` as its owner: `git fetch origin master --tags`. Both modes verify the published stable GitHub release with `gh` (read-only). Apply fetches the exact tag plus `origin/master`, rejects dirty source/branches/lightweight tags/tags outside master, installs no-dev authoritative dependencies in `releases/<tag>`, links shared storage/env, runs UI smoke, stops queue/scheduler, migrates **forward**, imports missing settings, clears non-cache bootstrap files, atomically swaps `current`, restarts FPM/embedder/Horizon then scheduler, and waits at most 90 seconds for `staging:readiness`. It never merges or creates a tag. Separate source Git authentication from the application role/credentials.

An empty installation cannot pass readiness until owner, API keys, alert channel, sources, movie ingestion and backup manifest exist. **For the first bootstrap only**, keep public traffic disabled and explicitly set `JAVMETA_SKIP_READINESS=1` on the apply invocation (logs BYPASSED), then complete bootstrap below before allowing traffic. `JAVMETA_SKIP_UI_SMOKE=1` is the same emergency-only convention as staging; never use it routinely. No bypass skips backup validation or migrations. For normal deploys both gates must pass.

From `current`, as `xcrawler`, run `php artisan operations:make-super-admin <owner-email>` and deliver its generated one-time password directly to a private secret-manager input (the existing command prints it; do not record the terminal); never use local/testing seeded credentials in production. Create scoped API keys in Quản trị. Run `php artisan crawler:sync-sources`, configure only approved sources, then `php artisan crawler:seed --limit=20`. Store the real backup result timestamp at `shared/storage/app/private/backups/latest.json` using the existing backup manifest format (`created_at` UTC), only after a successful backup. Schedule native PG/Mongo backups through the operator's backup system; staging's Docker backup scripts do not run on this node.

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

For a later operator-approved code rollback, choose an already verified tag; with ingress paused, run:

```bash
read -r -p 'Previous verified release tag: ' PREVIOUS_TAG
test -d "$ROOT/releases/$PREVIOUS_TAG"
sudo systemctl stop xcrawler-scheduler xcrawler-horizon
sudo -u xcrawler ln -s "$ROOT/releases/$PREVIOUS_TAG" "$ROOT/.rollback"
sudo -u xcrawler mv -Tf "$ROOT/.rollback" "$ROOT/current"
sudo systemctl restart php8.5-fpm xcrawler-embedder xcrawler-horizon xcrawler-scheduler
cd "$ROOT/current" && sudo -u xcrawler php8.5 artisan staging:readiness
```

 Keep the previous venv when requirements change. Retain failed release directories for investigation; remove only the known failed tag directory before retrying it. Never reset a served release or automatically delete old releases/shared storage.

## Verification scope

CI commands: `bash tests/Shell/production.test.sh`; `COMPOSE_PROJECT_NAME=javmeta-int-jooxcr-278 ./docker/ci/integration --filter ProductionProvisioningTest`. The latter applies PG provisioning twice, proves current/future SELECT and denied writes, applies Redis ACL twice and runs the unchanged `RedisQueueTest` under the production user/prefix, and provisions ES security/mappings twice then indexes/searches using the scoped API key, including denied unrelated-index/security access. Use only disposable CI service data for these tests.

### Verified only by reading

Native apt/PPA availability on the chosen Ubuntu release, nginx/FPM/systemd startup, the model download, real VM authentication/TLS, OpenObserve/Sentry UI steps, DNS/ACME/HAProxy routing, firewall policy, backup scheduling/restore and LAN throughput need operator verification. No production VM or `192.168.10.x` host was contacted for this task. `docs/03-operations/domains.md` is absent on this base; the existing user reference is `docs/user-guide/domains.md` and defaults are in `config/javmeta_domains.php`. The HAProxy snippet follows those host roles; it is not a copy of an observed live gatekeeper configuration.

Package references: [NodeSource Node 24 repository](https://github.com/nodesource/distributions/blob/master/scripts/deb/setup_24.x), [PostgreSQL psql](https://www.postgresql.org/docs/current/app-psql.html), [Elastic API-key role intersection](https://www.elastic.co/docs/api/doc/elasticsearch/operation/operation-security-create-api-key). Operator must review package sources before installation.
