# Deployment runbook

## Current native wizard flow

Use the [native install/deploy guide](../../deploy/production/README.md) for
all new installations and releases. `deploy/install.sh` is interactive;
production/staging wrappers use the same shared engine. Supply repo, ref and
target explicitly. Preview is default; `--apply` executes. Credentials come
from hidden input or a private 0600 answers JSON, never command arguments.

The wizard checks Ubuntu/prerequisites and service authentication, requires
PostgreSQL UTC, tests Redis prefix isolation, writes private shared env, clones
the chosen ref, installs Composer dependencies, migrates, initializes settings,
runs `es:setup` before crawling, syncs sources, creates the owner when there is
no current release, swaps `current` atomically and verifies readiness after
role-specific starts. Failure
restores the previous code/env; database rollback remains an operator decision.
Every step prints its duration. `--skip-backup-check` is a loud explicit bypass.

### Adding a worker node

Select `--role worker` and a distinct node name/target. Horizon, embedder and node exporter
are enabled and restarted. Nginx and scheduler are installed but disabled with
worker-role drop-ins; PHP-FPM is not managed. Workers do not migrate shared data. Keep exactly **one scheduler** in
the fleet (`onOneServer` is absent). `--start-scheduler` is an explicit primary
cutover step; `--no-start` installs without starting services.

Horizon needs `JAVMETA_WORKERS_PER_QUEUE` in env; its database setting alone
cannot change process counts. PostgreSQL's role connection limit **60** and
shared `max_connections` **150** bound fleet capacity. Browser capacity on
vm-tools is global, shared by every crawler node. The native Playwright script
must be under `<root>/current/vendor/jooservices/crawlerx/scripts/`.

For local verification use a disposable shared-service namespace, a local
repository/ref and `--skip-os-install --no-systemd --no-start`. These flags do
not establish running-process readiness. Credentials are written mode 0600 to
the operator-selected file; do not paste them into reports.

## Historical Compose operations

The following sections describe the older Compose deployment and operational
commands. They are retained for existing environments; the scripts now use the
native wizard above and do not infer any branch or target.


Status: source-driven control deployment verified on 2026-10-02. Crawler-node
rollout is pending.

## Deployment model

The current private deployment uses GitHub as the source of truth. The target
VM pulls the requested branch, builds the local Docker runtime, installs the
locked dependencies, runs the migration, and starts the selected Compose
services.

This flow does not transfer application images with `scp`, publish images to a
registry, or store image archives in GitHub. Docker still creates local images
from the repository Dockerfiles and may pull their pinned base images.

Use the standard `git` CLI directly. The repository is public, so this flow
does not require the `gh` CLI for cloning or updating the application.

The existing `docker-compose.yml` remains the deployment Compose file for this
internal flow. `Dockerfile.production`, `compose.production.yml`, and the
production image workflow are preparation for a future immutable-image flow;
they are not used by the scripts below.

## Service roles

The control VM runs:

- `api`
- `scheduler`
- `mcp`
- `embedder`

The control VM must not run `worker` or `flaresolverr`. Those belong to crawler
VMs. PostgreSQL, MongoDB, Elasticsearch, and OpenObserve remain external and
are configured through the private `.env` file.

Only one control VM runs `scheduler`; starting multiple schedulers duplicates
scheduled work and alerts.

## Worker runtime

Each configured queue worker runs with a 180-second timeout by default. The
database queue `retry_after` default is 240 seconds and must remain greater
than the worker timeout so a running job is not reserved by another worker.

The worker supervisor tracks each `(queue, slot)` process independently. A
worker that exits, including normal `--max-time` or `--max-jobs` rotation, is
restarted by itself with exponential backoff capped at 30 seconds. `SIGTERM`
and `SIGINT` are forwarded to all children; the supervisor waits up to the
stop grace period before killing remaining children and exits successfully.

When a queued crawl row is missing or is no longer `claimed`, the job skips the
fetch without changing the row and emits the `crawl_job_skipped` `ops` event.

The relevant environment variables are `JAVMETA_QUEUE_TIMEOUT` (default `180`),
`DB_QUEUE_RETRY_AFTER` (default `240`), and `JAVMETA_QUEUE_STOP_GRACE` (default
`200`). `JAVMETA_PHP_BIN` is available for shell-test replacement of the PHP
binary and defaults to `php`.

## Scheduled crawler commands

The scheduler runs these commands:

- `crawler:sync-sources` every 15 minutes. It updates the description of each source (name, URL, gaps, priority) but never the on/off switch of an existing source.
- `crawler:reclaim` and `crawler:seed` every minute.
- `crawler:feed-pool` every 10 seconds by default with overlapping runs prevented; `queue.feed_interval_seconds` can select 5, 10, 15, 30 or 60 seconds. The single scheduler process uses `schedule:work` for sub-minute ticks.
- `crawler:watchdog` every five minutes.
- `obs:publish-metrics` every minute.

Use the Makefile target for operator-triggered crawl work. `ACTION=tick` runs
sync-sources, reclaim, and seed in that order; `SITE` and `LIMIT` are passed
only to seed. `ACTION=dispatch` runs feed-pool. `ACTION=source` runs
run-source with dispatch enabled after seeding.

```bash
make crawl ACTION=tick SITE=example LIMIT=50
make crawl ACTION=dispatch LIMIT=50
make crawl ACTION=source SITE=example LIMIT=20
```

### Deprecated command compatibility

`crawl:tick {--source=} {--limit=50}` remains as a deprecated compatibility
command. It prints a deprecation notice, then runs `crawler:sync-sources`,
`crawler:reclaim`, and `crawler:seed` in that order. Only seed receives the
source and limit options. Update external callers to the Makefile target or
the new command names.

These previous command names remain available as deprecated aliases while
callers migrate:

- `crawl:dispatch` → `crawler:feed-pool`
- `crawl:source` → `crawler:run-source`
- `javmeta:watchdog` → `crawler:watchdog`
- `javmeta:obs-publish-metrics` → `obs:publish-metrics`

## New instance versus upgrade

The scripts support both deployment cases without renaming the application
directory:

- **New instance:** when `JAVMETA_APP_DIR` does not exist, the script runs
  `git clone --branch ... --single-branch` into that path. Restore the private
  `.env` through `JAVMETA_ENV_SOURCE` before starting services.
- **Existing instance upgrade:** when the checkout already exists, keep the
  same directory and branch. The script requires a clean worktree and runs
  `git pull --ff-only origin <branch>`. The existing `.env` stays in place
  unless `JAVMETA_ENV_SOURCE` is explicitly supplied to restore a verified
  backup.

Do not rename or delete `/home/joos/javmeta` during a normal upgrade. Record the
current Git SHA before pulling so an approved rollback can return to the
previous revision.

## Preconditions

The target VM must have:

- Git with network access to the public GitHub repository. No `gh` CLI is
  required for this deployment flow.
- Docker Engine enabled at boot.
- Docker Compose v2.
- The `joos` user allowed to run Docker.
- Network access to GitHub, the external dependencies, and Python package/model
  sources required by the embedder build.
- A private runtime `.env` file containing the external service configuration.

The current Compose file declares these sibling package bind mounts for local
compatibility:

```text
../client
../dto
../exceptions
../laravel-controller
../laravel-repository
```

The deployment dependency source is Packagist, fixed by `composer.lock`; the
deployment scripts do not clone sibling package repositories. Keep this
Compose compatibility detail separate from Composer dependency installation.

## Deployment scripts

Both scripts clone the application when `JAVMETA_APP_DIR` does not exist, or
perform a fast-forward-only pull when it is already a clean checkout. They
fail on a dirty checkout, a branch mismatch, a missing `.env`, a failed build,
or a failed command. They never print the `.env` contents.

### Laravel control deployment

`deploy/laravel.sh` performs:

1. Clone or pull the configured GitHub branch.
2. Validate that control mode disables the FlareSolverr requirement.
3. Build the PHP Docker runtime locally.
4. Run `composer install --no-dev` inside the PHP container.
5. Run `php artisan migrate --force` once.
6. Run `php artisan optimize:clear`.
7. Recreate `api` and `mcp`.

The script intentionally leaves `scheduler` stopped until the control services
have been verified.

After the API, scheduler, workers, and their dependencies are running, verify
the deployment from the application directory:

```bash
php artisan javmeta:smoke
php artisan javmeta:smoke --json
```

The command checks the configured databases and service probes, Horizon,
per-queue backlog limits, a temporary-key API lookup and MCP initialize, the
scheduler heartbeat, and enabled observability connections. Disabled
Elasticsearch, embedder, Sentry, and OpenObserve integrations are reported as
skipped. A failed required check returns exit code 1. The scheduler records a
heartbeat every minute; the check treats one as stale after 180 seconds. A
temporary API key is revoked after the API checks. `APP_URL` must be reachable
from the command process. When Sentry is enabled, the command sends one
informational smoke-check event to verify delivery.

### Python embedder deployment

`deploy/python.sh` performs:

1. Clone or pull the configured GitHub branch.
2. Build the embedder Docker runtime locally. The Docker build runs `pip
   install` from `embedder/requirements.txt`.
3. Recreate `embedder`.
4. Wait for `/healthz`.
5. Send a real `/v1/embeddings` request to download/warm the model and verify
   vector generation.

Python dependencies are installed during the Docker build, not on every
container start.

### Crawler deployment

`deploy/crawler.sh` is the crawler-only flow. It:

1. Clones or fast-forward pulls the configured Git branch and preserves the
   existing `.env`.
2. Requires `CRAWLERX_FLARESOLVERR_REQUIRED=true` and an `EMBEDDER_URL` that
   points to the control embedder rather than the local `embedder` service.
3. Builds the local PHP runtime, runs locked Composer installation, and runs
   `php artisan optimize:clear`.
4. Starts and verifies `flaresolverr` before starting the worker containers.
5. Starts the requested number of workers, verifies the control embedder from
   the worker network, and waits for them to remain running.

The script does not run migrations and does not start `api`, `mcp`,
`scheduler`, or `embedder`. Migrations are a control-side, once-per-release
operation. It also fails when existing worker containers are still running;
drain and stop them before an upgrade so an active crawl is not interrupted.

Run it from the crawler checkout:

```bash
/home/joos/javmeta/deploy/crawler.sh
```

The safe default is one worker. Override it only after reviewing queue load:

```bash
JAVMETA_WORKER_INSTANCES=2 /home/joos/javmeta/deploy/crawler.sh
```

### Elasticsearch mapping and reindex

Set `ELASTICSEARCH_HOST` to the Elasticsearch URL. Configure either
`ELASTICSEARCH_USERNAME` with `ELASTICSEARCH_PASSWORD`, or
`ELASTICSEARCH_API_KEY`; the API key takes precedence when both are present.
Keep credential values in the deployment environment, not in a tracked file or
the URL.

When the release changes the vector field names, update the mappings and then
reindex existing data after the Laravel services are prepared. Run both
commands from the application directory:

```bash
docker compose --env-file .env --file docker-compose.yml \
  --profile app --profile control --profile embed \
  run --rm --no-deps --entrypoint php api artisan es:setup

docker compose --env-file .env --file docker-compose.yml \
  --profile app --profile control --profile embed \
  run --rm --no-deps --entrypoint php api artisan es:setup --reindex
```

The reindex is a write-heavy operation and may run for a long time. Do not
start crawler workers during it.

## Verified control deployment

The control deployment was verified with the following result:

- The existing checkout was upgraded with `git pull --ff-only`; no archive,
  `scp`, image registry, or `gh` CLI was used.
- `deploy/python.sh` built the local embedder image, started the embedder,
  passed `/healthz`, and completed a real embedding warm-up.
- `deploy/laravel.sh` built the local PHP image, ran locked Composer
  installation, `php artisan migrate --force`, and `php artisan optimize:clear`,
  then recreated `api` and `mcp`.
- Elasticsearch mappings were updated and the full existing movie and
  performer data was reindexed successfully.
- A temporary scheduler smoke test exercised crawler scheduling and metrics
  publishing without errors. The scheduler was then stopped again.
- The final control state has only `api`, `mcp`, and `embedder` running.
  `scheduler`, `worker`, and `flaresolverr` remain stopped until crawler nodes
  are deployed.
- API health returned HTTP 200 with database connectivity OK. A degraded
  worker status is expected while no worker nodes are online.

### Script variables

The defaults are suitable for the current VM layout, but all deployment paths
can be overridden without changing the scripts:

```bash
export JAVMETA_APP_DIR=/home/joos/javmeta
export JAVMETA_REPO_URL=https://github.com/jooservices/javmeta.git
export JAVMETA_BRANCH=develop
export JAVMETA_ENV_SOURCE=/home/joos/javmeta-backups/.env.<timestamp>
```

`JAVMETA_ENV_SOURCE` is required only when a new checkout has no `.env`, or when
the operator intentionally restores a verified backup. When the checkout
already contains `.env`, the scripts preserve it and only enforce mode `600`.

## Control-only cutover

### 1. Read-only preflight

Record the Docker/Compose versions, disk and memory, current Git revision,
container state, external dependency health, and the location of `.env`.

### 2. Backup runtime configuration

Back up `.env` to a private path with mode `600` and verify its checksum. Do
not commit or print the backup. Keep the backup until the new control stack
passes verification.

### 3. Stop the old stack

Stop `scheduler` first. For the current control-only cutover, do not wait for
the worker currently running on the old control stack to drain. Then bring
down the old Compose stack without removing volumes:

```bash
docker compose --profile app --profile control --profile embed down
```

This can interrupt an active worker job. Queue data is external, so queued work
remains available, but an active job may become retryable after the queue
visibility timeout. Never use `down -v`, `docker volume prune`, or
`docker system prune`.

### 4. Pull the requested GitHub revision

Keep `/home/joos/javmeta` in place. Before running the scripts, record the
current revision and confirm the checkout is clean and on the requested branch:

```bash
git -C /home/joos/javmeta rev-parse HEAD
git -C /home/joos/javmeta status --short
git -C /home/joos/javmeta branch --show-current
```

The scripts then run `git pull --ff-only origin develop`. They stop if local
changes exist or if the checkout is on another branch. Do not rename, delete,
or replace the existing checkout for an upgrade.

### 5. Build and prepare dependencies

Run the Python script first so the embedder is available. Export the selected
checkout, repository and ref in the same shell (the scripts have no defaults):

```bash
export JAVMETA_APP_DIR="$CHECKOUT"
export JAVMETA_REPO_URL="$REPO_URL"
export JAVMETA_BRANCH="$REF"
```

```bash
/home/joos/javmeta/deploy/python.sh
```

Then run the Laravel script:

```bash
/home/joos/javmeta/deploy/laravel.sh
```

For a new instance, or when restoring the verified backup, prefix both
commands with `JAVMETA_ENV_SOURCE=/home/joos/javmeta-backups/.env.<timestamp>`.
The scripts must be run as the deployment user, not by copying secrets into
the repository.

### 6. Verify before starting the scheduler

Confirm:

- `api`, `mcp`, and `embedder` are running.
- API health reports external database and search connectivity.
- The embedder health endpoint passes.
- A real embedding request succeeds.
- MCP starts without errors and its smoke request succeeds.
- No `worker` or `flaresolverr` container exists on the control VM.
- Logs contain no startup errors.

### 7. Start and verify the scheduler

Only after the previous checks pass:

```bash
docker compose --env-file .env --file docker-compose.yml \
  --profile control up -d scheduler
```

Verify the scheduler process is running, observe at least one scheduled loop,
and confirm queue activity through the API/status checks. After a smoke test
when crawler workers are not yet deployed, stop it explicitly:

```bash
docker compose --env-file .env --file docker-compose.yml \
  --profile control stop scheduler
```

Do not manually run crawl commands as a deployment health check.

## Failure handling

The scripts stop on the first failure. Do not retry a failed migration or
recreate services blindly. Record the command and error, then decide whether
to restore the previous checkout and environment. No automatic rollback or
external database restore is performed by these scripts.

## Rollback preparation

Keep the recorded previous Git SHA, the `.env` backup, and Docker's local image
cache until verification is complete. An approved rollback switches the
existing checkout back to that known revision, rebuilds the required local
runtime, and starts the previous Compose service set. Do not automatically
reset the checkout, and do not delete old volumes during rollback.

## Staging on WSL

The staging environment is the main clone of JavMeta on the WSL workstation, branch `develop`, with the shared Docker
services and the shared PHP 8.5 runtime. `deploy/staging.sh [--dry-run]` updates it; it has no trigger of its own and never
runs on merge (a one-time trigger starts it when development is finished).

Order of the steps: check the branch (develop), a clean tree, the private `.env` and
`scripts/docker/shared check-project javmeta --mode live`; take a quick `pg_dump` of the `javmeta` database
(`.tmp/javmeta-deploy/backups`, newest seven kept); `git pull --ff-only`; `composer install --no-dev`; `php artisan migrate --force`
(forward only, never fresh or reset); `php artisan es:setup`; clear Laravel optimization caches with `optimize:clear --except=cache` (preserve Redis queues); install and restart
the systemd user units (`deploy/systemd`), with scheduler and Horizon stopped before code and schema changes;
then run `php artisan javmeta:smoke`. Set `APP_URL=http://javmeta.localhost:8085` and
`JAVMETA_SMOKE_BASE_URL=http://workspaces-wp-web-85` on WSL. The smoke check sends the public Host header
to the internal Docker URL. Do not cache Laravel bootstrap paths here: the shared CLI and web containers
mount the checkout at different absolute paths.

If a step fails after the pull, the script returns the code to the previous commit; when the migration was the failing step it
also restores the quick backup. The log of every run is `.tmp/javmeta-deploy/staging-<time>.log` and holds no secrets.


## Switching a source on or off

The database column `sources.enabled` is the only switch; there is no environment variable or config list for it.
A new source starts on, except a source whose configuration says `requires_login` (for example `avfan`), which starts off.

```bash
php artisan crawler:sources                 # list sources with their switch, last success and last error
php artisan crawler:source-toggle javbus off
php artisan crawler:source-toggle javbus on --by=owner
```

A switch is written to `crawl_events` (kind `source_toggled`, with who and when). The queued rows of a switched-off source
wait until it is switched on again. The XCrawler UI will use the same column.


## Operations dashboard (jav.soulevil.com)

`/ops` is a read-only page for the crawl: catalogue totals, per-source health, queue, last-hour performance and recent
issues. It needs a signed-in Super Administrator; the root address redirects to it. Create or rotate the account (the
password is random, 40 characters, shown once and stored hashed):

```bash
php artisan operations:make-super-admin owner@example.com           # create
php artisan operations:make-super-admin owner@example.com --rotate  # new password
```

Behind the gatekeeper set `APP_URL=https://jav.soulevil.com` and `SESSION_SECURE_COOKIE=true`; the app trusts the
forwarded headers of the proxy, so redirects and cookies use https. Login attempts are limited to 6 per minute.


## File permissions on staging

`deploy/staging.sh` runs with `umask 022` so the web server can read what the pull and composer write; only the database
backups under the state directory are private (0600). Do not `chmod` the `.env` file: it carries an ACL entry for the web
user (`user:www-data:r--`) and a plain `chmod 600` sets the ACL mask to nothing, which makes every request fail with HTTP 500.
Restore it with `setfacl -m m::r .env`. A full crawl queue is reported by the smoke check as a warning, not a failure.

## Native XCrawler node

The independent production node uses the operator-only [production kit](../../deploy/production/README.md).
It provisions `xcrawler` namespaces, provides native Ubuntu/nginx/FPM/systemd files, and deploys only
an explicitly selected branch, tag or commit SHA using `--ref`, `--repo` and `--target` through `deploy/production.sh` (dry-run by default).
The script verifies a fresh backup and requires restore-rehearsal attestation before migrations;
a failure restores the old symlink/services without automatically reversing schema changes.
No production hosts were contacted while preparing this kit; see its verification limits and bootstrap
Settings checklist. Staging uses the same wizard; pass the intended repository, ref, target and private service answers explicitly.
