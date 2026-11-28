# Changelog

All notable changes to javmeta (XCrawler) are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).
Releases are cut from `master` (see `docs/03-operations/release-process.md`).

## [1.2.0] - 2026-11-28

### Added

- The new web UI: a React 19 and Mantine 9 application (`ui/`) in Vietnamese and English, served at the root of the site from `public/web` (JOOXCR-724, JOOXCR-725). Guests browse the public catalogue when the setting `ui_security.login_required` is off (while it is on, a guest sees only the sign-in page); the personal features need a signed-in user; the statistics, Command Center, settings and administration pages need an account (the last three an administrator). The legacy UI stays reachable at `/app` and its backend routes (`/ui-api`, `/ui-me`, `/ui-admin`) are unchanged; they are removed later (JOOXCR-728).
- The UI API `/api/v2` with 139 operations (contract `docs/api/openapi.yaml`, inventory of the existing API `docs/api/existing-api-inventory.md`): resources and HTTP verbs, `problem+json` errors, a cookie session with a CSRF header, the scopes guest, owner and admin, `ETag` and `If-Match` on settings, rate limits (JOOXCR-726). `/api/v1`, MCP, the media worker, the node endpoints, Ops and Horizon are unchanged; a regression test keeps every route and MCP tool that existed before.
- PostgreSQL read models (`rm_movies`, `rm_performers`, `rm_perf_attr`) behind the catalogue lists, facets and search of the new UI, rebuilt every 20 minutes by the scheduled command `xcrawler:read-models:refresh` (atomic swap, one rebuild at a time).
- The setting `observability.prometheus_url`: the address of Prometheus for the infrastructure page of the Command Center (empty: the page shows that nothing is connected).
- Crawl failures are classified in Sentry as expected, real blocker, own fault or defect (JOOXCR-515).

### Changed

- `GET /` and `GET /login` serve the React UI. The command center host keeps its own sign-in page at `/login`, which signs in to `/ops`. `POST /login` and `POST /logout` are unchanged.
- The deploy installer builds the UI on the node (`npm ci` and `npm run build` in `ui/`, written to `public/web`); Node.js is already a prerequisite.
- CrawlerX 1.9.2 (JOOXCR-522, JOOXCR-371).

### Documentation

- The guest-mode route matrix lists the UI page routes.

### Database migrations

Five migrations, all additive and applied by the deploy on the primary node:

- `2026_11_26_000001_create_ui_read_model_tables` creates the three `rm_*` tables.
- `2026_11_27_030001_create_ui_preferences_and_view_history` creates `ui_preferences` and `view_history`.
- `2026_11_27_040001_create_download_requests_and_extend_download_items` creates `download_requests` and adds `state`, `progress`, `size_bytes` and `updated_at` (with defaults) to `download_items`; its `movie_code` column becomes nullable.
- `2026_11_27_060001_create_audit_log_and_setting_revisions` creates `audit_log` and `setting_revisions`.
- `2026_11_27_070001_create_movie_view_counts` creates `movie_view_counts`.

Rolling back to v1.1.1 needs no database change: the previous code ignores the new tables and columns and always sets `movie_code`. The new tables and columns stay in place after a rollback. The built UI in `public/web` also stays and is not served by the previous code (it redirects `/` to `/app`). The read models are rebuilt from the catalogue by `xcrawler:read-models:refresh`, but do not drop them: the catalogue queries of the new UI read them directly, so requests fail while a table is missing, and the scheduled refresh fails without `rm_perf_attr`, until the migration `2026_11_26_000001_create_ui_read_model_tables` creates the tables again.

## [1.1.1] - 2026-11-27

### Fixed

- Gentosha and Tokuma Shoten magazines are accepted by any 13-digit JAN code, not only ISBN-13 numbers (GENTOSHA-, TOKUMA-), and Natural High and Comma codes that end in edition letters keep their product number (JOOXCR-720).

### Changed

- CrawlerX 1.9.1: the browser fetch no longer stalls on an IPv6 (AAAA) lookup that some sites' name servers never answer, a content link containing the word "enter" is no longer clicked as an age gate, and every Composer and npm dependency of the package is updated (JOOXCR-727, JOOXCR-522, JOOXCR-717). The browser scripts run on the browser host (192.168.10.19) and are deployed there separately from this release.

### Database migrations

None. Rolling back to v1.1.0-rc.1 (the release running on the nodes today) needs no database change.

## [1.1.0] - 2026-11-25

### Added

- 14 CrawlerX 1.9.0 sources (comma, digitalark, fairway, gentosha, gravidia, hiyoko, idolgakuen, muteki, naturalhigh, silklabo, tma, tokuma, venus, yuira) are registered with their listing seed. They are created **switched off** by `crawler:sync-sources` and are switched on one at a time with `php artisan crawler:source-toggle <slug> on` (JOOXCR-720).
- Product codes for four of them: COMMA VISION numbers lose their edition letter (COMT-070B is COMT-070), Gentosha and Tokuma Shoten books are identified by their ISBN-13 (GENTOSHA-, TOKUMA-) and Gravidia by its product id (GRAVIDIA-), so the catalogue accepts their details (JOOXCR-720).

### Fixed

- Two crawl workers no longer deadlock on the API response cache epoch: it is written after the data transaction commits (JOOXCR-719).
- A restart of Redis or PostgreSQL (connection refused, `LOADING`, shutting down, terminating connection for an administrator command) is no longer reported to Sentry as an application error; the `RedisDown` and `PostgresqlDown` alerts own it (JOOXCR-524).
- The hourly `staging:readiness` run reports its verdict without failing the scheduled run (JOOXCR-519).
- `node:sample` runs as a worker command and every command needs an explicit startup role, so the startup gate no longer refuses it while Elasticsearch is down (JOOXCR-518).
- Horizon workers and the scheduled maintenance commands (`horizon:work`, `horizon:reconcile-workers`, `node:egress-probe`, `alerts:evaluate`, `report:send`, `report:send-daily`, `javmeta:hourly-report`, `reports:field-conflicts`) start without waiting for Elasticsearch; `search:switch-embeddings` is still refused (JOOXCR-715).
- Expected client errors (invalid API key, invalid filter, missing scope) and expected crawl outcomes (not found, gone, soft 404, login required, rate limited by the site) are no longer reported to Sentry as errors (JOOXCR-525).
- The deploy installer keeps the Node 26 apt source instead of resetting it to the Node 24 line (JOOXCR-718).

### Changed

- CrawlerX 1.9.0 (JOOXCR-369).
- Every dependency, container image and pinned GitHub Action is at its latest release: Laravel 13.44.1, Horizon 5.52.0, Sentry Laravel 4.31.0 and the other Composer packages; Node 26.13.1, PHP 8.5.13, Python 3.15.2 with PyTorch 2.15.0 for the embedder; PostgreSQL 18.7, Redis 8.10.3, Elasticsearch 9.5.7 and Prometheus 3.16.0 (agent mode now uses `--agent`) in CI and the monitoring compose (JOOXCR-716). Existing local development data of the old PostgreSQL 17, MongoDB 7 and Elasticsearch 8 stack must be recreated (README).

### Documentation

- A fix is finished only when its Sentry issue is resolved against the release that carries it (`docs/03-operations/release-process.md`, JOOXCR-714).

### Database migrations

None. Rolling back to v1.0.0 needs no database change. The 14 new source rows created by the deploy stay in `sources` after a rollback. A row that an operator switched on stays on: run `php artisan crawler:source-toggle SLUG off --by=NAME` for each such source before rolling back if it must not run. Switching a source off stops its work: jobs already queued for it are not fetched, they stay queued until the source is switched on again and they are still queued after a rollback. If they must be processed before the rollback, switch the source on and let its queue finish first (graceful worker drain, `docs/03-operations/deployment-automation-plan.md` TR-07).

## [1.0.0] - 2026-11-15

### Fixed

- `GET /ui-api/status/summary` no longer exhausts PHP memory: the worker heartbeat index is bounded (1 hour retention, 200 entries, 30 s write interval) and guests no longer receive a `workers` field; the owner receives at most 50 workers (JOOXCR-406).
- Redis `EVAL` argument order in the source request pacer (about 550 failures a day) and Sentry noise from expected crawl outcomes; Sentry events carry `release` and `server_name` (JOOXCR-390).
- PostgreSQL connection exhaustion: idle connections are released after jobs, scheduled tasks and in Horizon supervisor/master/`schedule:work` loops; worker scaling is capped by a connection budget with metrics and an alert (JOOXCR-392).
- SSRF guard classification of DNS answers and a browser DNS health probe (`crawler:dns-ssrf-check`) (JOOXCR-399).
- Login page, search totals, CJK fonts, API key scope requirement, confirmations, contrast, phone layout and 21 more UX defects found by the end-user audit (JOOXCR-394, JOOXCR-403).
- The XCrawler home page (`/app`) showed "Server Error" on production because the unbounded worker index exhausted PHP memory in `status/sources` and `status/summary`; fixed by the bounded index above, which this release deploys (JOOXCR-406).
- The adaptive per-source request gap reacts to timeouts and slow answers (JOOXCR-329); selected SSRF failures are requeued instead of lost (JOOXCR-363); CrawlerX 1.7.1 is adopted (JOOXCR-363).
- Read-heavy API responses are cached with reviewed invalidation and high-memory collection paths are bounded (JOOXCR-436, JOOXCR-435).
- Active API keys are listed first with keyset pagination; deployment smoke keys are cleaned up and become unusable when their deletion fails (JOOXCR-365).
- Library, performer profile and related-movie screens show clear states instead of misleading text (JOOXCR-365, JOOXCR-339, JOOXCR-335).

### Security

- Nine Symfony advisories fixed by updating `symfony/config`, `filesystem`, `http-foundation` and related components to patched 8.1.x releases (JOOXCR-415).

### Changed

- Controllers only call services and all database access goes through repositories (JOOXCR-375, JOOXCR-376); cache keys and fingerprints use SHA-256 instead of MD5/SHA-1 (JOOXCR-384).
- Backup monitoring is opt-in (`backup.monitoring_enabled`, off by default) (JOOXCR-397).
- Per-file coverage gate (85%) covering all console commands, and a 128-row UI inventory with a 36-scenario browser suite.

### Added

- Monitoring: Alertmanager routing to Telegram, exporters and Loki log shipping for every VM (operations only).
- Documentation: database tuning audit, memory-discipline audit, search investigation and ten bug-hunt reports under `docs/03-operations/`.
- A Discover page for browsing, the shared UI kit with tabbed Settings, English and Vietnamese interface text, guest browsing scenarios and a faceted search that keeps the typed query (JOOXCR-337, JOOXCR-367, JOOXCR-410, JOOXCR-385, JOOXCR-407).
- Download links on the movie page for everyone (JOOXCR-408) and node enrollment with an audit trail (JOOXCR-383).

### Database migrations

Five migrations run on deploy (`php artisan migrate`). Four are additive; none removes data. Rolling the code back to the previous tag is safe because the previous release ignores the new tables, columns and index.

| Migration | Change | Rollback |
|---|---|---|
| `2026_11_05_000001_create_node_enrollments` | new table `node_enrollments` | `dropIfExists` (drops the whole table: token hashes and the audit trail of node, role, usage and creator, so export it first if the history matters) |
| `2026_11_06_000002_add_source_circuit_backoff` | two columns on `sources` | `dropColumn` |
| `2026_11_07_000001_create_movie_download_searches` | new table, at most one row per movie | stop the download-search workers first, then `dropIfExists` (loses only search state) |
| `2026_11_09_000001_backfill_api_key_scopes` | sets the full scope list on keys whose scopes were null (the application now fails closed); `api_keys` has hundreds of rows | nothing to undo: null and the full list behave the same in the previous release |
| `2026_11_12_000001_add_api_keys_status_id_index` | index `api_keys_status_id_index` | `dropIndex` |
