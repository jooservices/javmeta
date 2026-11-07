# Security bug hunt: authentication, API keys, guest mode, MCP, headers (JOOXCR-414)

Report only. No application code or tests were changed, nothing was pushed, and no production write was made.
Branch `JOOXCR-bughunt-security` (worktree from `JOOXCR-373-readiness`, base `1b667d9`).

## Method and budget

- Read: `app/Http/Middleware/**`, `AuthenticateApiKey` scope map, `ApiKey*` services/repositories/models,
  `routes/api.php`, `routes/web.php`, `UiApiController`, `Login/Health/Status` controllers, `app/Services/Mcp/*`,
  `McpToolService`, `bootstrap/app.php`, `config/session.php`, `config/javmeta*.php`, `composer.lock`, `embedder/`,
  `deploy/production/**`, package `jooservices/laravel-controller` (status route).
- Proof: 14 scratch feature tests in `tests/Scratch/SecurityBugHuntTest.php` (phpunit, sqlite `:memory:`, through
  `docker/ci/run php vendor/bin/phpunit`). Each test asserts the **correct** behaviour, so a failure is the proof.
  Result: **14 tests, 12 failures, 2 passes**. Two of the failures are deliberate probes (`test_cors_probe_values`,
  `test_public_health_probe_payload`) that print real response data. The 2 passes are a control and a checked-OK case.
  Content of the scratch file is in Appendix A. The file is deleted before the commit.
- `docker/ci/run composer audit`: **Found 9 security vulnerability advisories affecting 6 packages**.
- Production (read-only, 12 SELECT statements over 6 SSH sessions, plus one read-only Sentry Django query).
  Statement timeout 10 s, all with LIMIT. No `count(*)` on tables above 1M rows (`pg_stat` estimates used).
  No HTTP request was sent to production.

## Counts by severity

| Severity | Count | IDs |
|---|---|---|
| S1 (security / data-exposure / outage) | 2 | 001, 002 |
| S2 (visible defect / info disclosure / wrong results) | 5 | 003, 004, 005, 006, 007 |
| S3 (minor / robustness / hardening) | 14 | 008 to 021 |
| **Total** | **21** | |

## Fix first (ranked)

1. **BH-security-001**: revoke or re-scope 3 active full-scope keys with `abuse_rpm = 100000` (`perf-proof`, ids 62, 65, 107). Owner decision required (see finding).
2. **BH-security-002**: trust only the HAProxy address in `trustProxies`, and make HAProxy drop client `X-Forwarded-For`. Without this the login throttle is void.
3. **BH-security-003**: anonymous `/api/health` and `/api/v1/status` return internal detail. Invert the filter (minimal by default) and publish `config/laravel-controller.php` with `include_*` set to false.
4. **BH-security-004**: MCP must not return exception messages (SQL, paths, hosts). Map to a generic `-32603`.
5. **BH-security-005**: smoke check creates a full-privilege key on every deploy. Give it the exact scopes it needs, revoke in `finally`, and add a sweeper.
6. **BH-security-006**: upgrade Symfony packages to 8.1.11 or later (9 advisories) and keep `composer audit` as a CI gate.
7. **BH-security-009**: throttle failed API-key authentication per client IP (after fix 2).
8. **BH-security-010**: stop accepting `scopes = NULL`. Backfill the 552 NULL keys to explicit lists.
9. **BH-security-012 / 013**: add anti-framing and hardening headers, and publish an explicit `config/cors.php` (deny by default).
10. **BH-security-007 / 020**: fix search pagination consistency and `total` (cross-area, suspected), and reject non-finite `score_min`.

---

## Findings

Legend: "Confirmed" means a test or production query was run. "Suspected" means reasoned from code only.
Line numbers refer to the worktree at `1b667d9`.

| id | sev | file:line | summary | root cause |
|---|---|---|---|---|
| BH-security-001 | S1 | app/Services/Auth/ApiKeyService.php:21, app/Http/Requests/Admin/CreateApiKeyRequest.php:22 | 3 active keys in prod: full scope (NULL), 100000 rpm, unused since 2026-10-24 | Confirmed (data). Creation path bypassed API validation; source of the 3 keys not identified |
| BH-security-002 | S1 | bootstrap/app.php:28, routes/web.php:34, deploy/production/haproxy/xcrawler.cfg:21 | `X-Forwarded-For` spoofing defeats `POST /login` throttle (6/min) and IP limiters | Confirmed (test). `trustProxies(at: '*')` plus `option forwardfor` without header scrub |
| BH-security-003 | S2 | app/Http/Controllers/HealthController.php:14-16, app/Http/Middleware/GuestSafeStatusResponse.php:18, app/Providers/AppServiceProvider.php:65-67 | Anonymous `/api/health` returns worker, queue, sources, dependency detail. `/api/v1/status` returns version, environment, maintenance | Confirmed (probe + test). Filter applies only when `ui_login_required=false`, which is inverted |
| BH-security-004 | S2 | app/Services/Mcp/McpRequestHandler.php:66-71, app/Services/Mcp/McpToolService.php:255-261 | Any internal exception reaches the MCP client as `code -32601` with the raw message (SQL text, DB name, path) | Confirmed (test) |
| BH-security-005 | S2 | app/Services/Health/DeploymentSmokeCheck.php:205, 215-228 | Smoke check creates a NULL-scope key each run. 545 such keys revoked in prod. Revoke failure leaves a live full-scope key | Confirmed (data + test). No least-privilege, no `finally` |
| BH-security-006 | S2 | composer.lock (symfony/*) | 9 advisories in 6 Symfony packages, all at 8.1.5 to 8.1.9 (fixed in 8.1.11) | Confirmed (`composer audit`) |
| BH-security-007 | S2 | app/Services/Search/MovieSearchService.php:42, 50, 142-150 | Relevance paging switches from ES to PostgreSQL after page 1. `total` is capped at 100 | Suspected (code only, cross-area) |
| BH-security-008 | S3 | app/Http/Controllers/Ui/UiApiController.php:219-232, app/Http/Resources/MovieResource.php:66 | Guest redactor misses `magnet:` URIs under unlisted keys. `attrs` passed through raw | Confirmed (test). Prod exposure not seen (latent) |
| BH-security-009 | S3 | app/Http/Middleware/AuthenticateApiKey.php:30 (order in routes/api.php) | Failed key authentication has no rate limit: 65 invalid keys gave 65x 401 | Confirmed (test) |
| BH-security-010 | S3 | app/Models/ApiKey.php:35-39, app/Services/Auth/ApiKeyService.php:21 | `scopes = NULL` means every scope. Service accepts it. 552 of 564 prod keys are NULL | Confirmed (test + data) |
| BH-security-011 | S3 | app/Services/Auth/ApiKeyService.php:41-47, app/Services/Auth/ApiKeyAdministrationService.php:53-58 | `DELETE /api/v1/admin/keys/{id}` returns 204 for unknown id and still writes an audit event | Confirmed (test) |
| BH-security-012 | S3 | app/Http/Middleware/** (none), deploy/production/node/xcrawler.nginx.conf, deploy/production/haproxy/xcrawler.cfg | No anti-framing or hardening headers on `/login` or `/app` | Confirmed (test + config grep). Gatekeeper prod headers not verified |
| BH-security-013 | S3 | config/cors.php (missing) | Implicit CORS: `Access-Control-Allow-Origin: *` on `/api/*`, no credentials | Confirmed (probe) |
| BH-security-014 | S3 | embedder/app.py:47-67, app/Services/Mcp/McpToolService.php:766-775 | Embedder has no auth and no input limits. MCP `search` `q` has no max length | Suspected (code only) |
| BH-security-015 | S3 | database/migrations/2026_09_18_000001_create_jvmeta_schema.php:239-247, app/Repositories/ApiUsageRepository.php:38 | `api_usage_log` has no retention. `endpoint` is the raw path (not the route template) | Confirmed (no prune: oldest row from 2026-10-23). Cardinality fine today (18 endpoints in 2000 rows) |
| BH-security-016 | S3 | app/Services/Mcp/McpToolService.php:508-516 | `get_counts` runs two uncached `count(*)` per call (movies ~310k rows, performers ~157k) | Confirmed (pg_stat estimates). Load impact not measured |
| BH-security-017 | S3 | app/Repositories/ApiKeyAdministrationRepository.php:33-40 | `GET /api/v1/admin/keys` loads every key (`get()` without limit) | Confirmed (564 keys, growing ~35/day) |
| BH-security-018 | S3 | app/Services/Mcp/McpEventStream.php:18-19 | MCP `GET` (SSE) holds a PHP worker about 30 s per request (3 x sleep 10) | Suspected impact (not measured) |
| BH-security-019 | S3 | app/Services/Auth/ApiKeyService.php:41-57, 94 | Revoke then cache refill race: a revoked key can stay accepted up to 5 s | Suspected (code only) |
| BH-security-020 | S3 | app/Services/Mcp/McpToolService.php:651-652 | `score_min` accepts `INF` (`is_numeric` then `(float)`), no range check | Confirmed (test) |
| BH-security-021 | S3 | app/Http/Middleware/OwnerAdminGuard.php:24-30, app/Providers/HorizonServiceProvider.php:49 | One static owner token for admin API and Horizon: no rotation, no expiry, no per-use audit | Design. Sentry: 6 "owner token invalid" events on `/api/v1/admin/keys` in 7 days (source unknown) |

---

### BH-security-001 (S1): three live full-scope, unlimited API keys in production

- **Where:** `app/Services/Auth/ApiKeyService.php:21` (`create` with `?array $scopes = null`);
  `app/Http/Requests/Admin/CreateApiKeyRequest.php:22` (`abuse_rpm max:10000`).
- **Reproduction (production, read-only):**
  ```sql
  SET statement_timeout=10000;
  SELECT id, left(label,60), abuse_rpm, (scopes IS NULL) AS null_scope, revoked_at
  FROM api_keys WHERE id IN (62,65,107) ORDER BY id LIMIT 4;
  -- 62|perf-proof|100000|t|    65|perf-proof|100000|t|    107|perf-proof|100000|t|
  SELECT api_key_id, count(*), max(created_at) FROM api_usage_log
  WHERE api_key_id IN (62,65,107) GROUP BY 1 ORDER BY 1 LIMIT 5;
  -- 62|361|2026-10-24 07:08  65|361|2026-10-24 07:13  107|451|2026-10-24 14:57
  ```
- **Evidence:** `abuse_rpm = 100000` is above the validated maximum of 10000, so these keys did not come through the admin API. Last use was 2026-10-24, so they are a leftover from a perf run, still active with every scope (`mcp`, `media`, `config`, ...).
- **Root cause:** confirmed for the data. Source of creation not identified (no `perf-proof` string in the repo). No expiry, no owner review.
- **Proposed fix:** owner confirms nothing uses ids 62, 65, 107, then revokes them (outward/hard-to-undo action, not done here). Add `expires_at` to keys. Reject NULL scopes in the service (see BH-010). Add a scheduled report of active keys with NULL scope or `abuse_rpm > 10000`.
- **Failing tests to add:** `ApiKeyServiceTest::test_create_rejects_null_scopes`, `ApiKeyExpiryTest::test_expired_key_is_rejected`.

### BH-security-002 (S1): X-Forwarded-For spoofing defeats the login throttle

- **Where:** `bootstrap/app.php:28-31` (`trustProxies(at: '*', headers: X_FORWARDED_FOR | ...)`);
  `routes/web.php:34` (`POST /login` `throttle:6,1`, keyed on `$request->ip()`);
  `deploy/production/haproxy/xcrawler.cfg:21` (`option forwardfor`, no `http-request del-header X-Forwarded-For`).
- **Reproduction:** `test_login_throttle_applies_when_client_rotates_forwarded_for` (scratch). Eight `POST /login` with wrong passwords and rotating `X-Forwarded-For: 203.0.113.N`.
  Actual: `302,302,302,302,302,302,302,302` (failed, no 429).
  Control `test_login_throttle_control_without_forwarded_for` (no header, same IP): passes, 429 is returned. So the limiter works, and the header is the bypass.
- **Evidence:** with all proxies trusted, Symfony picks the leftmost `X-Forwarded-For` value. HAProxy appends the real client IP to the right, so the attacker controls the left. The same `ip()` feeds `ThrottleGuestUiApi.php:24` and `LogApiUsage` (`api-ip:` key).
- **Root cause:** confirmed.
- **Proposed fix:** `trustProxies(at: ['192.168.10.x/32'])` (the HAProxy address only). In HAProxy add `http-request del-header X-Forwarded-For` before `option forwardfor` so the header cannot be forged. Check that Symfony then uses the HAProxy-appended value.
- **Failing tests to add:** the scratch test becomes the regression test. Add `ProxyTrustTest::test_untrusted_forwarded_for_is_ignored`.

### BH-security-003 (S2): anonymous callers get internal health and version data (inverted filter)

- **Where:** `app/Http/Controllers/HealthController.php:14-16` (trims only when `ui_login_required` is false); `app/Http/Middleware/GuestSafeStatusResponse.php:18` (returns unfiltered when login is required); `app/Providers/AppServiceProvider.php:65-67` (attaches it to `api/v1/status`); `vendor/jooservices/laravel-controller/src/config/laravel-controller.php:144-146` (`include_version`, `include_environment`, `include_maintenance` default `true`; the app does not publish this config).
- **Reproduction:** `test_public_health_probe_payload` (scratch, anonymous `GET /api/health`) returns HTTP 503 with `database`, `worker` (`stale`), `instances`, `queue` counters, `sources`, and `dependencies` (redis `state: down`, elasticsearch `last_failure`). No key, no session.
  `test_v1_status_does_not_disclose_version_or_environment_without_key` (anonymous `GET /api/v1/status`): `version`, `environment`, `maintenance` present (failed).
- **Evidence:** the trimming runs only in guest mode (login not required). With the secure default (`ui_login_required = true`) anonymous callers get the most detail. Production reachability through HAProxy was not verified (no HTTP to prod).
- **Root cause:** confirmed. The filter is inverted, and the package defaults expose version and environment.
- **Proposed fix:** always reduce anonymous responses to `{status, checked_at}`. Move the detail behind `OwnerAdminGuard` or the session. Publish `config/laravel-controller.php` with `include_version`, `include_environment` and `include_maintenance` set to false.
- **Failing tests to add:** `HealthControllerTest::test_anonymous_health_is_minimal_when_login_required`, `StatusRouteTest::test_status_omits_version_and_environment`.

### BH-security-004 (S2): MCP leaks internal exception messages

- **Where:** `app/Services/Mcp/McpRequestHandler.php:66-71` (`catch (Throwable $e)` returns `-32601` with `$e->getMessage()`); `McpToolService::call` catches only `InvalidFilterException` and `MovieNotFoundException` (`:255-261`).
- **Reproduction:** `test_mcp_internal_error_message_is_not_returned_to_client` (scratch). The `movies` table is renamed, then `tools/call get_counts` with a valid key.
  Actual: `{"error":{"code":-32601,"message":"SQLSTATE[HY000]: General error: 1 no such table: movies (Connection: sqlite, Database: :memory:, SQL: select count(*) as \"aggregate\" from \"movies\")"}}` (failed).
- **Evidence:** in production the same path would expose PostgreSQL host, database and SQL. Any key with `mcp` scope can trigger it. `-32601` (method not found) is also the wrong code.
- **Root cause:** confirmed.
- **Proposed fix:** in the handler, return `-32603 "Internal error"` with no detail. Log the exception with a request id and return that id. Keep `-32601` for unknown methods only.
- **Failing tests to add:** `McpRequestHandlerTest::test_internal_errors_are_generic`.

### BH-security-005 (S2): deployment smoke check creates a full-privilege key on every run

- **Where:** `app/Services/Health/DeploymentSmokeCheck.php:205` (`$apiKeys->create('JavMeta deployment smoke check')`, so scopes are NULL); `:215-228` (revoke after checks; on failure the key stays active).
- **Reproduction (production, read-only):**
  ```sql
  SELECT left(label,40), status, count(*) FROM api_keys GROUP BY 1,2 ORDER BY 3 DESC LIMIT 12;
  -- JavMeta deployment smoke check|revoked|545
  SELECT count(*), count(*) FILTER (WHERE scopes IS NULL) FROM api_keys LIMIT 1;
  -- 564 total, 552 NULL scope, created 2026-10-23 .. 2026-11-07
  ```
- **Evidence:** `test_null_scope_key_allows_every_scope` (scratch) shows a NULL-scope key is allowed `media` (failed). Each smoke run puts a key with all scopes into the table.
- **Root cause:** confirmed. No least-privilege for the temporary key. Revoke is not in `finally`.
- **Proposed fix:** create the key with only the scopes the checks use. Revoke in `finally`. Add a sweeper that revokes keys with that label older than an hour.
- **Failing tests to add:** `DeploymentSmokeCheckTest::test_temporary_key_has_only_required_scopes`, `test_temporary_key_is_revoked_when_a_probe_throws`.

### BH-security-006 (S2): vulnerable Symfony packages (composer audit)

- **Reproduction:** `docker/ci/run composer audit` gives "Found 9 security vulnerability advisories affecting 6 packages".
- **Affected (composer.lock):** `symfony/http-foundation` 8.1.9 (4 advisories: CVE-2026-118514 HEAD bypasses `methods` rules; PKSA-pr6q path rules bypassed by appending the path to the script name; PKSA-b2gg generated URLs can point to a client-chosen host; PKSA-nxjs `private_ranges` trusts IPv4-mapped/6to4/Teredo/NAT64 clients); `symfony/http-kernel` 8.1.9 (PKSA-3gxv forwarded headers set host and scheme in fragments); `symfony/routing` 8.1.9 (CVE-2026-118516 host placeholders bypass path-based access control); `symfony/config` 8.1.5 (XXE in `XmlUtils::parse()` with `validateOnParse`); `symfony/filesystem` 8.1.9 (path escapes base); `symfony/yaml` 8.1.9 (quadratic parse time). Advisory ranges fix at 8.1.11.
- **Relevance:** host and proxy advisories are reachable through BH-002 (`trustProxies('*')`). Not exploited here. Whether the app uses host-placeholder routes was not checked.
- **Proposed fix:** `composer update "symfony/*" --with-all-dependencies` to 8.1.11 or later, run the full suite, and make `composer audit` a required CI step.
- **Failing tests to add:** CI gate that fails on any `composer audit` advisory.

### BH-security-007 (S2, suspected, cross-area): search pagination mixes engines and caps `total`

- **Where:** `app/Services/Search/MovieSearchService.php:42` (`searchMovieUuids(..., min(100, max($perPage * 5, $perPage)))`); `:50` (`'total' => count($uuids)`); `:142-150` (`canUseElasticsearch` is false when a cursor is set).
- **Reasoning:** page 1 comes from ES relevance, with `last_sort = null` and `total` at most 100. The next cursor sends page 2 to PostgreSQL, with a different ranking and a different total. Not reproduced: `canUseElasticsearch` and `semanticSearch` disable ES under `testing`.
- **Proposed fix:** take `total` from ES `hits.total` (with `track_total_hits`), or from `countFiltered`. Either page everything from ES with an offset in the cursor, or disable ES for paged queries.
- **Failing tests to add:** `MovieSearchPaginationTest::test_relevance_pages_do_not_switch_engine`, `test_total_is_not_capped_at_100`.

### BH-security-008 (S3, latent): guest redactor misses magnet URIs under unlisted keys

- **Where:** `app/Http/Controllers/Ui/UiApiController.php:219-232` (`redactInternalReferences` has no `magnet:` rule); `app/Http/Resources/MovieResource.php:66` (`attrs` returned raw; key regex in `:142` does not match `attrs`).
- **Reproduction:** `test_guest_redactor_keeps_magnet_uri_under_unlisted_key` (scratch) returns `"source_links":["magnet:?xt=urn:btih:..."]` (failed).
- **Production check:** `SELECT count(*) FROM (SELECT attrs FROM movies WHERE attrs IS NOT NULL ORDER BY id DESC LIMIT 5000) s WHERE attrs::text LIKE '%magnet:%';` gives 0. A key scan of 5000 rows returned no keys. So no leak today.
- **Proposed fix:** make guest payloads an allow-list (project fields explicitly) instead of a deny-list, or add `magnet:` and other torrent/PikPak schemes to the redactor.
- **Failing tests to add:** `UiApiGuestRedactionTest::test_magnet_uri_is_removed_under_any_key`.

### BH-security-009 (S3): failed API-key authentication is not rate limited

- **Where:** `app/Http/Middleware/AuthenticateApiKey.php:30` throws `UnauthorizedException` before `LogApiUsage` (route middleware order in `routes/api.php`). `LogApiUsage.php:36` only limits valid keys.
- **Reproduction:** `test_failed_api_key_auth_is_rate_limited` (scratch): 65 requests with a well-formed but unknown key gave 65x `401` (failed).
- **Impact:** keys are 48 random characters, so guessing is infeasible. But every invalid request is an uncached DB lookup on `api_keys.key_hash`, which is cheap to amplify.
- **Proposed fix:** a per-IP limiter on the 401 path (for example 60 failures per minute). Do this after BH-002, so the IP is real.
- **Failing tests to add:** `AuthenticateApiKeyTest::test_invalid_keys_are_throttled_per_ip`.

### BH-security-010 (S3): NULL scope means every scope

- **Where:** `app/Models/ApiKey.php:35-39` (`allows` returns true when scopes is null); `app/Services/Auth/ApiKeyService.php:21` (`?array $scopes = null`); migration `2026_10_24_000002_add_scopes_to_api_keys.php` (comment: "null means every scope").
- **Reproduction:** `test_null_scope_key_allows_every_scope` (scratch) (failed). Production: 552 of 564 keys have NULL scope.
- **Proposed fix:** make `scopes` NOT NULL with `[]` as the default, and validate in the service. Backfill existing NULLs to explicit lists, decided by the owner per key.
- **Failing tests to add:** `ApiKeyScopeTest::test_scopes_are_never_null`.

### BH-security-011 (S3): revoking an unknown key returns 204 and writes an audit event

- **Where:** `app/Services/Auth/ApiKeyService.php:41-47` (update without checking affected rows); `app/Services/Auth/ApiKeyAdministrationService.php:53-58` (`emitOps` runs unconditionally); `ApiKeyController::destroy`.
- **Reproduction:** `test_revoking_unknown_key_reports_not_found` (scratch): `DELETE /api/v1/admin/keys/999999` gave 204 (failed).
- **Proposed fix:** return 404 when no row changed (or call `assertExists`). Emit the ops event only when the state changed. A second revoke also overwrites `revoked_at`, so make it idempotent.
- **Failing tests to add:** `ApiKeyAdministrationTest::test_revoke_unknown_is_404`.

### BH-security-012 (S3): no anti-framing or hardening headers

- **Where:** no header configuration in `app/Http/Middleware/**`, `bootstrap/app.php`, `deploy/production/node/xcrawler.nginx.conf`, or `deploy/production/haproxy/xcrawler.cfg`. Only `app/Http/Controllers/FileController.php:56-57` sets CSP (for files).
- **Reproduction:** `test_login_page_forbids_framing` (scratch): `/login` has no `X-Frame-Options` and no `frame-ancestors` (failed).
- **Not verified:** whether the gatekeeper adds headers in production (no HTTP request to prod).
- **Proposed fix:** a middleware (or HAProxy rule) adding `X-Frame-Options: DENY`, `frame-ancestors 'none'`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, and HSTS. Add a CSP to `/app` once inline scripts are handled.
- **Failing tests to add:** `SecurityHeadersTest::test_login_denies_framing`, `test_html_pages_send_nosniff`.

### BH-security-013 (S3): CORS wildcard from the implicit default

- **Where:** no `config/cors.php`. Laravel's default applies to `api/*`.
- **Reproduction:** probe `test_cors_probe_values` returns `ACAO='*' ACAC=NULL ACAH=NULL Vary=NULL` for `Origin: https://evil.example` on `/api/v1/status`. `test_api_does_not_send_cors_allow_origin_to_foreign_origin` fails.
- **Impact:** any origin's JavaScript can read API responses for requests that carry a valid key. Cookies are not sent (no credentials), so there is no ambient-authority read of the owner session. `/ui-api` is outside `api/*`, so the header is not sent there (reasoned, not probed).
- **Proposed fix:** publish `config/cors.php` with `paths => ['api/*']` and `allowed_origins => []` (server-to-server clients do not need CORS).
- **Failing tests to add:** `CorsConfigTest::test_no_wildcard_origin`.

### BH-security-014 (S3, suspected): embedder unauthenticated and unbounded

- **Where:** `embedder/app.py:47-65` (`/v1/embeddings`: no auth, no max list size or text length); `:67-70` (legacy `/embed`); `app/Services/Mcp/McpToolService.php:766-775` (`requiredStringArgument` has no max length, so MCP `search` `q` is unbounded). Compose binds `${JAVMETA_EMBEDDER_BIND:-127.0.0.1:8000}`, so the default is loopback, but the variable can change it.
- **Reasoning:** semantic search is disabled under `testing`, so this path was not run. Model cost grows with input size.
- **Proposed fix:** pydantic `max_length` on `str`, `max_items` on the list, and a shared-secret header. Cap MCP `q` at 200 characters, as `search_scenes` does.
- **Failing tests to add:** `embedder/tests/test_input_limits.py`, `McpToolServiceTest::test_search_q_is_bounded`.

### BH-security-015 (S3): `api_usage_log` has no retention and stores raw paths

- **Where:** `database/migrations/2026_09_18_000001_create_jvmeta_schema.php:239-247`; `app/Jobs/EmitObservabilityLog.php:36-45` (insert per API call); `app/Http/Middleware/LogApiUsage.php` (`'/' . ltrim($request->path(), '/')`); `app/Repositories/ApiUsageRepository.php:38` (top endpoints grouped by raw `endpoint`).
- **Production check:** `SELECT id, created_at FROM api_usage_log ORDER BY id ASC LIMIT 1;` returns row 1 from 2026-10-23 19:07 UTC, so nothing has been pruned. `pg_stat` estimate: 2,472 rows. Sample of the 2000 most recent: 18 distinct endpoints, max length 31, so cardinality is fine today.
- **Proposed fix:** scheduled chunked prune (for example 30 days). Store the route template (`$request->route()->uri()`), not the raw path, so "top endpoints" groups correctly.
- **Failing tests to add:** `ApiUsageRetentionTest::test_prune_removes_old_rows_in_chunks`.

### BH-security-016 (S3): `get_counts` runs two uncached full-table counts

- **Where:** `app/Services/Mcp/McpToolService.php:508-516`.
- **Production check (`pg_stat_user_tables.n_live_tup`, estimates):** `movies` 309,986; `performers` 156,701.
- **Impact:** each MCP `get_counts` scans the table. Nothing is cached. Per-key limits are 60 rpm, or 100000 for the perf keys in BH-001.
- **Proposed fix:** `Cache::remember` for 60 seconds, or read the planner estimate from `pg_class`.
- **Failing tests to add:** `McpToolServiceTest::test_get_counts_is_cached`.

### BH-security-017 (S3): API key admin list is unbounded

- **Where:** `app/Repositories/ApiKeyAdministrationRepository.php:33-40` (`->get()` on every key, no limit).
- **Production check:** 564 keys. About 545 came from the smoke check in 15 days (BH-005), so the list grows about 35 a day.
- **Proposed fix:** cursor pagination on `id`, with revoked keys excluded by default.
- **Failing tests to add:** `ApiKeyAdminListTest::test_list_is_paginated`.

### BH-security-018 (S3, suspected impact): MCP SSE holds a PHP worker about 30 s

- **Where:** `app/Services/Mcp/McpEventStream.php:18-19` (defaults `keepAlives = 3`, `intervalSeconds = 10`, `sleep` each time); `app/Http/Controllers/McpHttpController.php` (`GET` returns the stream).
- **Impact:** a valid key at 60 rpm can hold up to 60 workers at a time under PHP-FPM. Not measured.
- **Proposed fix:** drop the SSE endpoint, or cap concurrent streams per key and remove the sleep.
- **Failing tests to add:** `McpEventStreamTest::test_stream_does_not_block_for_long`.

### BH-security-019 (S3, suspected): revoke then cache-refill race

- **Where:** `app/Services/Auth/ApiKeyService.php:41-57` (DB update, then `Cache::forget`) and `:94` (`Cache::put` after a read that may predate the update).
- **Impact:** a revoked key can stay accepted for up to 5 seconds.
- **Proposed fix:** keep a revoked marker with a TTL, or re-check `status` on a cache hit for admin-sensitive calls.
- **Failing tests to add:** `ApiKeyRevocationTest::test_revoked_key_is_rejected_immediately`.

### BH-security-020 (S3): `score_min` accepts INF

- **Where:** `app/Services/Mcp/McpToolService.php:651-652` (`is_numeric` then `(float)`; `INF` passes `is_numeric`).
- **Reproduction:** `test_mcp_score_min_infinity_is_rejected_before_search` (scratch): `lookup_movies` with `score_min = INF`, `count_only` gives `{"count":0}` with no `invalid_filter` (failed).
- **Proposed fix:** require `is_finite()` and the range 0 to 10, else `InvalidFilterException`.
- **Failing tests to add:** `McpToolServiceTest::test_score_min_must_be_finite_and_in_range`.

### BH-security-021 (S3, design): one static owner token for admin API and Horizon

- **Where:** `app/Http/Middleware/OwnerAdminGuard.php:24-30` (`hash_equals`, correct); `app/Providers/HorizonServiceProvider.php:49` (same token for the Horizon gate).
- **Evidence:** Sentry, 7 days: `UnauthorizedException: Owner admin token is missing or invalid.` on `/api/v1/admin/keys`, 6 events. Source not identified.
- **Proposed fix:** session-backed super-admin for admin endpoints, or per-operator tokens with rotation and an audit row per use.

---

## Checked and found OK (do not repeat)

- **Scope map:** every `AuthenticateApiKey` route appears in `scopeOf` (`AuthenticateApiKey.php:44-59`). An unmapped URI fails closed (403 `undeclared`). Routes with no scope: `auth/verify` (any valid key), plus the public routes below.
- **Key storage:** only `sha256(plaintext)` is stored (`ApiKeyService.php:21-30`). The 48-character random key is high entropy, so no slow hash is needed. The prefix is stored for display only.
- **Key comparison:** lookup is by `key_hash`. The cached path uses `hash_equals`. A timing oracle on the DB lookup is not practical.
- **Owner token:** `hash_equals` and an empty configured token is refused (`OwnerAdminGuard.php:29`).
- **Admin key input validation:** scope is an enum and `abuse_rpm` is capped at 10000 (`CreateApiKeyRequest.php:22-24`). The prod outliers (BH-001) bypassed this path.
- **`/ui-me` IDOR:** the user id comes only from the session (`UiMarksController.php`), never from input. Marks and feed are scoped to that user.
- **UI bridge traversal:** `test_owner_ui_bridge_does_not_resolve_dot_segments` passes. `/ui-api/movies%2F..%2F..%2Fv1%2Fmedia%2Fconfig` returns 404, so the bridge does not reach `/api/v1/media/*`. The guest allow-list is anchored (`#...$#D`, `UiApiController.php:21-27`).
- **Guest writes:** `POST|PUT|PATCH|DELETE /ui-api/*` gives 403 to guests (`UiApiController.php:32`). Guests cannot pass `on_disk` (`:39`). Guest responses are `no-store` and `noindex`.
- **Elasticsearch injection:** the only user-facing ES query is `multi_match` over fixed fields (`ElasticsearchIndexer.php:206-236`). No `query_string`, `wildcard`, `regexp` or `script` built from input.
- **CSRF:** web routes are protected. Only `node/enroll` is exempt, and it authenticates with a one-time token (`bootstrap/app.php`).
- **Login session:** the session is regenerated on success. Logout invalidates the session and regenerates the CSRF token (`LoginController.php`).
- **Secrets in repo:** `.env` is git-ignored and not tracked. `.env.example` and `deploy/production.env.example` contain placeholders. Only lengths were checked; no values were printed. `config/insecure_defaults.php` refuses sample DB/Redis/OpenObserve passwords in staging and production.
- **Horizon:** gated by the owner token (`HorizonServiceProvider.php:49`), not open.
- **Trace header:** `traceparent` is parsed and regenerated, never echoed raw (`TraceHttpRequest.php`).
- **Credentials over CORS:** no `Access-Control-Allow-Credentials`, so cookies are not exposed cross-origin (see BH-013 for the wildcard).
- **Dependencies:** `laravel/framework` v13.38.0 is not in the advisory list. The only advisories are Symfony (BH-006).
- **Route list:** `route:list` shows no unauthenticated `/api/v1/*` route except the public ones listed under BH-003 and `openapi.json`, which is intentionally public.

## Could not verify

- **Production reachability** of `/api/health` and `/api/v1/status` through the gatekeeper. No HTTP request was sent to production (not in the allowed access list).
- **HAProxy frontend** (only the backend snippet is in the repo): whether an outer frontend already strips `X-Forwarded-For` or adds headers.
- **Login policy:** password rules and per-account lockout. Brute-force feasibility with a real password was not tested.
- **Source** of the three `perf-proof` keys (BH-001). No evidence in the repo or git history was checked.
- **`pip-audit`** for `embedder/requirements*.txt` (pins are only top-level: fastapi 0.146.0, uvicorn 0.54.0, sentence-transformers 6.1.0, no hashes). **`npm audit`** for `ui-tests/qa`. Neither tool ran.
- **Redis** contents and **Prometheus** 429 counts: not queried (outside this scope's access list).
- **`movies.attrs` content:** only a 5000-row sample was checked.
- **Downloads / qBittorrent** (`/ui-me/downloads/{hash}`) and SSRF in the fetch policy: outside this scope.
- **Sentry:** 7-day groups and 24 h TSDB sums only. No per-release comparison.

## Production query log (read-only)

12 SELECT statements over 6 SSH sessions to `192.168.10.12`, plus one read-only Sentry query to `192.168.10.17`.
Statements: `pg_stat_user_tables` estimates (api_usage_log, movies, performers, api_keys); oldest `api_usage_log` row by id; `api_keys` totals; active keys and labels; per-key usage for ids 62, 65, 107; top endpoints in 2000 recent rows; `attrs` magnet and key scan (5000 rows). Sentry: `Group` titles matching auth terms (7 days) and `tsdb.get_sums` over 24 h. No writes.

---

## Appendix A: scratch test (deleted before commit)

File: `tests/Scratch/SecurityBugHuntTest.php` in the worktree. Deleted after this report was written. Result at the end of the hunt: **14 tests, 12 failures, 2 passes**.

Each test asserts correct behaviour. Passing tests: `test_login_throttle_control_without_forwarded_for`, `test_owner_ui_bridge_does_not_resolve_dot_segments`. Failing tests map to findings as follows:

| test | finding |
|---|---|
| `test_login_throttle_applies_when_client_rotates_forwarded_for` | BH-002 |
| `test_v1_status_does_not_disclose_version_or_environment_without_key` | BH-003 |
| `test_failed_api_key_auth_is_rate_limited` | BH-009 |
| `test_null_scope_key_allows_every_scope` | BH-005, BH-010 |
| `test_mcp_internal_error_message_is_not_returned_to_client` | BH-004 |
| `test_mcp_score_min_infinity_is_rejected_before_search` | BH-020 |
| `test_guest_redactor_keeps_magnet_uri_under_unlisted_key` | BH-008 |
| `test_revoking_unknown_key_reports_not_found` | BH-011 |
| `test_api_does_not_send_cors_allow_origin_to_foreign_origin` | BH-013 |
| `test_login_page_forbids_framing` | BH-012 |
| `test_cors_probe_values` (probe, always fails) | BH-013 evidence |
| `test_public_health_probe_payload` (probe, always fails) | BH-003 evidence |

Key code (shortened; full methods were run as written):

```php
$statuses[] = $this->withHeaders(['X-Forwarded-For' => "203.0.113.{$i}"])
    ->post('/login', ['email' => 'owner@example.test', 'password' => "wrong-{$i}"])->status();
$this->assertContains(429, $statuses);                       // BH-002

$response = $this->actingAs($admin)->get('/ui-api/movies%2F..%2F..%2Fv1%2Fmedia%2Fconfig');
$this->assertSame(404, $response->status());                 // control, passes

$result = app(McpRequestHandler::class)->handle([...'get_counts'...], true);   // after Schema::rename('movies','movies_hidden')
$this->assertArrayNotHasKey('error', $result);               // BH-004

$result = app(McpToolService::class)->call('lookup_movies', ['score_min' => INF, 'count_only' => true]);
$this->assertSame('invalid_filter', $result['error'] ?? null);   // BH-020

$this->withHeaders(['X-Owner-Token' => 'scratch-owner-token-123'])->deleteJson('/api/v1/admin/keys/999999')->assertNotFound(); // BH-011
```

Other test methods (`test_v1_status...`, `test_failed_api_key...`, `test_null_scope...`, `test_guest_redactor...`, `test_api_does_not...`, `test_login_page_forbids_framing`) follow the same pattern: a request or call, then an assertion on the correct behaviour.

## Appendix B: proposed fix tests (names only)

`ApiKeyServiceTest::test_create_rejects_null_scopes`, `ApiKeyExpiryTest::test_expired_key_is_rejected`, `ProxyTrustTest::test_untrusted_forwarded_for_is_ignored`, `HealthControllerTest::test_anonymous_health_is_minimal_when_login_required`, `StatusRouteTest::test_status_omits_version_and_environment`, `McpRequestHandlerTest::test_internal_errors_are_generic`, `DeploymentSmokeCheckTest::test_temporary_key_has_only_required_scopes`, `DeploymentSmokeCheckTest::test_temporary_key_is_revoked_when_a_probe_throws`, `MovieSearchPaginationTest::test_relevance_pages_do_not_switch_engine`, `MovieSearchPaginationTest::test_total_is_not_capped_at_100`, `UiApiGuestRedactionTest::test_magnet_uri_is_removed_under_any_key`, `AuthenticateApiKeyTest::test_invalid_keys_are_throttled_per_ip`, `ApiKeyScopeTest::test_scopes_are_never_null`, `ApiKeyAdministrationTest::test_revoke_unknown_is_404`, `SecurityHeadersTest::test_login_denies_framing`, `SecurityHeadersTest::test_html_pages_send_nosniff`, `CorsConfigTest::test_no_wildcard_origin`, `embedder/tests/test_input_limits.py`, `McpToolServiceTest::test_search_q_is_bounded`, `ApiUsageRetentionTest::test_prune_removes_old_rows_in_chunks`, `McpToolServiceTest::test_get_counts_is_cached`, `ApiKeyAdminListTest::test_list_is_paginated`, `McpEventStreamTest::test_stream_does_not_block_for_long`, `ApiKeyRevocationTest::test_revoked_key_is_rejected_immediately`, `McpToolServiceTest::test_score_min_must_be_finite_and_in_range`, plus a CI gate that fails on any `composer audit` advisory.
