# Changelog

All notable changes to javmeta (XCrawler) are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).
Releases are cut from `master` (see `docs/03-operations/release-process.md`).

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
