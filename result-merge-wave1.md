# Merge Wave 1 — JOOXCR-373 Readiness

**Status: unblocked (2026-11-07, WSL).** All requested branches remain merged. Follow-up merge gates on JOOXCR-439 are green: per-file coverage ≥85% and browser E2E 31/31 on the QA stack at port 18099. Details and final numbers: [`result-400b.md`](./result-400b.md).

## Merge commits

| Order | Branch | Merge commit |
|---:|---|---|
| 1 | `JOOXCR-375-controllers-to-services` | `ef4a450` |
| 2 | `JOOXCR-376-repositories` | `83d044f` |
| 3 | `JOOXCR-390-sentry-issues` | `cd3a966` |
| 4 | `JOOXCR-384-weak-hashes` | `002fa8e` |
| 5 | `JOOXCR-385-e2e-more-views` | `ef9ebc8` |
| 6 | `JOOXCR-389-db-tuning` | `22c2d35` |
| 7 | `JOOXCR-388-monitoring` | `ef5cb42` |
| 8 | `JOOXCR-394-ux-fixes` | `8d82c57` |

Each is a real `--no-ff` merge. Hook-required Conventional Commit subjects use `chore(readiness): Merge JOOXCR-<branch> into readiness (JOOXCR-373)`. No hook bypass. `JOOXCR-391` and `JOOXCR-392` were not merged. Nothing was pushed.

Follow-up test correction: `1b667d9` (`test(admin): Require explicit API key scopes (JOOXCR-373)`).

## Conflicts and decisions

| File | Decision |
|---|---|
| `app/Services/Notifications/TelegramBot.php` (375 and 376) | Combined both service changes and consolidated persistence behind one repository per concern; retained a single canonical CrawlQueue, CrawlRun, CrawlEvent, Source, FailedJob, and Movie repository. |
| `app/Services/Crawl/CrawlQueueService.php` (390) | Preserved stale/backlog and retry behavior while keeping the persistence queries in `CrawlQueueRepository`. |
| `app/Http/Controllers/CatalogController.php` (384) | Kept the controller thin and delegated catalog behavior to the service. Replaced non-protocol MD5/SHA1 use with SHA-256. |
| `app/Http/Controllers/Admin/ApiKeyController.php` (394) | Kept service-driven actions and the request boundary; retained the new requirement for explicit, non-empty API key scopes. |

There were no code conflicts in 385, 389, or 388. The 394 merge also moved calendar query work into `CalendarRequest -> MovieCalendarService -> MovieRepository`; MCP uses the same service and snapshot DTO.

## Verification (wave merges)

| Change | Result after merge |
|---|---|
| 375 | `composer lint` passed; 39 tests, 260 assertions. |
| 376 | `composer lint` passed; 145 tests, 1,215 assertions. |
| 390 | `composer lint` passed; 66 tests, 845 assertions; shell checks passed. |
| 384 | `composer lint` passed; 47 tests, 335 assertions. No MD5/SHA1 under `app/`. |
| 385 | `composer lint` passed; QA runner tests 4/4 and Node syntax check passed. |
| 389 | `composer lint` passed; docs-only change. |
| 388 | `composer lint` passed. Promtool 3.13.4 accepted Prometheus config and 15 rule files (169 rules). |
| 394 | `composer lint` passed; 33 tests, 205 assertions. |
| API key test correction | 11 tests, 56 assertions passed. |

## Verification (JOOXCR-439 merge gates, 2026-11-07)

| Gate | Result |
|---|---|
| Lint + PHPStan | Pass |
| Unit/Feature coverage suite | 2441 passed, 1 skipped, 23 592 assertions |
| Aggregate coverage | 94.54% (floor 90%) |
| Per-file coverage (≥85%) | 468 measurable files, **0 below floor**, 95.68% merged statements |
| Integration | 123 tests, 1 418 assertions, 1 skipped |
| Render smoke | Pass (37 views) |
| Browser E2E | **31/31** on `http://localhost:18099` (`JAVMETA_QA_PORT=18099`) |

PHPUnit now measures `app/Console/Commands` and `CrawlerxClient.php`. Per-file checker: `tools/quality/coverage-per-file.php` / `composer coverage:per-file`. QA host port is configurable; orphan docker-proxy on 8099 was left alone; `javmeta-qa2`/8098 untouched.

## Remaining notes

- Thin command coverage (still ≥85%) and stdin/STDOUT seams are documented in `result-400b.md`.
- Monitoring: Promtool passed earlier; `systemd-analyze` / `amtool` / `nft` were unavailable on this machine for wave1.
- No production changes; ESXi not accessed; no push.
