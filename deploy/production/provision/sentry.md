# Sentry (operator with organization administrator access)

1. Sign into `http://192.168.10.17:9000` using the existing Sentry organization administrator. Use the existing company organization; select the operations team that owns XCrawler (operator decision).
2. **Projects → Create project → Laravel**, project name/slug **xcrawler**, select that team, then **Create project**. On a rerun, open the existing project instead of creating another one.
3. **Settings → Projects → xcrawler → Client Keys (DSN)**: create or reuse one enabled client key. Copy its DSN directly to the private secret manager and then `JAVMETA_SENTRY_DSN` in `shared/.env`; never paste it into Jira, a PR or logs. The DSN host must be reachable from the node; do not substitute a workstation URL.
4. Set `SENTRY_ENVIRONMENT=production`; set `SENTRY_RELEASE` to the deployed full commit SHA in the private environment before deploy. Configure `observability.sentry_traces_sample_rate` in the Settings page (default zero). Errors remain enabled when a DSN is present; PII is disabled and the application's Sentry scrubber remains active.
5. After first deploy, run `php artisan javmeta:smoke --json` as `xcrawler` from `current`; check the Sentry result and inspect the project's issues/event view. Confirm project `xcrawler`, environment `production`, commit SHA and scrubbed fields. Enable alert rules for the owning team. This task does not send any event to the real Sentry VM.

Verified only by reading: organization membership, DSN reachability, the installed Sentry UI and production event ingestion require an operator with VM access.
