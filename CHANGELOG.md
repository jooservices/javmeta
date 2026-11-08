# Changelog

All notable changes to javmeta (XCrawler) are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).
Releases are cut from `master` (see `docs/03-operations/release-process.md`).

## [1.0.0] - 2026-11-09

### Fixed

- `GET /ui-api/status/summary` no longer exhausts PHP memory: the worker heartbeat index is bounded (1 hour retention, 200 entries, 30 s write interval) and guests no longer receive a `workers` field; the owner receives at most 50 workers (JOOXCR-406).
- Redis `EVAL` argument order in the source request pacer (about 550 failures a day) and Sentry noise from expected crawl outcomes; Sentry events carry `release` and `server_name` (JOOXCR-390).
- PostgreSQL connection exhaustion: idle connections are released after jobs, scheduled tasks and in Horizon supervisor/master/`schedule:work` loops; worker scaling is capped by a connection budget with metrics and an alert (JOOXCR-392).
- SSRF guard classification of DNS answers and a browser DNS health probe (`crawler:dns-ssrf-check`) (JOOXCR-399).
- Login page, search totals, CJK fonts, API key scope requirement, confirmations, contrast, phone layout and 21 more UX defects found by the end-user audit (JOOXCR-394, JOOXCR-403).

### Security

- Nine Symfony advisories fixed by updating `symfony/config`, `filesystem`, `http-foundation` and related components to patched 8.1.x releases (JOOXCR-415).

### Changed

- Controllers only call services and all database access goes through repositories (JOOXCR-375, JOOXCR-376); cache keys and fingerprints use SHA-256 instead of MD5/SHA-1 (JOOXCR-384).
- Backup monitoring is opt-in (`backup.monitoring_enabled`, off by default) (JOOXCR-397).
- Per-file coverage gate (85%) covering all console commands, and a 128-row UI inventory with a 36-scenario browser suite.

### Added

- Monitoring: Alertmanager routing to Telegram, exporters and Loki log shipping for every VM (operations only).
- Documentation: database tuning audit, memory-discipline audit, search investigation and ten bug-hunt reports under `docs/03-operations/`.
