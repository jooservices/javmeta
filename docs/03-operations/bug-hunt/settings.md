# Bug hunt: Settings, Command Center, node enrollment and fleet (JOOXCR-414)

Scope: `app/Services/Settings/**`, `config/javmeta_settings.php`, settings controllers, `app/Services/Nodes/**`,
`NodeEnrollmentService` / `JoinBundleFactory`, `deploy/join-node.sh`, Operations (Command Center) controllers and
`resources/views/operations`, `app/Services/Workers/**` (Horizon reconcile), and the enrollment/login throttles.

Method: code read end to end, then each suspicion was either reproduced with a scratch PHPUnit test run through
`docker/ci/run` (the scratch file is listed in Appendix A and was deleted after the run), or checked against a
read-only production query, or labelled SUSPECTED. Branch `JOOXCR-bughunt-settings` from `JOOXCR-373-readiness`.
No application code or tests were changed. Dev dependencies were installed into the worktree's ignored `vendor/`.

## Counts

| Severity | Count | Ids |
|---|---|---|
| S1 (security / data loss / outage) | 1 | 001 |
| S2 (wrong result / visible defect / principle violation on a hot path) | 4 | 002, 003, 004, 005 |
| S3 (minor / robustness) | 8 | 006 to 013 |

Confirmed by running a test: 001, 002, 003. Confirmed by code reading: 004, 006, 007, 008, 010, 012.
Suspected: 005 (impact), 009, 011, 013. Out-of-scope symptom from Sentry: 014.

## Fix first (ranked)

1. **BH-settings-001**: stop trusting `X-Forwarded-For` from every peer. Login and enrollment throttles are both
   bypassed by a header the attacker writes. This is the only S1.
2. **BH-settings-002**: do not consume an enrollment token until the bundle can be built (no `REPO_URL` means a dead token).
3. **BH-settings-003**: make a bulk settings save all-or-nothing (validate every key first, then one `setMany`).
4. **BH-settings-005**: cache the Command Center dashboard snapshot; stop exact `COUNT(*)` on the 310k-row `movies`
   and 157k-row `performers` tables and the `GROUP BY` over the 664k-row `crawl_queue` on every 15-second poll.
5. **BH-settings-004**: narrow what a join bundle carries and move the token off argv (see 012); document that
   `APP_KEY` in the bundle decrypts every Encrypted setting.
6. **BH-settings-011**: validate cross-key timeout invariants inside the same write (lock or versioned write).
7. **BH-settings-009**: bound the total Horizon process count against the PostgreSQL connection budget (JOOXCR-392).
8. **BH-settings-006**: stop running `SettingsService::apply()` on every `JobProcessing`; reload only when the version changes.
9. **BH-settings-012**: keep the enrollment token out of `curl -d "$payload"` argv.
10. **BH-settings-007**: stop silently trimming secrets and free-text values, or say so in the UI.

---

## Findings

Severity legend: S1/S2/S3 as in the header. "Root cause" is CONFIRMED when a test or query shows it, SUSPECTED otherwise.

| Id | Sev | file:line | Summary | Root cause |
|---|---|---|---|---|
| BH-settings-001 | S1 | `bootstrap/app.php:28-31`, `routes/web.php:34`, `:55` | Forwarded headers are trusted from any peer, so the per-IP login and enrollment throttles (6/min) are bypassed by rotating `X-Forwarded-For`. Enrollment `used_from_ip` audit data is spoofable too. | CONFIRMED |
| BH-settings-002 | S2 | `app/Services/Nodes/NodeEnrollmentService.php:50-58`, `app/Services/Nodes/JoinBundleFactory.php:101-102` | The token is consumed before the bundle is validated. With `javmeta_nodes.release_repo_url` unset, the first redeem returns 200 without `REPO_URL`, the join script aborts, and the token is dead. | CONFIRMED |
| BH-settings-003 | S2 | `app/Http/Controllers/Ui/UiSettingsController.php:23-35` | Bulk save loops key by key. Only `InvalidArgumentException` is caught. A store failure midway leaves earlier keys written and returns 500 without the per-key contract. | CONFIRMED |
| BH-settings-004 | S2 | `app/Services/Nodes/JoinBundleFactory.php:94-110`, `app/Services/Nodes/NodeEnrollmentService.php:44` | One single-use token delivers `APP_KEY`, the Postgres, Mongo, Redis, ES and OpenObserve passwords to whoever redeems it. The token sits in the Command Center page and in the operator's shell history. | CONFIRMED (by design; blast radius is the issue) |
| BH-settings-005 | S2 | `app/Services/Operations/OperationsDashboardService.php:35`, `:84-88`; `app/Repositories/MovieRepository.php:83-86`, `:130-133`; `app/Repositories/CrawlQueueRepository.php:29-36`; `resources/views/operations/dashboard.blade.php:161` | Every open dashboard tab polls `/ops/data` every 15 s. Each poll runs exact `COUNT(*)` on movies and performers plus a `GROUP BY` over the whole crawl queue. Breaks "a request costs O(page size)". | Code CONFIRMED; production load impact SUSPECTED (not measured) |
| BH-settings-006 | S3 | `app/Providers/SettingsServiceProvider.php:38` | `apply()` runs on every `JobProcessing`. Each job pays a cache-version read and re-emits `SettingsApplied` with all saved keys. | CONFIRMED by code; cost not measured |
| BH-settings-007 | S3 | `app/Services/Settings/SettingsService.php:680` | Strings and secrets are `trim()`-ed before storage. A credential with leading or trailing whitespace is saved altered, with no warning. | CONFIRMED by code; practical impact SUSPECTED |
| BH-settings-008 | S3 | `app/Services/Settings/SettingsService.php:804` vs `:824` | The JSON length check counts the unescaped UTF-8 form (`JSON_UNESCAPED_UNICODE`), but storage uses the escaped form. CJK text can store up to ~6x the limit. | CONFIRMED by code; impact SUSPECTED (no hard column limit found) |
| BH-settings-009 | S3 | `app/Services/Workers/HorizonWorkerLayout.php:35` | Each queue is capped at 32 processes, but nothing caps the total across queues and nodes against the PostgreSQL connection budget (JOOXCR-392). | SUSPECTED (branch not reviewed) |
| BH-settings-010 | S3 | `app/Services/Workers/HorizonWorkerReconciler.php:143` | `max(600, config(...reconcile_cooldown_seconds))` silently raises any smaller configured cooldown to 600 s. | CONFIRMED by code (may be intended; undocumented) |
| BH-settings-011 | S3 | `app/Services/Settings/SettingsService.php:974-1020` (`assertTimeoutRelationshipsForValues`) | Cross-key timeout invariants are validated against a snapshot read, then written by a separate call. Two admins saving related keys at once can both pass. | SUSPECTED (needs a concurrency test) |
| BH-settings-012 | S3 | `deploy/join-node.sh:72-73`, `:100` | The token travels in `curl -d "$payload"` argv (visible in `/proc/<pid>/cmdline` for up to 30 s) and in `bash -s -- --token` argv on the new VM. | CONFIRMED by code |
| BH-settings-013 | S3 | `deploy/join-node.sh:43-45` | The script refuses `http://` unless `JAVMETA_JOIN_ALLOW_HTTP=1`. The address comes from `APP_URL`, so a LAN deployment on `http://` gets a join command that always fails. | SUSPECTED (config trap; production `APP_URL` not checked) |
| BH-settings-014 | S3 (out of scope) | `app/Services/Crawl/SourceRequestPacer.php:37-43` | Sentry group 167/168/169 (`TypeError: Redis::eval(): Argument #3 ($num_keys) must be of type int, array given`): ~1,800 events in 7 days, last seen 2026-11-07 00:18 UTC. Crawl pacing is hitting this on the phpredis path. | Sentry CONFIRMED; cause in the call shape SUSPECTED |

### BH-settings-001 (S1) X-Forwarded-For defeats the login and enrollment throttles

- **file:line**: `bootstrap/app.php:28-31` (`trustProxies(at: '*', headers: X_FORWARDED_FOR | ...)`), `routes/web.php:34` (`throttle:6,1` on `POST /login`), `routes/web.php:55` (`throttle:6,1` on `POST /node/enroll`).
- **Reproduction**: scratch tests `test_rotating_forwarded_for_defeats_the_login_rate_limit`,
  `test_rotating_forwarded_for_defeats_the_enrollment_rate_limit`, and
  `test_rotating_the_forged_part_of_an_appended_forwarded_for_defeats_the_login_limit` (Appendix A).
  Run: `docker/ci/run php vendor/bin/phpunit tests/Scratch/BugHuntSettingsTest.php`.
- **Evidence**:
  - Login, 12 attempts, each with a different `X-Forwarded-For`: `302,302,302,...` (no 429). A password guesser is never limited.
  - Enrollment, 12 attempts with rotating `X-Forwarded-For`: all 404, no 429.
  - Control, same address, no header: `404,404,404,404,404,429,429,429`. The limit works without the header, so the bypass is the header.
  - Production shape: HAProxy runs `option forwardfor` (`deploy/production/haproxy/xcrawler.cfg:21`), which appends the real peer, so the header is `"<forged>, <real>"`. Forging only the leftmost value still gives 12/12 302. Trusting every peer means the leftmost (client-written) value is what Laravel uses.
- **Root cause (CONFIRMED)**: `trustProxies(at: '*')` lets any peer, including a direct client, set the client IP. Rate limits keyed on `$request->ip()` are therefore client-controlled. `used_from_ip` in `node_enrollments` is also client-controlled.
- **Fix**: trust only the HAProxy address(es), e.g. `trustProxies(at: [env('TRUSTED_PROXIES')])`, and key the login throttle on the normalised email plus the IP. Store `used_from_ip` from the trusted address only.
- **Named failing tests**: `test_login_throttle_ignores_forwarded_for_from_an_untrusted_peer`, `test_enrollment_throttle_ignores_forwarded_for_from_an_untrusted_peer`, `test_used_from_ip_is_the_trusted_proxy_chain_result`.

### BH-settings-002 (S2) The token is burned before the bundle is complete

- **file:line**: `NodeEnrollmentService.php:50-58` (`redeem` marks used in the repository, then calls `$this->bundles->for(...)`); `JoinBundleFactory.php:101-102` (`REPO_URL` and `REF` are `null` when unset, and `strings()` drops nulls); `deploy/join-node.sh:91-93` (dies on missing repo URL).
- **Reproduction**: scratch test `test_token_is_burned_even_when_the_bundle_lacks_the_release_repository`:
  create a grant, `config(['javmeta_nodes.release_repo_url' => null])`, redeem, then redeem again.
- **Evidence**: first redeem `200`, `REPO_URL present: no`; second redeem `404`. The operator gets "The Command Center has no release repository or ref set", but the token is gone, so after the config is fixed a new token must be generated.
- **Root cause (CONFIRMED)**: `redeem` is a one-way state change committed before the bundle is validated; nothing checks the bundle is usable.
- **Fix**: validate bundle prerequisites (`javmeta_nodes.release_repo_url`, `release_ref`) before the atomic update, or run the update and bundle build in one transaction and roll back on failure. Respond 503 with a clear message when config is missing, without burning the token.
- **Named failing tests**: `test_token_is_not_consumed_when_the_bundle_cannot_be_built` (expects second redeem 200 after config fix).

### BH-settings-003 (S2) Bulk settings save is not atomic

- **file:line**: `UiSettingsController.php:23-35` (per-key `set()` in a loop; only `InvalidArgumentException` is caught); `SettingsService.php:222-258` (each `set()` does its own store write and cache bump). `ConfigStore::setMany` exists and is documented as "single cache version bump".
- **Reproduction**: scratch test `test_a_bulk_settings_save_is_not_atomic_when_the_store_fails_midway`. A mocked `ConfigStore` accepts the first `set` and throws `RuntimeException('store unavailable')` on the second; `update()` is called with two valid keys.
- **Evidence**: `first key written=["settings.media.scene_threshold"] then exception: store unavailable`. One key is saved, the second is not, and the exception propagates as a 500. The client cannot tell which keys were applied. The same path also calls `ObservabilityEmitter::emitOps` per key, and that call is not wrapped, so an ops-emit failure after a save also yields 500 with the value already saved.
- **Root cause (CONFIRMED)**: validation and persistence are interleaved per key; the error contract only models validation errors.
- **Fix**: validate every key first (collect errors, return 422 with none written), then persist with one `setMany`. Wrap the emit so an audit failure is logged, not turned into a 500. Return `applied` and `errors` consistently.
- **Named failing tests**: `test_bulk_save_is_all_or_nothing_when_the_store_fails`, `test_bulk_save_with_one_invalid_key_writes_nothing`.

### BH-settings-004 (S2) One token hands over the fleet's secrets

- **file:line**: `JoinBundleFactory.php:94-110` (`APP_KEY`, `DB_PASSWORD`, `MONGO_PASSWORD`, `REDIS_PASSWORD`, `ELASTICSEARCH_API_KEY`, `OPENOBSERVE_PASSWORD`, `MONGO_URI` with credentials); `NodeEnrollmentService.php:44` (the token is embedded in a copy-paste command).
- **Reproduction**: the scratch test `test_node_name_with_trailing_newline_...` prints the exact `command` returned by `POST /ops/nodes` (token in clear text, e.g. `curl -fsSL https://cc.example.test/node/join.sh | sudo bash -s -- --token jne_... --name worker-1`). The redeem response in `test_token_is_burned_...` includes the answer keys.
- **Evidence**: the bundle contains `APP_KEY`. Laravel's `laravel-config` Encrypted settings (`ui_security.owner_admin_token`, `ui_security.mcp_api_key`, `downloads.qbittorrent_password`) are encrypted through the container's Laravel `Encrypter` (`ConfigService.php:440`, `:607`), which uses `APP_KEY`, so a leaked token plus the bundle also decrypts those values from the Mongo document.
- **Root cause (CONFIRMED by design)**: the join design gives a new VM every secret it needs, through a bearer token that is shown on screen and copied into shell history. Single use and 30-minute TTL limit replay, not disclosure.
- **Fix**: give each node its own DB, Mongo and Redis credentials (or only what the role needs); keep `APP_KEY` out of the bundle, or deliver it over a separate authenticated channel; add a confirmation step on the Command Center page; document the blast radius in `docs/decisions/node-enrollment.md`.
- **Named failing tests**: `test_join_bundle_for_a_worker_excludes_the_app_key`, `test_join_bundle_contains_only_the_role_secrets`.

### BH-settings-005 (S2) The Command Center poll costs full-table scans

- **file:line**: `OperationsDashboardService.php:35` (`totalsBySourceAndStatus` over `crawl_queue`), `:84` (`movies->countAll()`), `:85` (`performers->count()`), `:87` (`failedJobs->count()`), `:86` (`countFirstSeenSince`); `dashboard.blade.php:161` (`setInterval(refresh, 15000)`).
- **Reproduction**: open `/ops` in one browser tab and leave it. Each tick calls `/ops/data`. No caching, no snapshot TTL.
- **Evidence** (read-only production, `pg_stat_user_tables.n_live_tup`, 2026-11-07; planner estimates, no `count(*)`):
  `crawl_events` 820,241; `crawl_queue` 663,792; `movies` 309,986; `performers` 156,702; `failed_jobs` 2,110.
  So each tick is an exact count over ~310k movies and ~157k performers, plus a group-by over ~664k queue rows.
  Four ticks per minute per open tab. The impact on the primary is not measured (EXPLAIN on production was not allowed).
- **Root cause**: the snapshot is recomputed per request, and totals use exact aggregates.
- **Fix**: cache the snapshot for 30 to 60 s (`Cache::remember`); use `pg_class`/`n_live_tup` estimates for the totals; keep the queue breakdown behind the cache.
- **Named failing tests**: `test_dashboard_snapshot_is_cached_between_polls`, `test_dashboard_totals_do_not_use_exact_count_on_large_tables`.

### BH-settings-006 (S3) Settings reload on every job

- **file:line**: `SettingsServiceProvider.php:38` (`Event::listen(JobProcessing::class, ... apply())`); `SettingsService.php:53-107` (`apply()` loops every definition, then `event(new SettingsApplied($applied))` with every saved key, which `CrawlerxServiceProvider.php:26` listens to).
- **Evidence**: code reading. `ConfigService::ensureLoaded` reads the cache version on each call, so each job costs at least one cache-table read, plus a loop over ~300 definitions and one event dispatch with all saved keys.
- **Root cause (CONFIRMED by code)**: reload is tied to every job, not to a version change.
- **Fix**: call `apply()` only when the stored version changed (compare `ConfigStore` version), and emit `SettingsApplied` only for keys whose effective value changed.
- **Named failing tests**: `test_apply_does_not_emit_settings_applied_when_nothing_changed`.

### BH-settings-007 (S3) Secrets and free text are silently trimmed

- **file:line**: `SettingsService.php:680` (`$text = trim((string) $value);` before storage for `string` and `secret`).
- **Evidence**: code reading; the value that is stored is not the value that was typed.
- **Root cause (CONFIRMED by code)**: trimming applies to secrets as well.
- **Fix**: trim only non-secret fields for display, or reject leading or trailing whitespace in secrets with a clear message.
- **Named failing test**: `test_secret_with_surrounding_whitespace_is_rejected_or_kept_verbatim`.

### BH-settings-008 (S3) JSON length check and stored size disagree

- **file:line**: `SettingsService.php:804` (length measured with `JSON_UNESCAPED_UNICODE`) vs `:824` (`toStorage` json_encode without it).
- **Evidence**: code reading. A CJK value that passes the check as N code points is stored as up to 6N characters of `\uXXXX`.
- **Root cause (CONFIRMED by code)**: two encodings, one limit. Practical impact SUSPECTED (the `worker.*_overrides` maps have no `max_length`; Mongo document limit is 16 MB).
- **Fix**: measure the exact stored bytes (`strlen` of the encoded value) in `validateJson`.
- **Named failing test**: `test_json_length_limit_counts_the_stored_bytes`.

### BH-settings-009 (S3) No total Horizon process budget (SUSPECTED)

- **file:line**: `HorizonWorkerLayout.php:35` (`min(32, ...)` per queue only); `HorizonWorkerReconciler.php:138` (scales by queue, no total).
- **Evidence**: no total check in the layout, reconciler or settings validator (`invalidWorkerSettings` checks only the timeout order). I did not review the JOOXCR-392 branch, so the budget may live there.
- **Fix**: compute `sum(maxProcesses) * per-process connections` and reject layouts above the budget in `invalidWorkerSettings`.
- **Named failing test**: `test_worker_layout_above_the_connection_budget_is_invalid`.

### BH-settings-010 (S3) Cooldown silently raised to 600 s

- **file:line**: `HorizonWorkerReconciler.php:143`.
- **Evidence**: code reading. A configured `reconcile_cooldown_seconds` below 600 is ignored without a log line.
- **Fix**: validate the setting's minimum in the definition, or log when the floor applies.
- **Named failing test**: `test_reconcile_cooldown_floor_is_reported`.

### BH-settings-011 (S3) Cross-key invariants are checked on a snapshot (SUSPECTED)

- **file:line**: `SettingsService.php:974-1020` (`assertTimeoutRelationshipsForValues` reads the other keys, then `set()` writes), `SettingsService.php:222-226`.
- **Evidence**: two concurrent `PUT /ui-admin/settings` calls, one raising `queue.worker_timeout` and one lowering `queue.retry_after_seconds`, can each pass against the other's old value. Not run (needs two concurrent requests).
- **Fix**: validate and write inside one lock (`Cache::lock` on the settings group) or with a version check on write.
- **Named failing test**: `test_concurrent_timeout_edits_cannot_both_pass`.

### BH-settings-012 (S3) Token in argv

- **file:line**: `deploy/join-node.sh:72-73` (`curl ... -d "$payload"`; the payload contains the token), `:100` (the installer is run with the token already consumed, but the `bash -s -- --token jne_...` invocation keeps it in argv on the VM).
- **Evidence**: code reading. The token is short-lived and single-use, so exposure is limited, but another local user on the VM can read argv during the 30-second request.
- **Fix**: send the payload with `--data-binary @-` on stdin, or from a `0600` file; read the token from stdin or a file, not argv.
- **Named failing test**: a shellcheck and dry-run test (`--plan` exists; add a redeem-stub test that asserts no token in `/proc/*/cmdline`).

### BH-settings-013 (S3) Plain-HTTP APP_URL breaks the join command (SUSPECTED)

- **file:line**: `deploy/join-node.sh:43-45`; `NodeEnrollmentService.php:44` (uses `config('app.url')`).
- **Evidence**: the script refuses `http://` by design. If `APP_URL` is `http://...` the generated command cannot succeed unless the operator adds `JAVMETA_JOIN_ALLOW_HTTP=1`, which the Command Center page does not show. Production `APP_URL` was not checked.
- **Fix**: detect `http` in `NodeEnrollmentService::create` and warn in the UI.
- **Named failing test**: `test_join_command_warns_when_app_url_is_plain_http`.

### BH-settings-014 (S3, out of scope) SourceRequestPacer eval errors in Sentry

- **file:line**: `app/Services/Crawl/SourceRequestPacer.php:37-43`.
- **Evidence (Sentry, project 2, read-only Django shell)**: groups 167 (904 events), 168 (597), 169 (299): `TypeError: Redis::eval(): Argument #3 ($num_keys) must be of type int, array given`. Last seen 2026-11-07 00:18 UTC. The pacer call passes the key count as argument 2 and the arguments as later ones. Whether the Laravel phpredis wrapper or this call is at fault was not checked.
- **Note**: the reconciler's `$redis->command('eval', [script, [keys], 1])` uses the correct phpredis order and is not affected (see "checked and found OK").
- **Owner**: crawl area; not fixed here.

---

## Checked and found OK

- **Enrollment token**: 256-bit random (`jne_` + 64 hex), stored only as SHA-256 (`token_hash` is unique, `migrations/2026_11_05_000001`). Redeem is one conditional `UPDATE ... WHERE used_at IS NULL AND expires_at > now AND node_name = ?` (`NodeEnrollmentRepository.php:51-67`), so two concurrent redeems cannot both win. A wrong node name does not burn the token (the UPDATE matches nothing).
- **Uniform 404** for unknown, wrong-node, used and expired (`NodeEnrollmentController.php:238-240`). Malformed token gives 401 without revealing anything.
- **TTL**: `max(5, ttl)` minutes; default 30; expiry compared with `>`.
- **Trailing newline in node name** (`"worker-1\n"`): accepted and stored as `worker-1`. The `TrimStrings` middleware runs before validation. Test `test_node_name_with_trailing_newline_...` shows `201` and no newline in the command.
- **Command Center authorisation**: every `/ops/*` and `/ui-admin/*` route is behind `auth` plus `EnsureSuperAdministrator` (`routes/web.php:57-94`). The `/ui-api/*` write routes are not used for settings.
- **CSRF**: `/ops/nodes` POST and `/ui-admin/settings` PUT are in the web group with CSRF enforced. Only `node/enroll` is excluded (`bootstrap/app.php:22`), and that endpoint is authenticated by the token.
- **XSS in the dashboard**: all API strings go through `esc()`, which escapes `& < > " '` (`dashboard.blade.php:127`), including in `title=` attributes.
- **Settings secrets**: `SettingsService::all()` never returns secret values, only `is_set` (`all()`, line 137 onward). The import preview shows `[redacted]` (`:564`). The three secret definitions (`ui_security.owner_admin_token`, `ui_security.mcp_api_key`, `downloads.qbittorrent_password`) are the only credential-looking keys in the definitions file.
- **URL settings**: `SettingUrl::validate` rejects userinfo, query and fragment, and its error messages do not echo the value. `redact()` strips userinfo from legacy values.
- **Float settings**: every `float` definition has a `max` (`javmeta_settings.php:97,150,265,266`), so `1e400` (INF) is rejected.
- **Settings propagation**: values are read through `laravel-config`, cache store default `database` (`config/cache.php:20`, shared PostgreSQL), with a cache-version key bumped on write. Other processes see a change at their next `apply()`, which runs per HTTP request (`booted`) and per job (`JobProcessing`). No window longer than the next request or job, except `bootstrap_only` and restart-required keys.
- **Timeout invariants on save**: `set()` checks the relationships against the new value (`SettingsService.php:224`, `:965-1000`); `storedWorkerValue` re-validates on read, so bad stored values are ignored with a warning, not applied.
- **Horizon reconcile locks**: NX+EX lock with a token-checked release (`HorizonWorkerReconciler.php:68-88`); `eval` argument order is correct for phpredis (`[script, [key, token], 1]`). The fleet lock is released on pre-queue failure only. Deploy lock is checked before and after the lock.
- **Horizon layout hash**: `hash_equals` on the snapshot hash; restart-required path is logged, not silently applied.
- **join-node.sh**: `set -Eeuo pipefail`; token and name validated by regex before use; JSON built with `jq --arg` (no shell injection); work directory `0700` with a trap cleanup; response file shredded; refuses plain HTTP; checks Ubuntu and root. `bash -n` passes.
- **Dashboard lists** (`issues`, `events`, `recent nodes`) are bounded (12, 12, 20) and the source list is bounded by enabled sources.

## Could not verify

- **Node egress probe** (scope item): not reviewed; no time left in this pass.
- **JOOXCR-392 connection budget**: the branch was not read; BH-009 is not confirmed against it. No `max_connections` or live Horizon counts were collected.
- **Concurrent settings edits** (BH-011): needs a concurrency test with two real requests.
- **Predis** path: `REDIS_CLIENT` defaults to phpredis. The reconciler's eval call would break under Predis (`num_keys` would be an array). Not tested.
- **Production settings values** (Mongo) and `APP_URL`: not queried. BH-013 is unconfirmed.
- **Prometheus, Redis, Elasticsearch**: not queried (not in scope for this pass).
- **Sentry MCP** was unavailable; the Django-shell route was used with a keyword filter over the 400 most recent groups. No enrollment or settings group appeared in that filter.
- **Cost of BH-006 and BH-005**: not measured; EXPLAIN on production was not allowed.

## Production access used

- PostgreSQL (`xcrawler`, read-only): 2 queries (`pg_stat_user_tables` row estimates, one with a 10 s statement timeout).
- Sentry (project 2, read-only): 1 Django-shell query over recent groups, `get_sums` not run.
- No writes, no `pkill`, no background processes, no compose, no `docker/ci/integration` or `docker/qa/*`.

## Appendix A: scratch test (deleted after the run, not committed)

Path: `tests/Scratch/BugHuntSettingsTest.php`. Run with `docker/ci/run php vendor/bin/phpunit tests/Scratch/BugHuntSettingsTest.php`.
Result at the time of the run: 6 tests, 8 assertions, OK (the newline test passes because `TrimStrings` runs first, so it documents "checked OK").

```php
<?php

declare(strict_types=1);

namespace Tests\Scratch;

use App\Models\NodeEnrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** SCRATCH reproduction for the JOOXCR-414 bug hunt. Not committed. */
final class BugHuntSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://cc.example.test', 'javmeta_nodes.release_repo_url' => 'https://git.example.test/x.git', 'javmeta_nodes.release_ref' => 'main']);
    }

    /** BH-settings-001 candidate: X-Forwarded-For is trusted from every peer, so rotating it defeats the per-IP throttle. */
    public function test_rotating_forwarded_for_defeats_the_enrollment_rate_limit(): void
    {
        $statuses = [];
        foreach (range(1, 12) as $attempt) {
            $statuses[] = $this->withHeader('X-Forwarded-For', '203.0.113.' . $attempt)
                ->postJson('/node/enroll', ['token' => 'jne_' . str_repeat('3', 64), 'node_name' => 'worker-1'])
                ->status();
        }
        fwrite(STDERR, "\nXFF-rotation statuses: " . implode(',', $statuses) . "\n");
        $control = [];
        foreach (range(1, 8) as $attempt) {
            $control[] = $this->postJson('/node/enroll', ['token' => 'jne_' . str_repeat('4', 64), 'node_name' => 'worker-1'])->status();
        }
        fwrite(STDERR, 'control (same address, no XFF) statuses: ' . implode(',', $control) . "\n");
        self::assertNotContains(429, $statuses, 'Rotating X-Forwarded-For should not bypass the per-address limit.');
    }

    /** Same bypass on the login throttle (6/min per address): a password guesser never hits 429. */
    public function test_rotating_forwarded_for_defeats_the_login_rate_limit(): void
    {
        $statuses = [];
        foreach (range(1, 12) as $attempt) {
            $statuses[] = $this->withHeader('X-Forwarded-For', '198.51.100.' . $attempt)
                ->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong-guess-' . $attempt])
                ->status();
        }
        fwrite(STDERR, "\nlogin XFF-rotation statuses: " . implode(',', $statuses) . "\n");
        self::assertNotContains(429, $statuses);
    }

    /** BH-settings-002 candidate: '$' in the PHP regex accepts a trailing newline, so the name is stored with it. */
    public function test_node_name_with_trailing_newline_is_stored_and_printed_in_the_join_command(): void
    {
        $admin = User::factory()->create(['role' => 'super_administrator']);
        $this->actingAs($admin);
        $response = $this->postJson('/ops/nodes', ['node_name' => "worker-1\n", 'role' => 'worker']);
        fwrite(STDERR, "\nnewline name status: " . $response->status() . "\n");
        $response->assertCreated();
        $command = (string) $response->json('data.command');
        fwrite(STDERR, 'join_command bytes: ' . json_encode($command) . "\n");
        self::assertStringNotContainsString("\n", $command);
        self::assertSame(0, NodeEnrollment::query()->where('node_name', "worker-1\n")->count());
    }

    /** BH-settings-003 candidate: the token is burned before the bundle is checked; a node with no repo URL gets a dead token. */
    public function test_token_is_burned_even_when_the_bundle_lacks_the_release_repository(): void
    {
        $grant = app(\App\Services\Nodes\NodeEnrollmentService::class)->create('worker-1', 'worker', null);
        config(['javmeta_nodes.release_repo_url' => null]);

        $first = $this->postJson('/node/enroll', ['token' => $grant->token, 'node_name' => 'worker-1']);
        fwrite(STDERR, "\nfirst redeem status: " . $first->status() . ' REPO_URL present: ' . (array_key_exists('REPO_URL', (array) $first->json('data.answers')) ? 'yes' : 'no') . "\n");
        $second = $this->postJson('/node/enroll', ['token' => $grant->token, 'node_name' => 'worker-1']);
        fwrite(STDERR, 'second redeem status: ' . $second->status() . "\n");
        self::assertSame(404, $second->status());
    }

    /** BH-settings-004 candidate: a bulk save stops at the first non-validation failure; earlier keys stay written, the answer is a 500. */
    public function test_a_bulk_settings_save_is_not_atomic_when_the_store_fails_midway(): void
    {
        $writes = [];
        $store = \Mockery::mock(\JOOservices\LaravelConfig\Contracts\ConfigStore::class);
        $store->shouldReceive('group')->andReturn([]);
        $store->shouldReceive('set')->andReturnUsing(function (string $path) use (&$writes): void {
            if (count($writes) >= 1) {
                throw new \RuntimeException('store unavailable');
            }
            $writes[] = $path;
        });
        $this->app->instance(\JOOservices\LaravelConfig\Contracts\ConfigStore::class, $store);
        $this->app->forgetInstance(\App\Services\Settings\SettingsService::class);

        $request = \Illuminate\Http\Request::create('/', 'PUT', ['settings' => ['media.scene_threshold' => 0.5, 'retry.pacing_gap_multiplier' => 2]]);
        try {
            app(\App\Http\Controllers\Ui\UiSettingsController::class)->update($request, app(\App\Services\Settings\SettingsService::class), app(\App\Observability\ObservabilityEmitter::class));
            self::fail('expected the store failure to surface');
        } catch (\RuntimeException $exception) {
            fwrite(STDERR, "\nbulk save: first key written=" . json_encode($writes) . ' then exception: ' . $exception->getMessage() . "\n");
        }
        self::assertSame(['settings.media.scene_threshold'], $writes);
    }

    /** HAProxy 'option forwardfor' appends the real peer, so the header looks like "<forged>, <real>". Rotate only the forged part. */
    public function test_rotating_the_forged_part_of_an_appended_forwarded_for_defeats_the_login_limit(): void
    {
        $statuses = [];
        foreach (range(1, 12) as $attempt) {
            $statuses[] = $this->withHeader('X-Forwarded-For', '192.0.2.' . $attempt . ', 10.9.9.9')
                ->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong-guess-' . $attempt])
                ->status();
        }
        fwrite(STDERR, "\nappended-header login statuses: " . implode(',', $statuses) . "\n");
        self::assertNotContains(429, $statuses);
    }
}
```

## Appendix B: remaining notes

- Git status is clean apart from `tests/Scratch/` (deleted before commit) and the ignored `vendor/` installed for the run.
