# Native install and deploy wizard

Run `deploy/production.sh` or `deploy/install.sh` on the selected Ubuntu
24.04+ node. Both prompt for missing answers by default. Dry-run is the
default: no network calls, files, packages or units change until `--apply`.
`deploy/staging.sh` is the unattended wrapper. No branch, repository or target
is implied by any entry point.

## Inputs

Defaults are declared in [../manifest.json](../manifest.json). Priority is CLI,
private answers JSON, environment, interactive input over defaults. `--ref` is
required (branch, tag or full SHA). The private config must be an owned regular
0600 file, never committed. `--non-interactive` lists every missing required
answer. Secrets use hidden prompts, the private config or environment; never
argv. `--set KEY=VALUE` rejects secret fields.

```bash
bash deploy/install.sh --help
bash deploy/production.sh                                       # interactive preview
bash deploy/production.sh --apply                               # interactive install
bash deploy/install.sh --config /private/node.json --non-interactive        # preview
bash deploy/install.sh --config /private/node.json --non-interactive --apply # install
bash deploy/production.sh --config /private/node.json --ref "$REF" --apply
bash deploy/staging.sh staging --config /private/node.json --ref "$REF" --apply
```

The config is a flat JSON object of strings. Supply `REPO_URL`, `REF`,
`TARGET_DIR`, `SERVICE_USER`, `NODE_ROLE`, `OWNER_EMAIL`, plus required service
fields from the manifest. `UNIT_PREFIX`, `FPM_UNIT`, `NGINX_UNIT`, node name and
release root are operator inputs. The private credentials file defaults to
`<TARGET_DIR>/shared/<APP_NAME>-credentials.txt`; set `CREDENTIALS_FILE` to
another absolute private path when needed.

Existing shared env keys are preserved. Explicit changes require per-key
confirmation, or `--replace-existing` unattended. APP_KEY is generated once.
The env is 0640, owned by the service user. Do not share the credentials file:
on first install it contains the collected credentials and generated owner
password, mode 0600. The owner command output is captured privately and
shredded, never logged. Later releases preserve the existing owner account and
password instead of rotating them.

## Services and validation

| Service | Check |
| --- | --- |
| PostgreSQL | PDO authentication, `psql SHOW timezone`, require UTC/Etc/UTC |
| MongoDB | PHP driver ping; build URI with percent-encoded user/password and authSource |
| Redis | ACL AUTH, SET/GET in the prefix, denial outside it |
| Elasticsearch | HTTP(S) and API-key (TLS verified for HTTPS) `_security/_authenticate`; optional CA file |
| OpenObserve | Authenticated test ingest into the selected org/log stream |
| FlareSolverr | HTTP sessions list, require `status=ok` |
| Playwright | HTTP health endpoint |
| Embedder, Ollama | Optional HTTP health/API check |
| Sentry | Optional DSN and authenticated client report |

Interactive failures show a redacted reason and re-ask; unattended failures
abort before clone. A non-UTC PostgreSQL session blocks deployment: UTC strings
otherwise break stale-claim reclaim. The wizard offers a separate admin prompt
to `ALTER DATABASE ... SET timezone TO 'UTC'`, then reconnects to verify. Admin
credentials live only in a subshell and are never written to env/config/logs.
`DB_SSLMODE` defaults to `require`. The Elasticsearch CA can be installed into
OS trust with `INSTALL_CA=1`; this requires sudo and cannot be combined with
`--skip-os-install`.

`CRAWLERX_PLAYWRIGHT_SCRIPT` is always resolved under
`<TARGET_DIR>/current/vendor/jooservices/crawlerx/scripts/playwright-fetch.mjs`.
Never use a Docker image path for a native node. `JAVMETA_WORKERS_PER_QUEUE`
is written to env: Horizon reads this directly; the database setting
`queue.workers_per_queue` cannot configure Horizon.

## Installer and release sequence

The existing idempotent `node/install-node.sh` checks Ubuntu, installs the
manifest-selected prerequisites and renders templates. It creates storage,
service account and embedder venv; validates nginx (primary only), sudoers and
Composer checksum. It never starts application units. Bootstrap Bash/sudo/jq
before invoking the wizard. `--skip-os-install` skips OS/package changes.

The release engine locks the target, checks that env has not changed during
input, checks a fresh custom-format PostgreSQL backup and restore attestation,
clones/fetches the exact selected ref, and checks out a detached commit into
`releases/<UTC timestamp>-<sha7>-<process id>`. Optional exact-commit CI check
names come from `CI_CHECKS`; enable with `--ci-check 'Job A,Job B'` and
`CI_REPOSITORY`. `--skip-ci-check` skips this optional gate.

It then links shared env/storage, runs Composer `--no-dev
--classmap-authoritative`, UI render smoke, quiesces role units, migrates forward,
initializes registered database settings through SettingsService, imports env
settings, runs `es:setup` **before crawl**, syncs sources, creates the owner on
first install,
clears config/routes/views without flushing Redis, and swaps `current`
atomically. Initial settings include `domains.ui_host`,
`domains.command_center_host`, `search.movies_index`, `search.performers_index`,
`search.embedder_url`, `ai.ollama_url`, `media.api_url` and
`observability.openobserve_enabled`; saved UI choices remain authoritative.

`--skip-backup-check` explicitly bypasses the backup gate with a loud warning.
It does not make migrations reversible. Preserve PostgreSQL and Mongo backups
and review forward compatibility before each release.

Matching units are enabled/restarted, with scheduler last **only** when
`--start-scheduler` is supplied. `--no-start` installs without unit actions or
claiming process readiness; `--no-systemd` prints actions without calling
systemctl. Normal starts wait with a bounded timeout for application readiness.
Every step reports duration and verification; raw command output is suppressed
to avoid leaking secrets. Failure restores the old symlink/env and restarts old
units; first-install failure stops role units. Database migrations/settings are
not rolled back automatically. Failed releases remain for private inspection.

## Adding a worker node

Use a new target/service account/node name with `--role worker`. Horizon, embedder and node exporter are enabled. Nginx and scheduler remain installed but disabled and blocked by a systemd condition drop-in. Workers never run migrations; use the shared env from the primary node. Point
`media.api_url` at the primary API. Shared service answers must select the same
application databases/indexes but the appropriate scoped Redis prefix.

Only **one scheduler** may run across the fleet: scheduled jobs do not use
`onOneServer`. Stop the old scheduler before deliberately starting the new one
with `--start-scheduler` on a primary node. The PostgreSQL application role's
connection limit **60** and shared `max_connections` **150** limit scale. Size
worker counts against both limits. Browser capacity on vm-tools is **global**,
not per worker; adding nodes does not add browser capacity.

## Local verification

Run shell regressions and the disposable local install twice. Never point this
proof at production. Use the existing shared runtime/services, `--skip-os-install
--no-systemd --no-start`, a local file repository and a local commit SHA.
External browser service reachability and full started-process readiness require
separate operator verification when those services are available.
