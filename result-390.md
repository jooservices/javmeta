# JOOXCR-390 results

Work was limited to this checkout. No production nodes were changed; the only remote access was the authorized read-only Sentry event inspection on `192.168.10.17`.

## 1. Startup health reports PostgreSQL and Mongo unavailable

- **Reproduced:** Yes. `PostgresProbeConnectionLimitTest::test_check_reuses_a_connection_when_the_database_role_has_one_slot` failed against the old probe with a real PostgreSQL role limited to one connection. `StartupHealthServiceTest::test_a_postgres_failure_does_not_mark_a_healthy_mongo_ping_as_unavailable` also failed against the old health report.
- **Root cause:** `PostgresProbe` opened a new randomized connection for each health check, so an account at its connection limit could fail its probe while the application connection was already available. Separately, `StartupHealthService` converted a passing Mongo ping into a failure when PostgreSQL prevented bootstrap settings from loading.
- **Fix:** Reuse the configured Laravel PostgreSQL connection for the probe and preserve Mongo's independent ping result when bootstrap fails.
- **Verification:** Both tests pass after the fix, including the real PostgreSQL connection-limit integration test.

## 2. Worker claim expires before its crawl row starts

- **Reproduced:** Yes. `CrawlQueueServiceTest::test_unstarted_claims_wait_for_the_queue_backlog_window_before_reclaiming` failed against the old code by reclaiming both claims at once the 900-second claim timeout elapsed.
- **Root cause:** The reclaimer applied the running-claim timeout to rows still waiting to start. A row can remain claimed behind the bounded queue backlog longer than that timeout.
- **Fix:** Keep the configured stale timeout for started rows. Give unstarted rows a grace period based on the largest direct, browser, or retry queue depth multiplied by the worker timeout, with the configured timeout as a minimum. Updated the setting description to distinguish the two cases.
- **Verification:** The test now keeps a claim aged 901 seconds and reclaims one older than the calculated 18,000-second backlog window.

## 3. Redis `eval()` argument type error

- **Reproduced:** Yes. `SourceRequestPacerRedisTest::test_reservation_uses_real_redis_and_preserves_the_per_source_lock` failed against the old code with `Redis::eval(): Argument #3 ($num_keys) must be of type int, array given`.
- **Root cause:** The Laravel Redis connection API expects the key count before the variadic key and script arguments; the pacer passed the raw PhpRedis argument order instead.
- **Fix:** Pass `1` as the key count, followed by the key and script arguments. Repository search found this as the only incorrect Laravel `eval()` call; direct PhpRedis `eval()` calls use the extension's separate, correct argument order.
- **Verification:** The integration test passes against the real CI Redis service and confirms the first reservation succeeds while the duplicate reservation is held by the per-source lock.

## 4. SSRF policy blocks Jable and MissAV

- **Reproduced:** No — **needs more info**.
- **What I tried:** Inspected the vendored crawler SSRF guard and tested its DNS policy with deterministic answers. Public Cloudflare addresses `104.20.0.1` and `172.66.0.1` were accepted for the two hostnames; an added private `10.0.0.1` answer and an allowlist mismatch were rejected. This did not reproduce the reported production block.
- **Why unresolved:** The browser service's effective `CRAWLERX_BROWSER_ALLOWED_HOSTS`, the full A/AAAA answer set used for the failing request, and the blocked redirect/subrequest are not available from the authorized Sentry event metadata. Reading the browser node or its logs would exceed the read-only Sentry access boundary.
- **Needed to continue:** Provide the browser-sidecar allowlist and the blocked request's full DNS answer set and redirect/request URL chain (or a safe captured browser log). No SSRF policy change was guessed.

## 5. Expected source failures sent as error-level Sentry issues

- **Reproduced:** Yes. `SentryReportingTest::test_expected_source_failures_are_warnings_and_group_by_code_and_source_across_urls` failed against the old reporter because events had no explicit warning level.
- **Root cause:** Expected crawler outcomes were captured as exceptions without lowering their Sentry level. The reporter already used the stable `(error_code, source)` fingerprint.
- **Fix:** Set `blocked`, `network`, and crawler `timeout` outcomes to warning level while retaining the existing fingerprint. A worker `TimeoutExceededException` remains at its existing error level. Crawl event/run records and alert behavior were not changed.
- **Verification:** The test checks four warning events, stable fingerprints across two URLs for the same source/code, and distinct fingerprints for different source/code pairs. Existing worker-timeout coverage checks it is not downgraded.

## 6. Sentry release and server identity missing

- **Reproduced:** Yes. `SentryConfigTest::test_options_include_the_deployed_release_and_node_name_without_changing_environment` failed against the old config because `server_name` was absent. The deploy shell checks also failed against the old short-SHA export and installer environment generation.
- **Root cause:** Sentry config did not set `server_name`. Compose deploy exported only a short SHA, while the native installer resolved the exact commit but did not write it into the staged environment.
- **Fix:** Set `server_name` from `JAVMETA_NODE_NAME` with hostname fallback. Compose deploy now exports the full checked-out commit SHA; native deploy writes its fetched full commit SHA to `SENTRY_RELEASE` before staging the environment and refreshes it on each deploy. The Sentry provisioning guide documents both paths. `SENTRY_ENVIRONMENT` remains configured as before.
- **Verification:** The Sentry config test, `tests/Shell/sentry-release.test.sh`, and `tests/Shell/install.test.sh` pass. The installer test verifies that the deployed SHA is in the private environment even when the optional Sentry integration is disabled.

## Deployment smoke-check events

Keep the `JavMeta deployment smoke check` event in Sentry. It is an intentional `info` message with no exception, sent and flushed to verify delivery; the reported 25 events in 24 hours are expected smoke signals, not error issues.

## Verification

- `docker/ci/run composer lint` passed.
- `docker/ci/run composer test:coverage` passed: 2,304 tests, 1 skipped, 22,000 assertions; 93.84% combined statement coverage. Touched PHP source coverage: `CrawlFailureReporter` 100%, `CrawlQueueService` 97.47%, `SourceRequestPacer` 100%, `PostgresProbe` 95.45%, `StartupHealthService` 98.00%.
- `docker/ci/integration` passed: 119 tests, 1 skipped, 1,368 assertions; the separate Mongo outage check passed 1 test with 20 assertions.
- Shell deploy checks `tests/Shell/sentry-release.test.sh` and `tests/Shell/install.test.sh` passed.
