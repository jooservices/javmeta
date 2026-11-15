# Bug hunt: observability, health, startup gate, deployment and install scripts (JOOXCR-414)

Scope: `app/Observability/**`, `app/Services/Health/**`, `StartupCommandGate`, `/up`, `/api/health`,
`/api/health/live`, `obs:publish-metrics`, `deploy/**` (incl. `deploy/production/**`, systemd units,
`install.sh`, `join-node.sh`, rollback), `tests/Shell`.

Method: code read end to end for the scope; invariants attacked with scratch PHPUnit reproductions
(`tests/Scratch/`, not committed, listed in the appendix); read-only production checks (SQL on
`xcrawler` via `pg_stat_*`/`pg_indexes`/`pg_settings`-style SELECTs only, Sentry Django shell read-only,
read-only `ls`/`grep`/`cat` on nodes .20/.21/.22). No application code, tests, servers or units were
changed. No `docker/ci/integration`, `docker/qa/*` or compose was run. Only `docker/ci/run php vendor/bin/phpunit`
and `docker/ci/run composer install` (inside this worktree) were used.

Reproduction command for every scratch test below:
`export DOCKER_CONFIG=/home/joos/Workspaces/.tmp/javmeta-docker-config-jooxcr-341; docker/ci/run php vendor/bin/phpunit tests/Scratch/<File>.php`

Note: `phpunit.xml` only runs `tests/Unit` and `tests/Feature`; scratch files must be passed by path.

## Summary

Counts (findings): **S1: 1 · S2: 12 · S3: 9** (22 total). Evidence: 12 confirmed by a failing scratch test or a production read (001–007, 010, 013–015, 020); 5 confirmed by code reading only (009, 012, 016, 017, 019); 5 suspected at runtime (008, 011, 018, 021, 022).

| Severity | Count | IDs |
|---|---|---|
| S1 | 1 | BH-ops-001 |
| S2 | 12 | BH-ops-002 .. BH-ops-013 |
| S3 | 9 | BH-ops-014 .. BH-ops-022 |

### Fix first (ranked)

1. **BH-ops-001** Public `/api/health` runs 11 live dependency probes (incl. Telegram `getMe` with the bot token in the URL), 3 `COUNT(*)` on `crawl_queue` and an unbounded source list on every request; the 10 s dependency cache is per-process, so it never applies in PHP-FPM. Production log: about 21k `Runtime dependency state changed` lines per dependency per day.
2. **BH-ops-002** `StartupCommandGate::roleFor()` maps `horizon:work` (Horizon's worker children) to `web`, so an Elasticsearch outage blocks every queue worker. Sentry: 3,160 blocked events, group 130.
3. **BH-ops-013** A settings-store read error marks a reachable MongoDB as failed and blocks batch commands. On the web node the same state returns 503 for every page.
4. **BH-ops-005** `now()->diffInSeconds($past)` is negative in Carbon 3, so `max(0, …)` makes `crawl_queue_claimed_age_seconds` and `source_last_success_age_seconds` always 0. The alert `javmeta_queue_claimed_age_high` can never fire.
5. **BH-ops-007** The OpenObserve shipping timeout is capped at 0.2 s in console; the configured `openobserve.timeout` is ignored. A failed metrics POST still prints `Published N metric points` and exits 0.
6. **BH-ops-006** `obs:publish-metrics` runs every minute with no `withoutOverlapping()`, and runs full scans over `movie_observations` (4.18M rows, 1.2 GB), `crawl_events` and `crawl_queue` each time.
7. **BH-ops-004** Elasticsearch is a primary dependency of the web role by default, so a search outage turns every web page into a 503.
8. **BH-ops-010** Deployed sudoers on `.20` is the old single-line grant, while the current wizard issues single-unit `systemctl` calls, `is-active`, and `sudo bash refresh-units.sh`. The next apply run as `xcrawler` will likely fail and roll back.
9. **BH-ops-008** `/up` is gated by `StartupHealthMiddleware` and is the URL the install readiness check probes, while the docs name `/api/health/live` as the health check.
10. **BH-ops-011** The owner password is rotated (`--rotate`) on any deploy where `current` is missing or its target was removed, contrary to the documented "preserve the owner password" rule.

---

## Findings

### BH-ops-001 · S1 · Public `/api/health` probes every dependency per request; the cache is per-process

- **Files:** `app/Services/Dependencies/DependencyMonitor.php:28` (state is an instance property), `:38-60` (`check()` cache test, `CACHE_SECONDS = 10`), `:210-236` (`changeState` logs every transition); `app/Services/Health/StatusHealthCheck.php:29` (`statuses()` for all 11 dependencies), `:50` (`queueCounts()`), `sourceRows()` via the repository; `app/Repositories/HealthStatusServiceRepository.php:34-41` (three `COUNT(*)` on `crawl_queue`), `:44-57` (unbounded `Source` list); `app/Services/Dependencies/Probes/TelegramProbe.php:26-30` (`getMe` with token in URL); `routes/api.php:34` (route is not inside a throttled group, `bootstrap/app.php` has no api throttle).
- **Summary:** Each HTTP request builds a new container in PHP-FPM, so `DependencyMonitor` starts with `state = unknown` and probes all eleven dependencies synchronously. Probes include outbound calls (Telegram, Sentry HEAD, Ollama, qBittorrent login, browser, embedder, Elasticsearch, OpenObserve). The request also runs three `COUNT(*)` on `crawl_queue`. The endpoint is public and unthrottled. The 10 s cache and the circuit breaker (`FAILURE_THRESHOLD`, `CIRCUIT_COOLDOWN_SECONDS`) therefore never act across requests.
- **Reproduction (scratch):** `tests/Scratch/BugHuntOpsDependencyTest.php` — two `new DependencyMonitor($probes)` instances (= two requests) each call `statuses()`; the probe is called twice. **FAILS**: `Failed asserting that 2 is identical to 1`.
- **Evidence (production, read-only):** `storage/logs/laravel-2026-11-04.log` on `.20` contains per day: `postgres` 21,046, `sentry` 21,037, `redis` 21,037, `ollama` 21,037, `observability` 21,037, `mongo` 21,037, `embedder` 21,037, `elasticsearch` 21,036, `telegram` 20,275 lines of `Runtime dependency state changed` with `"from":"unknown"`. Total file size about 30–41 MB/day. `storage/logs` is 193 MB.
- **Root cause (confirmed):** state and cache live in an object that is rebuilt per request; the public endpoint does live I/O and a full queue aggregate per call.
- **Proposed fix:** serve `/api/health` from a shared snapshot (APCu/Redis, TTL 5–10 s, single-flight lock) written by the scheduler or by one request per TTL; keep dependency probing out of the request path; cache `queueCounts()` (or read it from the existing metrics/heartbeat tables); add a throttle to `/api/health`; move the Telegram probe to `getMe` only from the scheduler and never log the token-bearing URL; do not log `unknown → healthy` transitions.
- **Proposed failing tests:** `DependencyMonitorTest::test_probe_results_are_shared_across_instances_for_the_cache_window`; `HealthEndpointTest::test_public_health_does_not_probe_dependencies_per_request`; `HealthEndpointTest::test_public_health_is_throttled`; `DependencyMonitorTest::test_unchanged_state_is_not_logged`.

### BH-ops-002 · S2 · Queue workers classified as `web`: `horizon:work` is blocked by Elasticsearch

- **File:** `app/Services/Health/StartupCommandGate.php:70` (only the exact string `horizon` maps to worker), `:75` (`default => 'web'`); `config/javmeta_health.php:13` (`web` primary includes `elasticsearch`).
- **Summary:** Horizon starts its worker children as `artisan horizon:work`. `horizon:work` does not match `$command === 'horizon'`, so it falls to `default => 'web'`. The web role requires Elasticsearch, so every worker child exits with `RuntimeException` while ES is down. Horizon then restarts the children in a loop.
- **Reproduction (scratch):** `tests/Scratch/BugHuntOpsGateTest.php::test_horizon_worker_is_not_blocked_by_elasticsearch` — ES probe fails, `handle('horizon:work')`. **FAILS**: `Startup health blocked \`horizon:work\` for role \`web\`; unavailable primary dependencies: elasticsearch`.
- **Evidence (Sentry, read-only, project 2 `xcrawler`):** group 130 "Startup health blocked `horizon:work` for role `web`; unavailable primary dependencies: elasticsearch" — `times_seen` 3,160; `first_seen` 2026-11-02 05:20 UTC; `last_seen` 2026-11-06 16:01 UTC; daily counts non-zero from 11-02 to 11-06, zero on 11-07.
- **Root cause (confirmed):** `roleFor()` has an allowlist of worker commands and defaults to `web`, so any unlisted command inherits the web primary set.
- **Proposed fix:** map `horizon` and every `horizon:*` command to `worker`; change the default to an explicit role for each command family (or `scheduler` for CLI), and add a test that every command in `routes/console.php` resolves to a role whose primary set matches its node. Do not let a worker crash-loop on Elasticsearch.
- **Proposed failing tests:** `StartupCommandGateTest::test_horizon_workers_use_worker_dependencies`; `StartupCommandGateTest::test_every_scheduled_command_has_an_explicit_role`.

### BH-ops-003 · S2 · Scheduled commands classified as `web` (`perf:warm` etc.) — scheduler node is gated by Elasticsearch

- **Files:** `app/Services/Health/StartupCommandGate.php:75` (default `web`); scheduled set in `routes/console.php:66-85`.
- **Summary:** The following commands run on the scheduler node (`.20`, `schedule:work`) but resolve to `web` and so require Elasticsearch: `horizon:reconcile-workers`, `node:egress-probe`, `obs:publish-metrics` is worker (ok), `alerts:evaluate`, `node:sample`, `perf:warm` (+ `--status-only`), `report:send`, `report:send-daily`, `javmeta:hourly-report`. With ES down, each scheduled tick fails and writes `Scheduled command failed` to the log.
- **Reproduction (scratch):** `tests/Scratch/BugHuntOpsGateTest.php::test_scheduled_perf_warm_is_not_blocked_by_elasticsearch`. **FAILS**: `Startup health blocked \`perf:warm\` for role \`web\`; unavailable primary dependencies: elasticsearch`.
- **Note on the reported symptom:** the reported Sentry message `Startup health blocked perf:warm for role web; unavailable primary dependencies: postgres, mongo` (~1,400/day) was **not found**: a Sentry search for `perf:warm` and for `postgres, mongo` over projects 1, 2 and 3 returned zero groups. The only startup group is 130 (BH-ops-002). The postgres/mongo symptom is therefore **not reproduced** (see "Could not verify").
- **Root cause (confirmed):** same as BH-ops-002.
- **Proposed fix:** explicit role per scheduled command (scheduler set = postgres+redis+mongo, no ES); cover all `routes/console.php` commands in a test.
- **Proposed failing tests:** `StartupCommandGateTest::test_scheduler_commands_do_not_require_elasticsearch`.

### BH-ops-004 · S2 · Elasticsearch is a web primary by default: a search outage returns 503 on every web page

- **Files:** `config/javmeta_health.php:13` (`'web' => ['postgres','redis','mongo','elasticsearch']`); `config/javmeta_settings.php:57` ("Elasticsearch mặc định là dịch vụ chính của nút web"); `app/Services/Health/StartupHealthService.php:107-113` (role list); `app/Http/Middleware/StartupHealthMiddleware.php:27-35` (503 for all non-health routes when blocking).
- **Summary:** Search is one feature; the web primary set makes it a site-wide gate. The owner principle is that optional dependencies must not turn the whole product into a 503.
- **Reproduction (scratch):** `tests/Scratch/BugHuntOpsWebEsTest.php` — ES probe false, `run('web', 0, false)['blocking']`. **FAILS**: `Failed asserting that true is false`.
- **Root cause (confirmed by test; product decision SUSPECTED):** the default makes ES primary for web.
- **Proposed fix:** remove ES from the default web primary set (keep it in the worker primary set or as a secondary check with a search-only 503 on `/api/v1/search*`); keep Postgres/Redis/Mongo as the gate.
- **Proposed failing tests:** the scratch test above, moved to `StartupHealthServiceTest`.

### BH-ops-005 · S2 · Carbon 3 signed `diffInSeconds`: queue-claimed age and source last-success age are always 0

- **Files:** `app/Console/Commands/Observability/PublishMetricsCommand.php:165` (`max(0, now()->diffInSeconds($source->last_success_at))`), `:273` (`max(0, (int) now()->diffInSeconds($oldestClaimed))`); alert `deploy/observability/alerts/javmeta_queue_claimed_age_high.json` (fires on `value > 1800`).
- **Summary:** In `nesbot/carbon` 3.15.1 (`composer.lock`), `$now->diffInSeconds($past)` is negative. `max(0, …)` turns every age into 0. Other call sites in the repo use `past->diffInSeconds(now())` and are correct (`WorkerHeartbeat.php:54,78`, `OperationsDashboardService.php:46`, `StatusHealthCheck.php:55`).
- **Reproduction (scratch):** `tests/Scratch/BugHuntOpsCarbonTest.php` — `now()->diffInSeconds(now()->subHour())`. **FAILS**: `Failed asserting that 0 is identical to 3600`. Command-level: `tests/Scratch/BugHuntOpsPublishTest.php::test_claimed_queue_row_age_is_reported_in_seconds` **FAILS** (`0.0` vs `3600.0`) and `::test_source_last_success_age_is_reported_in_seconds` **FAILS** (`0.0` vs `7200.0`).
- **Evidence (production, read-only):** `crawl_queue` currently has 11 claimed rows, oldest `claimed_at` 2026-11-07 00:13:24 UTC, so the alert condition is live and the metric would be 0 even with a 2-hour-old claim.
- **Root cause (confirmed):** argument order for Carbon 3 signed diff.
- **Proposed fix:** `$oldestClaimed->diffInSeconds(now())` (or `now()->diffInSeconds($x, absolute: true)`), and use one helper for all ages.
- **Proposed failing tests:** the two scratch tests above, moved to `PublishMetricsCommandTest`.

### BH-ops-006 · S2 · `obs:publish-metrics` every minute: no overlap guard, full scans of the largest tables

- **Files:** `routes/console.php:68` (`$scheduleCommand('obs:publish-metrics')->everyMinute();` — no `withoutOverlapping()`, no timeout); `app/Console/Commands/Observability/PublishMetricsCommand.php:616` (`observationConflictRates()`), `:672` (`observations_1d` count), `:361-377` (`crawlEventMetrics`, `created_at >= now-5m`), `:255-270` (`groupBy('status')` and `min(claimed_at)` on `crawl_queue`), `:604-605` (`movies` tier group), `:596` (`needs_review` count), `:669` (`movies` count), `:688`/`:691` (`failed_jobs` counts); `app/Repositories/ObservabilityServiceRepository.php:19-33` (`GROUP BY movie_id, field` with `COUNT(DISTINCT value_hash)` on the whole observation table).
- **Summary:** Each run performs at least seven full-table aggregates. Production facts (read-only):
  - `movie_observations`: 4,181,204 live rows, 1,212 MB; `seq_scan` 26,236 with 28,588,051,090 tuples read; only indexes are `(movie_id, field)` and pkey, so `crawled_at >= now()-1d` cannot use an index.
  - `crawl_events`: 819,975 rows; `seq_scan` 26,241 (about one per minute); only pkey, so the 5-minute `created_at` window is a full scan.
  - `crawl_queue`: 663,686 rows, 74,186 dead tuples; `seq_scan` 7,718,158; `status='claimed'` has no index, so `min(claimed_at)` and the claimed count are full scans.
  - None of this overlaps protection: a run longer than 60 s starts a second concurrent run.
- **Reproduction:** SQL (read-only, `statement_timeout=10000`):
  `SELECT relname, seq_scan, seq_tup_read, idx_scan FROM pg_stat_user_tables WHERE relname IN ('movie_observations','crawl_events','crawl_queue');` → as above. `SELECT tablename, indexdef FROM pg_indexes WHERE tablename IN ('movie_observations','crawl_events','crawl_queue');` → as above.
- **Root cause (confirmed by schema and pg_stat; runtime overlap SUSPECTED):** metrics are computed by live aggregates per minute, not from rollups; no concurrency guard.
- **Proposed fix:** add `->withoutOverlapping(2)` and `->onOneServer()`; split the command: cheap gauges every minute, heavy aggregates (conflict rate, observation counts, tier distribution, movie count) every 5–15 minutes from `crawler:stats-rollup` or a chunked job. Add indexes `movie_observations(crawled_at)`, `crawl_events(created_at)`, and a partial index `crawl_queue(claimed_at) WHERE status='claimed'` via a concurrent migration. Verify with EXPLAIN on a non-production copy (not run here).
- **Proposed failing tests:** `ScheduleTest::test_metrics_publish_does_not_overlap`; `PublishMetricsCommandTest::test_heavy_aggregates_are_not_recomputed_every_minute`.

### BH-ops-007 · S2 · OpenObserve: console timeout capped at 0.2 s and metrics failures are reported as success

- **Files:** `app/Observability/OpenObserveClient.php:432-449` (`min(0.2, …)` for console too), `:132-178` (`ingestMetrics` builds one body for all points, no chunking; return value of `postJson` is discarded at `:177`); `app/Console/Commands/Observability/PublishMetricsCommand.php:122` (`Published N metric points.` printed unconditionally); `deploy/` settings `observability.openobserve_timeout_seconds` (UI range 1–30, `config/javmeta_settings.php:260`).
- **Summary:** `openobserve.timeout` is effectively capped at 200 ms in every console context, so a large metrics batch (hundreds of points, a few hundred KB) can time out and be dropped; the operator's timeout setting does nothing above 0.2 s. A rejected POST (HTTP 500) is logged to the single channel but the command still says it published.
- **Reproduction (scratch):**
  - `tests/Scratch/BugHuntOpsOpenObserveTest.php::test_console_shipping_timeout_honours_configured_timeout` — `openobserve.timeout=3`, reflect `shippingTimeoutSeconds()`. **FAILS**: `Failed asserting that 0.2 is identical to 3.0`.
  - `tests/Scratch/BugHuntOpsPublishTest.php::test_failed_openobserve_post_is_not_reported_as_published` — `Http::fake` returns 500. **FAILS**: `Output "Published" was printed`.
- **Root cause (confirmed):** the 200 ms request-path budget is applied without checking `runningInConsole()`; `postJson()` result is ignored.
- **Proposed fix:** apply the cap only for request-path shipping; chunk metric points (for example 200 per POST); make `ingestMetrics()` return the count of failed chunks and make the command exit non-zero (or print `FAILED`) when any chunk fails.
- **Proposed failing tests:** the two scratch tests above; `OpenObserveClientTest::test_metrics_are_chunked`.

### BH-ops-008 · S2 · `/up` is gated by the startup middleware; install readiness uses `/up`, docs say `/api/health/live`

- **Files:** `vendor/laravel/framework/src/Illuminate/Foundation/Configuration/ApplicationBuilder.php` (`Route::get($health, HealthCheck::class)` for `/up`); `bootstrap/app.php:17,24` (`health: '/up'`; `$middleware->prepend(StartupHealthMiddleware::class)`); `app/Http/Middleware/StartupHealthMiddleware.php:23` (exempts only `api/health` and `api/health/live`); `deploy/lib/install.sh:292` (`readiness()` probes `${APP_URL}/up` on primary); `docs/03-operations/runtime-dependencies.md:6` ("healthcheck still uses `/api/health/live`"); `docs/03-operations/startup-health.md:39-41` (lists only the two `/api/health*` exemptions).
- **Summary:** `/up` runs through the global middleware, so a red web snapshot (Elasticsearch today, BH-ops-004) makes `/up` return 503. Install readiness treats that as unhealthy and rolls back; docs describe a different endpoint. The Laravel default `/up` contract is "application booted", not "dependencies up".
- **Reproduction:** runtime test is not possible in the testing env (`StartupHealthMiddleware` is not prepended under `runningUnitTests()`). Code path is confirmed by reading the two files above. **SUSPECTED runtime impact** until run on staging.
- **Root cause (confirmed by code):** exemption list omits `/up`; installer and docs disagree.
- **Proposed fix:** exempt `/up` from the startup gate (liveness only) or make it documented as gated; make `install.sh` probe the endpoint that docs call liveness; add a doc line for `/up`.
- **Proposed failing tests:** `HealthRoutesTest::test_up_is_liveness_only_and_not_gated`.

### BH-ops-009 · S2 · Credentials in process arguments (`mongodump --uri`, `mongosh <uri>`)

- **Files:** `deploy/backup/backup.sh:194-199` (`mongodump --uri="$MONGO_URI" ...`); `deploy/backup/common.sh:133,140,147,155` (`mongosh --quiet "$MONGO_URI" --eval ...`), `common.sh:155` also embeds `$collection` into JS.
- **Summary:** When `MONGO_URI` carries credentials (it does: `build_mongo_uri`, `install.sh` `answers[MONGO_URI]`), the full URI including the password is in `argv` for the lifetime of each process: visible in `/proc/<pid>/cmdline` and `ps` to any local user (mongodump on a large archive can run for many minutes).
- **Reproduction:** read-only, by inspection of the lines above. Equivalent test: start `mongosh "mongodb://u:p@h/db"` with a stub and list `ps -o args=` — the password appears. **Impact on a shared host: SUSPECTED** (requires another local account).
- **Root cause (confirmed by code):** secrets passed as argv instead of env or a 0600 file.
- **Proposed fix:** write the URI to a 0600 temp config file and pass `--config`/`--uriFile` (verify the flag for the pinned mongodump/mongosh), or pass via environment where the tool supports it; keep `set +x`; add a shell test that greps the built argv for the password.
- **Proposed failing tests:** `tests/Shell/backup.test.sh::test_mongo_credentials_are_not_in_argv`.

### BH-ops-010 · S2 · Deployed sudoers on `.20` does not match the wizard's current `systemctl` calls (deploy blocker, not yet exercised)

- **Files:** `deploy/production/node/install-node.sh:60-70` (generator, one line per unit/action; `visudo -cf` after write); `deploy/lib/install.sh:212-214` (`control()` runs `sudo -n /usr/bin/systemctl "$@"` for every action including `is-active` and single-unit `restart`/`enable`); `deploy/lib/install.sh:251` (`sudo --preserve-env=… "${command[@]}"` runs `bash refresh-units.sh` as `xcrawler`); `deploy/lib/prerequisites.sh:15-16` (installer run with output discarded).
- **Evidence (production, read-only, `sudo -n cat`):** `/etc/sudoers.d/xcrawler-units` on `.20` is one line with five combined command vectors, modified 2026-11-01. It is the format of commit `816a0e8`; the current generator (`git log -S NOPASSWD`) writes one grant per unit and action. The file grants no `is-active`, no single-unit `restart`/`enable`, and no `bash refresh-units.sh`.
- **Impact:** with the current wizard, `Refresh systemd units` (as `xcrawler`) fails at the first `sudo` prompt; if it did not, `Enable role units` / `Readiness` fail. Each path reaches `rollback()`. Confirmed drift; deploy failure **SUSPECTED** until an apply run.
- **Root cause (confirmed):** the generator runs only from `prerequisites()` as root; a release run as `xcrawler` never regenerates it.
- **Proposed fix:** have the apply path verify the sudoers content (checksum against the generator output) and fail with a clear message; or regenerate it in `refresh-units.sh` as root; print the `prerequisites` installer output on failure instead of `>/dev/null 2>&1`.
- **Proposed failing tests:** `tests/Shell/install.test.sh::test_sudoers_grants_match_the_wizard_commands`; `tests/Shell/production.test.sh::test_prerequisite_failure_shows_installer_output`.

### BH-ops-011 · S2 · Owner password rotated on any deploy without a resolvable `current`

- **File:** `deploy/lib/install.sh:334` (`previous` cleared when `current` is missing or its target is not a directory), `:378-379` (`if [[ -z "$previous" ]]` → `owner_credentials`), `:502-520` (`owner_credentials` runs `operations:make-super-admin --rotate` and rewrites the credentials file with `mv -fT`); documented promise `deploy/production/README.md:40-41`.
- **Summary:** The documented guarantee is "later releases preserve the existing owner account and password". The code keys the decision on the presence of a `current` symlink pointing to an existing directory. A removed link (manual cleanup, a `rollback()` with no previous release, or a pruned release dir, see BH-ops-014) makes the next run rotate the owner password and overwrite the credentials file.
- **Reproduction:** logic reproduction by reading; not executed (no apply on a server). **SUSPECTED runtime**.
- **Proposed fix:** decide "first install" from the database (an owner exists) or from a marker file in `shared/`, not from `current`; never rotate without `--replace-existing`.
- **Proposed failing tests:** `tests/Shell/install.test.sh::test_missing_current_does_not_rotate_the_owner`.

### BH-ops-012 · S2 · `.20` web node: `systemd` restart policy and hardening gaps on the health-gated units

- **Files:** `deploy/production/node/xcrawler-scheduler.service`, `xcrawler-horizon.service` (`StartLimitIntervalSec=0`, `Restart=on-failure`, `RestartSec=15s`, `ExecStartPre=… health:startup`, `ProtectSystem=full`).
- **Summary:** `StartLimitIntervalSec=0` disables the start-rate limit, so while a primary dependency is down each unit retries every 15 s indefinitely, each attempt logging through `health:startup` and the gate. `ProtectSystem=full` is weaker than `strict` for a unit that writes only `shared/storage`. There is no `ProtectHome=read-only` or `ProtectKernelTunables`. None of these are exploitable by themselves; they add noise and widen blast radius.
- **Reproduction:** read the unit files; no runtime run.
- **Proposed fix:** `ProtectSystem=strict`, `ProtectHome=read-only` (with `ReadWritePaths` covering `shared/`), `ProtectKernelTunables=yes`, `RestrictSUIDSGID=yes`; a finite `StartLimitBurst` with a longer `RestartSec` for dependency waits; `systemd-analyze security` in CI.
- **Proposed failing tests:** `tests/Shell/node-template.test.sh::test_units_have_hardening_baseline`.

### BH-ops-013 · S2 · Settings-store read error marks a reachable MongoDB as failed; blocks commands and 503s the web

- **Files:** `app/Services/Health/StartupHealthService.php:89` (`$settingsLoaded = $bootstrapPassed && $this->settings->applyFresh();`), `:92-99` (Mongo check flipped to `fail` when settings are not loaded, with message "MongoDB settings-store read was unavailable or deferred"), `:103` (`$enabled` forced to `true` when settings are not loaded); `app/Services/Settings/SettingsService.php:110-119` (`applyFresh()` returns false on any `refresh()` error); `app/Http/Middleware/StartupHealthMiddleware.php:33-35` (503 when blocking); `app/Console/Listeners/StartupCommandListener.php:20-22`.
- **Summary:** `applyFresh()` reads the database-backed settings through the same MongoDB store that was just pinged. A transient read error or timeout after a successful ping makes `StartupHealthService` report Mongo as `fail`, so the gate names Mongo and blocks the command. On the web role the same report makes `StartupHealthMiddleware` return 503 for every non-health route for the snapshot TTL (5 s), and the next snapshot repeats it. The operator sees "unavailable primary dependencies: mongo" while Mongo answers ping.
- **Reproduction (scratch):** `tests/Scratch/BugHuntOpsSettingsGateTest.php::test_settings_store_read_error_does_not_blame_a_reachable_mongo` — all probes up; `ConfigStore::refresh()` throws; `handle('crawler:reclaim')`. **FAILS**: `Startup health blocked \`crawler:reclaim\` for role \`worker\`; unavailable primary dependencies: mongo`.
- **Root cause (confirmed):** the settings read result is folded into the Mongo probe result instead of being its own check, and a failed read is treated as a dependency failure.
- **Proposed fix:** report a settings-read failure as its own `settings` check with class `secondary` (warn), keep the last known good settings for the process, and never mark Mongo failed from a settings read. For the web role, do not 503 on a settings-read failure alone.
- **Proposed failing tests:** the scratch test above, moved to `StartupHealthServiceTest::test_settings_read_failure_is_not_a_mongo_failure`; `StartupHealthMiddlewareTest::test_settings_read_failure_does_not_return_503`.

---

### BH-ops-014 · S3 · Releases are never pruned (3.2 GB on `.20`, 34 release directories)

- **Files:** `deploy/lib/install.sh:341` (each release is a full clone with `.git`, then `composer install`); no pruning anywhere under `deploy/` (grep for `releases` finds creation only).
- **Evidence (read-only):** `.20`: 34 entries, 3.2 GB under `/var/www/xcrawler/releases`; `.21`: 32 entries; `.22`: 15 entries; root disk 124 GB, 97 GB free.
- **Impact:** linear growth (about 100 MB per release); no disk pressure today.
- **Proposed fix:** after a successful activation keep `current`, `previous` and N (for example 5) newest; delete the rest with the validated `prune_backups` pattern; drop `.git` from each release.
- **Proposed failing tests:** `tests/Shell/install.test.sh::test_old_releases_are_pruned_after_activation`.

### BH-ops-015 · S3 · Role label is `api` on every node (`JAVMETA_ROLE=api` on `.20`, `.21`, `.22`)

- **Files:** `app/Observability/OpenObserveClient.php:143` (`labels['role'] = config('dependencies.role','api')`), `config/dependencies.php:21`, `config/sentry.php:17` (same env key); docs say the value is per role (`docs/03-operations/telemetry.md:253`, `runtime-dependencies.md:17`).
- **Evidence (read-only, values of `JAVMETA_ROLE` only):** `JAVMETA_ROLE=api` on `.20`, `.21`, `.22`. Workers have `JAVMETA_NODE_ROLE=worker`, which is read by `javmeta_workers.role` and not by the telemetry role.
- **Impact:** the `role` label and Sentry `role` tag never separate worker from web/scheduler telemetry. Drift between docs and config.
- **Proposed fix:** set `JAVMETA_ROLE` per node in the wizard manifest (`web`, `scheduler`, `worker`), or derive the role label from `JAVMETA_NODE_ROLE`; add an env-drift check to the wizard `--validate-only`.
- **Proposed failing tests:** `ObservabilityConfigTest::test_role_label_reflects_node_role`.

### BH-ops-016 · S3 · `dep_up` telemetry is weaker than its name

- **File:** `app/Console/Commands/Observability/PublishMetricsCommand.php:738` (`Http::get($ooUrl/healthz)` then `successful() || status() < 500` — any 4xx counts as up), `:749` (`fsockopen` on the Mongo port — TCP only; a wrong password or stopped replica set reports up).
- **Impact:** `dep_up{dependency="mongo"}` and `dep_up{dependency="openobserve"}` can stay 1 while the dependency is unusable.
- **Proposed fix:** use the Mongo ping from `MongoProbe`; for OpenObserve accept 2xx only.
- **Proposed failing tests:** `PublishMetricsCommandTest::test_mongo_dep_up_requires_a_ping`.

### BH-ops-017 · S3 · Endpoint label uses the raw request path; the per-minute API query loads every row

- **Files:** `app/Http/Middleware/LogApiUsage.php` (endpoint = `'/' . ltrim($request->path(), '/')`); `routes/api.php:56-64` (`movies/{code}`, `performers/{id}`, `movies/{code}/related`); `PublishMetricsCommand.php:540-546` (`->get(['endpoint','status_code','response_ms'])` for the last minute, unbounded).
- **Summary:** Every distinct path becomes a metric label value (cardinality by movie code). The minute query loads all API usage rows into PHP memory with no limit (O(requests per minute)). Production today is small (`/api/v1/performers` 22 and `/api/v1/mcp` 22 rows in the last day), so this is latent.
- **Proposed fix:** use the route name or the matched route pattern for the label (`$request->route()?->uri()`), and aggregate with SQL `GROUP BY endpoint` plus a percentile approximation or a bounded sample.
- **Proposed failing tests:** `LogApiUsageTest::test_endpoint_label_uses_route_pattern`.

### BH-ops-018 · S3 · `join-node.sh`: option injection risk on clone/checkout; token on the command line

- **File:** `deploy/join-node.sh:72` (`jq -n --arg token "$token"`, token visible in `argv`), `:73` (`curl -d "$payload"`, payload with the token visible in `argv`), `:96` (`git clone --quiet "$repo_url" "$work/src"` — no `--`), `:97` (`git -C … checkout --quiet "$ref"` — no dash guard). `install.sh` guards the same values only later (`deploy/lib/install.sh:114`, "cannot start with a dash"), after the clone at `:341`.
- **Summary:** `repo_url` and `ref` come from the Command Center answer. A value beginning with `--` is parsed as an option by `git clone` (for example `--upload-pack=…`). The Command Center is trusted over HTTPS, so this is **SUSPECTED** exploitable only with a compromised or buggy server. The token in `argv` is one-time and short-lived; exposure is small.
- **Proposed fix:** `git clone -- "$repo_url"`; validate `repo_url` (`https://` only, no leading dash) and `ref` (`^[A-Za-z0-9._/-]+$`) before any git call; pass the payload via stdin (`curl -d @-`).
- **Proposed failing tests:** `tests/Shell/join-node.test.sh::test_refuses_option_like_repo_and_ref`.

### BH-ops-019 · S3 · `sudoers` is written in place and validated afterwards

- **File:** `deploy/production/node/install-node.sh:60-70` (`: > "$sudoers"`, appends, then `visudo -cf "$sudoers"` on the live file).
- **Summary:** A failure between the truncation and `visudo` leaves `/etc/sudoers.d/xcrawler-units` empty or invalid. Invalid files in `sudoers.d` break `sudo` for every account. Inputs are validated earlier, so the likelihood is low.
- **Proposed fix:** write to a temp file in the same directory, `visudo -cf temp`, then `install -m 0440` atomically.
- **Proposed failing tests:** `tests/Shell/production.test.sh::test_sudoers_write_is_atomic`.

### BH-ops-020 · S3 · Blocked startup commands raise an exception and show up in Sentry as errors

- **File:** `app/Services/Health/StartupCommandGate.php:44` (`throw new RuntimeException(…)`); `app/Console/Listeners/StartupCommandListener.php:24`.
- **Evidence:** Sentry group 130 is 3,160 error events of this one message (BH-ops-002).
- **Summary:** An expected, retryable block is reported as an unhandled error, drowning real errors. The `startup_blocked` telemetry already exists.
- **Proposed fix:** exit with a non-error status and a single-line log at `notice`; reserve Sentry for unexpected failures; add a rate limit to the `startup_blocked` emit.
- **Proposed failing tests:** `StartupCommandGateTest::test_blocked_command_is_not_reported_as_an_exception`.

### BH-ops-021 · S3 · Snapshot misses on the web path run the probes synchronously without single-flight

- **Files:** `app/Http/Middleware/StartupHealthMiddleware.php:27-31` (miss → `health->run('web', 0, false)` in the request); `app/Services/Health/StartupHealthSnapshotStore.php:28-47`.
- **Summary:** Every 5 s per FPM worker, the first request after expiry runs the full web probe set, including Elasticsearch (2 s timeout) and the settings store read. Concurrent requests in the same window all miss, so a slow dependency adds latency to all of them (thundering herd).
- **Reproduction:** by reading; no runtime run. **SUSPECTED** latency impact.
- **Proposed fix:** refresh the snapshot in the scheduler (`startup:snapshot` every 5 s) and serve only the stored value on the request path; if a miss is unavoidable, use a lock so one request probes and the rest serve the previous snapshot.
- **Proposed failing tests:** `StartupHealthMiddlewareTest::test_one_probe_per_snapshot_window`.

### BH-ops-022 · S3 · Node sample store: read-modify-write without a lock

- **File:** `app/Services/Report/NodeSampleStore.php:14-20` (`samples()` read, append, `Cache::put` at `:20` outside the lock; only the registry is locked at `:22`).
- **Summary:** Two concurrent `node:sample` runs for the same node can drop one sample. Impact is one missing point per race in a 180-point window.
- **Proposed fix:** take the per-node lock around read-modify-write, or store one key per sample with a TTL.
- **Proposed failing tests:** `NodeSampleStoreTest::test_concurrent_puts_keep_both_samples`.

---

## Checked and found OK

- **Startup gate precedence:** allowlist vs denylist order (`StartupCommandGate::mustCheck`), the settings-failure path restores code defaults before gating (`StartupCommandListener`, existing tests), diagnostics (`health:*`, `settings:*`, `migrate*`) exempt. Existing tests cover them.
- **Installer rollback signalling:** `trap 'false' INT TERM` routes signals into the `ERR` trap. Checked with a bash probe: the rollback runs and the script exits 1. Not a bug.
- **Atomic release swap:** `ln -s` + `mv -Tf` for `current`, and the same pattern for rollback. Verified by reading `activate_release`, `rollback`.
- **Deploy lock:** `flock -n` on `shared/deploy.lock` before any release change; environment digest re-check after answers collected.
- **Unit template sync:** `sync_node_units` is a subshell function, so its `local temporary` is still set when the `EXIT` trap runs. Dry run of `refresh-units.sh --dry-run` exits 0 with the expected `UPDATE:` lines and writes nothing.
- **Strict mode:** `set -Eeuo pipefail` in `install.sh`, `join-node.sh`, `refresh-units.sh`, `install-node.sh`, `backup.sh`, `rehearse.sh`, `restore.sh`, `staging-*.sh`; `pg_dump | age` runs under `pipefail`.
- **Retention:** `deploy/backup/retention.sh` validates the count, verifies every listed path is a direct child before deleting, and deletes nothing on listing failure.
- **join-node.sh input checks:** token regex, `name` regex, HTTPS-only unless opted in, `.env`/answers file mode 0600 and `shred`, cleanup trap, no secret printed.
- **Shared env keys:** `write_env` escapes `\`, `"`, `$` and rejects multiline values.
- **Time:** nodes are `Asia/Ho_Chi_Minh` with `NTPSynchronized=yes`; PostgreSQL `SHOW timezone` = `UTC`; PostgreSQL probe enforces UTC.
- **Postgres capacity:** `max_connections` = 200; 79 backends in use at check time (read-only).
- **Release consistency:** `current` on `.20`, `.21`, `.22` all point to commit `06540de`; worker nodes have no `xcrawler-scheduler` unit; timers enabled as expected.
- **Cache store:** `CACHE_STORE=redis` on all three nodes, so node samples and the startup snapshot are shared.
- **Metric names:** every `MetricStreams::NAMES[...]` key used by `PublishMetricsCommand` (including the `crawl_run_*_1h` dynamic keys) exists in `MetricStreams`.
- **Logs:** `storage/logs` has one file per day (Laravel daily driver); the oldest file present is 8 days old, so the retention window was not verified; no unbounded single file was seen.
- **Sudo scope on nodes:** the grant file only names `/usr/bin/systemctl` for the service account (no `ALL`).

## Could not verify

- **Reported `perf:warm` + `postgres, mongo` Sentry symptom (~1,400/day):** no matching group in projects 1, 2, 3 (`Group.message` icontains `perf:warm`, `postgres, mongo`). Possibly from another Sentry instance, older data, or a different message. Needs the original event link.
- **OpenObserve content:** the age metrics and `dep_up` values were not read from OpenObserve; conclusions for BH-ops-005/015 are from code and test.
- **Prometheus** (`http://192.168.10.10:9090`): not queried; no node-exporter-based cross-check of load or disk.
- **Runtime `/up` and ES behaviour in a live stack** (BH-ops-004/008): the testing environment does not prepend `StartupHealthMiddleware`; needs staging.
- **Sudoers effect of a real apply** (BH-ops-010): not run; no apply on servers.
- **Overlap of `obs:publish-metrics` in production:** no runtime evidence of two concurrent runs; only the missing guard and the query cost.
- **Query plans:** EXPLAIN on production data was not run (rule). Index conclusions come from `pg_indexes` and `pg_stat_user_tables` only.
- **Workers `.21`/`.22` release counts and disk:** read via `ls | wc -l` only; the `du` was run on `.20` only.
- **Not reviewed in depth (time/scope):** `deploy/backup/restore.sh`, `rehearse.sh`, `staging-restore-check.sh`, `deploy/lib/provision.sh`, `deploy/lib/readiness.php`, `deploy/lib/probe.php` (whether probe output can echo secrets into the wizard log), `SchedulerHeartbeat`, `CrawlDispatchHeartbeat`, `ApcuSnapshotMemory`, `SystemStatusService`, `StagingReadiness`, `tests/Shell/*` (not executed), `phpstan`/`pint` (not run).
- **`docs/03-operations/bug-hunt` other files and `deploy/observability/dashboards/*.json`:** not checked for stale metric names.

## Appendix: scratch tests (not committed; deleted after this report)

All files are under `tests/Scratch/`. Final run of `docker/ci/run php vendor/bin/phpunit tests/Scratch`: **Tests: 10, Assertions: 13, Errors: 2, Failures: 8, Passes: 0**. Every test is a reproduction of a defect; none is expected to pass until its fix lands. Errors are the gate tests, where the blocked command throws `RuntimeException`.

| File | Test | Result | Finding |
|---|---|---|---|
| `BugHuntOpsGateTest.php` | `test_horizon_worker_is_not_blocked_by_elasticsearch` | ERROR (blocked) | BH-ops-002 |
| `BugHuntOpsGateTest.php` | `test_scheduled_perf_warm_is_not_blocked_by_elasticsearch` | ERROR (blocked) | BH-ops-003 |
| `BugHuntOpsCarbonTest.php` | `test_now_diff_to_past_is_positive` | FAIL (0 vs 3600) | BH-ops-005 |
| `BugHuntOpsDependencyTest.php` | `test_second_request_reuses_dependency_probe_results` | FAIL (2 vs 1) | BH-ops-001 |
| `BugHuntOpsOpenObserveTest.php` | `test_console_shipping_timeout_honours_configured_timeout` | FAIL (0.2 vs 3.0) | BH-ops-007 |
| `BugHuntOpsPublishTest.php` | `test_claimed_queue_row_age_is_reported_in_seconds` | FAIL (0.0 vs 3600.0) | BH-ops-005 |
| `BugHuntOpsPublishTest.php` | `test_source_last_success_age_is_reported_in_seconds` | FAIL (0.0 vs 7200.0) | BH-ops-005 |
| `BugHuntOpsPublishTest.php` | `test_failed_openobserve_post_is_not_reported_as_published` | FAIL ("Published" printed on HTTP 500) | BH-ops-007 |
| `BugHuntOpsSettingsGateTest.php` | `test_settings_store_read_error_does_not_blame_a_reachable_mongo` | FAIL (blocked: mongo) | BH-ops-013 |
| `BugHuntOpsWebEsTest.php` | `test_web_report_is_not_blocking_when_only_search_is_down` | FAIL (blocking=true) | BH-ops-004 |

Test bodies (abridged to the assertion; the setup is the one shown in each file):

```php
// BugHuntOpsGateTest
$fixture = StartupHealthTestFixture::bind(['elasticsearch' => false]);
$gate = new StartupCommandGate($fixture['health'], app(ObservabilityEmitter::class));
$gate->handle('horizon:work');   // and 'perf:warm'
self::assertSame(0, $fixture['probes']['elasticsearch']->calls);

// BugHuntOpsCarbonTest
$now = CarbonImmutable::parse('2026-11-07 00:00:00', 'UTC');
self::assertSame(3600, max(0, (int) $now->diffInSeconds($now->subHour())));

// BugHuntOpsDependencyTest
$probe = new MutableDependencyProbe(true);
$probes = [Dependency::Telegram->value => $probe];
(new DependencyMonitor($probes))->statuses();
(new DependencyMonitor($probes))->statuses();
self::assertSame(1, $probe->calls);

// BugHuntOpsOpenObserveTest
config(['openobserve.timeout' => 3]);
$method = new ReflectionMethod(app(OpenObserveClient::class), 'shippingTimeoutSeconds');
self::assertSame(3.0, (float) $method->invoke($client));

// BugHuntOpsPublishTest (RefreshDatabase, travelTo 2026-11-02T12:34:00Z, openobserve.test fake)
CrawlQueue::factory()->create(['status' => CrawlQueue::STATUS_CLAIMED, 'claimed_at' => now()->subHour()]);
Http::fake(['openobserve.test/*' => Http::response(['code' => 200], 200)]);
$this->artisan('obs:publish-metrics')->assertSuccessful();
// assert the javmeta_crawl_queue_claimed_age_seconds data point == 3600.0
Source::factory()->create(['last_success_at' => now()->subHours(2)]);   // == 7200.0
Http::fake(['openobserve.test/*' => Http::response(['code' => 500], 500)]);
$this->artisan('obs:publish-metrics')->doesntExpectOutputToContain('Published');

// BugHuntOpsSettingsGateTest
StartupHealthTestFixture::bind();                       // all probes up
$store = Mockery::mock(ConfigStore::class);
$store->shouldReceive('refresh')->andThrow(new RuntimeException('store read timeout'));
app()->instance(ConfigStore::class, $store);
app()->forgetInstance(SettingsService::class);
app()->forgetInstance(StartupHealthService::class);
(new StartupCommandGate(app(StartupHealthService::class), app(ObservabilityEmitter::class)))->handle('crawler:reclaim');

// BugHuntOpsWebEsTest
$fixture = StartupHealthTestFixture::bind(['elasticsearch' => false]);
self::assertFalse($fixture['health']->run('web', 0, false)['blocking']);
```

Production reads performed (all read-only, no writes, no `kill`/`pkill`):
- SQL on `192.168.10.12` (`xcrawler`): 14 SELECT statements with `statement_timeout` 10 s (`SHOW timezone`, `pg_stat_activity` counts, `SHOW max_connections`, `pg_stat_user_tables`, `pg_indexes`, `pg_stat_database` `stats_reset`, `now()`, `api_usage_log` endpoint counts, `crawl_queue` claimed count and minimum `claimed_at`).
- Sentry (`192.168.10.17`, `sentry exec` read-only Django script): group lookups and `tsdb.get_sums` for project 2 and projects 1–3 message search. Five invocations.
- Nodes `.20`, `.21`, `.22` (`ssh` with `sudo -n` for root-owned files): `readlink -f current`, release counts, `du -sh` on releases (`.20`), env **key names** and `JAVMETA_ROLE`/`JAVMETA_NODE_ROLE`/`CACHE_STORE` values (non-secret), unit-file states, `/etc/sudoers.d/xcrawler-units` content, `timedatectl` (`.20`), `/etc/logrotate.d` listing, log directory sizes and message counts (`.20`). No secret value was printed.
- Prometheus not used.

---

## JOOXCR-379 — Event registration and dispatch inventory

**Snapshot:** `origin/develop` at `416bd43` (the local `develop` and `origin/develop` refs matched when this worktree was created). Commit `7525486` is not in this baseline's ancestry. This section records source-level behavior from that snapshot; it does not port the unmerged proposal.

**Scope:** application event classes in `app/Events`, their dispatch sites and listeners, plus framework events explicitly registered by application providers. Queue job dispatches and database records named “events” are outside this event-listener inventory. The code does not establish an approved business-group taxonomy; group labels below are provisional code areas.

### Registration model and ordering limits

- `composer.lock:1787-1789` pins `laravel/framework` to `v13.40.0`. `bootstrap/app.php:13-37` does not override event discovery; `bootstrap/providers.php:5-11` orders providers as `AppServiceProvider`, `CrawlerxServiceProvider`, `DependencyServiceProvider`, `HorizonServiceProvider`, then `SettingsServiceProvider`. Laravel 13 documents default discovery of typed `handle` / `__invoke` methods in `app/Listeners` ([event discovery](https://laravel.com/framework/docs/events#event-discovery)).
- `Event::listen` declarations append listener entries in the pinned dispatcher (`Dispatcher.php:137-142` in [Laravel framework v13.40.0](https://raw.githubusercontent.com/laravel/framework/v13.40.0/src/Illuminate/Events/Dispatcher.php)); the duplicate paths below therefore represent repeated registrations, not a deduplication convention.
- `APP_ENV=testing docker/ci/run php artisan event:list` confirms the listener map and runtime enumeration order for this environment. It reports `MirrorCrawlIncidentToOpenObserve` before `PersistCrawlIncident`, `SendMissedLookupAlert` before `RecordConsumerMissIncident`, and two entries each for `DownloadLinkSearchUpdated` and `SourceCircuitOpened`. `Looping` is listed as explicit and discovered `PauseWorkerWhenHardDependencyUnavailable` entries, followed by connection release. Provider conditions affect the map in other environments, so the output is an observed testing snapshot, not a claim about every deployment. Laravel documents that a listener returning `false` stops propagation ([event propagation](https://laravel.com/framework/docs/events#stopping-the-propagation-of-an-event)).

### Application event paths

#### Crawl incident persistence and mirroring — provisional code-area group

- `CrawlIncidentOccurred` is dispatched by `HandlesCrawlQueueRow::recordEvent()` (`app/Jobs/Concerns/HandlesCrawlQueueRow.php:133-136`), `SeedCommand` (`app/Console/Commands/Crawler/SeedCommand.php:92-97`), and the consumer-miss bridge (`app/Listeners/RecordConsumerMissIncident.php:16-27`). Laravel discovers `PersistCrawlIncident::handle()` and `MirrorCrawlIncidentToOpenObserve::handle()` (`app/Listeners/PersistCrawlIncident.php:10-21`; `app/Listeners/MirrorCrawlIncidentToOpenObserve.php:10-17`). The testing event list reports mirror before persist; application source does not declare that ordering.
- Existing evidence checks the persisted `queue_tick` outcome (`tests/Feature/Crawl/SeedCommandTest.php:24-38`) and the consumer-miss incident side effect (`tests/Feature/Alerts/ConsumerMissAlertTest.php:48-101`). These are behavior checks, not registration-count checks.

#### Consumer misses and crawl alerts — provisional code-area group

- `ConsumerMissReporter::report()` trims and ignores an empty query, then dispatches `ConsumerMissedLookup` (`app/Services/Miss/ConsumerMissReporter.php:16-24`). Discovery registers `SendMissedLookupAlert` and `RecordConsumerMissIncident` (`app/Listeners/SendMissedLookupAlert.php:13-29`; `app/Listeners/RecordConsumerMissIncident.php:14-27`). The testing event list reports the alert before the incident bridge; application source does not declare that order. Preserve the bridge into `CrawlIncidentOccurred` when ownership is split.
- `CrawlSourceUnhealthy` is dispatched for title-failure and stale-source cases (`app/Services/Crawl/TitleFailureTracker.php:34-38,64-75`; `app/Console/Commands/Crawler/WatchdogCommand.php:56-65`) and handled by discovered `SendCrawlAlert` (`app/Listeners/SendCrawlAlert.php:10-22`). `WorkerHeartbeatStale` is dispatched by `WatchdogCommand` (`app/Console/Commands/Crawler/WatchdogCommand.php:35-40`) and handled by discovered `SendWorkerHeartbeatAlert` (`app/Listeners/SendWorkerHeartbeatAlert.php:10-22`).
- `SourceCircuitOpened` is dispatched when the circuit opens (`app/Services/Crawl/SourceCircuitBreaker.php:45-51`) and handled by `SendSourceCircuitAlert` (`app/Listeners/SendSourceCircuitAlert.php:20-32`). That handler has both a discovered registration and an explicit `AppServiceProvider` registration (`app/Providers/AppServiceProvider.php:81`); this is a duplicate registration path.
- Dispatch and listener behavior have coverage in `tests/Unit/Services/Miss/ConsumerMissReporterTest.php:16-31`, `tests/Feature/Alerts/ConsumerMissAlertTest.php:34-123`, `tests/Feature/Crawl/TitleFailureTrackerTest.php:21-75`, `tests/Feature/Crawl/WatchdogCommandTest.php:33-74`, `tests/Feature/Crawl/SourceCircuitBreakerTest.php:20-73`, and `tests/Unit/Notifications/SendSourceCircuitAlertTest.php:19-42`. These tests do not establish discovery order or assert the duplicate registration count.

#### Download-link search — provisional code-area group

- `DownloadLinkSearchService` dispatches `DownloadLinkSearchUpdated` at its no-source, start, completion and failure paths (`app/Services/Downloads/DownloadLinkSearchService.php:134,162,198,206,224`). `RecordDownloadLinkSearchUpdate::handle()` is auto-discovered (`app/Listeners/RecordDownloadLinkSearchUpdate.php:14-27`) and is also explicitly registered by `AppServiceProvider` (`app/Providers/AppServiceProvider.php:67`), a second duplicate registration path. Current tests cover job dispatch (`tests/Unit/Services/Downloads/DownloadLinkSearchServiceTest.php:66`); a targeted search found no assertion for this event or its observability listener.

#### Node enrollment — provisional code-area group

- `NodeEnrollmentService` dispatches `NodeEnrollmentCreated` after creating an enrollment and `NodeEnrolled` after redemption (`app/Services/Nodes/NodeEnrollmentService.php:27-58`). `AppServiceProvider` explicitly subscribes `NodeEnrollmentSubscriber` (`app/Providers/AppServiceProvider.php:66`); its map registers `NodeEnrollmentCreated` → `created` before `NodeEnrolled` → `enrolled` (`app/Listeners/Nodes/NodeEnrollmentSubscriber.php:15-41`). Feature tests assert both dispatches (`tests/Feature/Nodes/NodeEnrollmentServiceTest.php:35-49,66-89`), but do not assert subscriber registration. The event and subscriber source state that enrollment tokens are not carried or logged.

#### Settings and crawler reconfiguration — provisional code-area group

- `SettingsService` dispatches `SettingsApplied` after applying stored settings and after a setting update (`app/Services/Settings/SettingsService.php:101-103,243-247`). `CrawlerxServiceProvider` registers a closure that rebuilds crawler configuration when browser URL settings change (`app/Providers/CrawlerxServiceProvider.php:23-42`). `tests/Unit/Providers/CrawlerxServiceProviderTest.php:47-58` checks the fetch-chain update.

#### Draft capture — listener not found; intent unresolved

- `FetchDetailJob` dispatches `MovieDraftCaptured` only when no `MovieDraftSink` is bound (`app/Jobs/FetchDetailJob.php:128-138`). No matching listener or explicit registration was found in current source, and it is absent from the testing event list. The event comment calls this a marker for a future capture path (`app/Events/MovieDraftCaptured.php:10-19`). Record it as currently unhandled; the inventory does not claim this is a defect or define the desired consumer.

### Framework event registrations

- `Looping`: `PauseWorkerWhenHardDependencyUnavailable` is both discovered (`app/Listeners/PauseWorkerWhenHardDependencyUnavailable.php:10-23`) and explicitly registered by `DependencyServiceProvider` (`app/Providers/DependencyServiceProvider.php:46-49`). `DisconnectIdlePostgresConnections::afterQueueLoop()` is registered later from the `AppServiceProvider` booted callback (`app/Providers/AppServiceProvider.php:71-80`). The testing event list shows both pause-handler entries before connection release. The dependency listener returns `false` when a hard dependency is down; that path stops later listeners. Its direct behavior is tested in `tests/Unit/Listeners/PauseWorkerWhenHardDependencyUnavailableTest.php:31-66`, while connection-release coverage is in `tests/Integration/DisconnectIdlePostgresConnectionsTest.php:33-56`. Tests do not assert the combined registration order or duplicate count.
- `JobProcessing`: `WorkerBusyStateListener::processing()` is explicitly registered first by `AppServiceProvider` (`app/Providers/AppServiceProvider.php:68`); `SettingsServiceProvider` registers a settings-apply closure later, only outside unit tests and when `app.key` is present (`app/Providers/SettingsServiceProvider.php:25-39`). `JobProcessed` and `JobFailed` use `WorkerBusyStateListener::processed()` (`app/Providers/AppServiceProvider.php:68-70`). `tests/Unit/Providers/SettingsServiceProviderTest.php:25-41` checks listener presence, not order.
- `CommandStarting`: `StartupCommandListener` is registered by `SettingsServiceProvider` only when outside unit tests with an application key (`app/Providers/SettingsServiceProvider.php:25-39`). `DisconnectIdlePostgresConnections::afterScheduleWorkStarted()` is registered from the later `AppServiceProvider` booted callback (`app/Providers/AppServiceProvider.php:71-80`); `tests/Integration/DisconnectIdlePostgresConnectionsTest.php:121-143` checks that registration and behavior.
- `MasterSupervisorLooped`: `HorizonServiceProvider` conditionally registers `HorizonLayoutSnapshot` during provider boot (`app/Providers/HorizonServiceProvider.php:25-34`); the later booted callback adds the connection-release listener (`app/Providers/AppServiceProvider.php:71-80`). `tests/Unit/Providers/HorizonServiceProviderTest.php:59-75` checks snapshot listener presence; `tests/Integration/DisconnectIdlePostgresConnectionsTest.php:78-102` checks connection release. `SupervisorLooped`, `ScheduledTaskFinished`, and `ScheduledTaskFailed` use the same connection-release listener from that callback. `RouteMatched` has one explicit policy closure in `app/Providers/AppServiceProvider.php:83-92`.

### Registration findings and limits

- Three listeners have two registration paths under Laravel 13 default discovery: `PauseWorkerWhenHardDependencyUnavailable`, `RecordDownloadLinkSearchUpdate`, and `SendSourceCircuitAlert`. The testing event list confirms two entries for each. Preserve the current multiplicity, order, and resulting listener invocations in any behavior-preserving refactor; an exact-count test must assert this current map rather than assume one entry. For `DownloadLinkSearchUpdated`, each invocation calls `emitOps()`, so each dispatch currently makes two observability-emission calls. For `SourceCircuitOpened`, each invocation calls `sendTemplate()` and existing debounce semantics still apply. For `Looping`, both pause-listener registrations precede connection release in the testing snapshot; a `false` return stops propagation, so the connection-release listener is skipped, while a continuing invocation allows later listeners to run. Removing any registration or changing these effects is a separate behavior change requiring explicit authorization, acceptance criteria, and tests.
- `CrawlIncidentOccurred` and `ConsumerMissedLookup` each have two discovered listeners. The testing event list reports their current enumeration order, but application source does not declare it. The node subscriber's event-to-method order is explicit as listed above.
- Commit `7525486` is proposal-only, and its subscriber group names are not evidence of approved business ownership. The proposal sets `withEvents(discover: false)` in [`bootstrap/app.php`](https://github.com/jooservices/javmeta/blob/752548649a0c33f7556f103b339b8bf42d191799/bootstrap/app.php#L12-L36) and registers six subscribers in [`AppServiceProvider`](https://github.com/jooservices/javmeta/blob/752548649a0c33f7556f103b339b8bf42d191799/app/Providers/AppServiceProvider.php#L54-L61). Those maps do not register `DownloadLinkSearchUpdated`, `SourceCircuitOpened`, `RecordConsumerMissIncident`, or any `DisconnectIdlePostgresConnections` framework listener, so those current paths would be lost if the proposal were ported as written. The proposal also adds a `MovieDraftCaptured` listener. Recheck every edge before reusing any proposal.
- Registration tests should verify exact multiplicity and observable order for the cases where order affects behavior. Current tests chiefly verify dispatch or listener outcomes; `event:list` is runtime evidence for the testing environment, not an automated regression assertion.

### Candidate follow-up slices

All labels below are **provisional code-area slices**, not confirmed business units. Treat the shared registration contract as a prerequisite and give its provider/bootstrap files one owner. After it lands, group slices own disjoint event, service, listener and test paths; they do not edit central provider files or each other's tests.

The subscriber strategy is a prerequisite decision: keeping default discovery while also explicitly subscribing typed `handle` methods can register handlers twice; disabling discovery requires explicit registration for every current listener path. Decide this once in slice 1, verify the complete listener map and order, and preserve the current multiplicity and observable effects of all three duplicate paths. Do not remove registrations as cleanup in a behavior-preserving slice; any deduplication is a separate, explicitly authorized behavior change with its own acceptance criteria and tests. Then let each group slice own only its subscriber mapping and behavior files.

1. **Registration and framework lifecycle baseline (prerequisite):** own `bootstrap/providers.php`, `app/Providers/{AppServiceProvider,CrawlerxServiceProvider,DependencyServiceProvider,HorizonServiceProvider,SettingsServiceProvider}.php`, and provider / connection-listener registration tests. Assert the existing multiplicity and order of all three duplicate paths: both `PauseWorkerWhenHardDependencyUnavailable` registrations before connection release on `Looping`, both `RecordDownloadLinkSearchUpdate` registrations (and their two `emitOps()` calls per dispatch), and both `SendSourceCircuitAlert` registrations (retaining `sendTemplate()` calls and current debounce semantics). Preserve provider conditions and explicit ordering for `JobProcessing`, `CommandStarting`, `Looping`, and `MasterSupervisorLooped`; test that the `Looping` early `false` return suppresses later listeners, including connection release. De-duplication is out of scope for this behavior-preserving prerequisite and requires a separate authorized change with explicit acceptance criteria and tests.
2. **Crawl incident persistence and mirroring:** own a crawl-incident subscriber mapping, `CrawlIncidentOccurred`, its `HandlesCrawlQueueRow` and `SeedCommand` producers, `PersistCrawlIncident`, `MirrorCrawlIncidentToOpenObserve`, and crawl incident tests. Verify persisted and mirrored outcomes, including a failed or unavailable sink; leave the consumer-miss bridge to slice 3.
3. **Consumer misses and crawl alerts:** own a notification subscriber mapping for `ConsumerMissedLookup`, `CrawlSourceUnhealthy`, `WorkerHeartbeatStale`, and `SourceCircuitOpened`; also own their reporter / tracker / watchdog / breaker paths, `RecordConsumerMissIncident`, alert listeners, and alert-focused tests. Verify empty-query suppression, alert/recovery transitions, and preservation of the cross-event crawl-incident write. Keep `SourceCircuitOpened`'s current two listener registrations and their order/invocations; any deduplication and resulting alert/debounce change needs separate authorization, acceptance criteria, and tests.
4. **Download-link search:** own a download-search subscriber mapping, `DownloadLinkSearchUpdated`, `DownloadLinkSearchService`, `RecordDownloadLinkSearchUpdate`, and download-search tests. Verify the event payload and the current two `emitOps()` calls per dispatch path; any deduplication and resulting observability-count change needs separate authorization, acceptance criteria, and tests.
5. **Node enrollment:** own a node-enrollment subscriber mapping, `NodeEnrollmentCreated`, `NodeEnrolled`, `NodeEnrollmentService`, `NodeEnrollmentSubscriber`, and enrollment tests. Preserve both event-to-method mappings, ensure failures do not log enrollment credentials, and assert subscriber wiring.
6. **Settings and crawler reconfiguration:** own a settings subscriber mapping, `SettingsApplied`, `SettingsService`, crawler reconfiguration behavior and focused settings-service tests. Verify both settings-apply paths and browser reconfiguration; central provider wiring stays with slice 1.
7. **Draft capture:** do not schedule an implementation slice until the intended `MovieDraftCaptured` consumer is decided. A no-listener finding alone is not acceptance to add one.

The framework lifecycle listeners are technical infrastructure rather than an established business group. Keep their registration and failure-order contract in slice 1; do not mix them into a domain slice solely to reduce file count.
