# Merge wave 2 — release candidate for production deploy WAVE 1

**Machine:** WSL · **Branch:** `JOOXCR-373-readiness` · **Date:** 2026-11-07  
**Prerequisite:** JOOXCR-439 / `result-400b` merge gates already green on this branch. Nothing pushed. No production access.

## Skipped

| Branch | Reason |
|---|---|
| `JOOXCR-393-crawl-stability` | Zero commits ahead of readiness; implementation only as uncommitted worktree files + `result-393.md` |
| *(initial)* `JOOXCR-415-dep-security` | No `result-dep-security.md` at first pass — **later merged** after independent `composer audit` on that branch was clean and readiness audit failed the full gate |

Not merged (deploy WAVE 2 / out of scope): i18n, facets, downloads, photos, sentry-zero, quality, monitoring-log, god-class, QA-prod-like, mongo-index.

## Merge commits (`--no-ff`)

| Order | Branch | Merge commit |
|---:|---|---|
| 1 | `JOOXCR-392-pg-connections` (includes 392b) | `0ea395700508f5e84effa80e953d85e4ba6c63cd` |
| 2 | `JOOXCR-403-ux-complete` | `20eed2aeb33f1dd6c7daa9e2618f30121060f1d0` |
| 3 | `JOOXCR-406-heartbeat-index` | `fa62a0d7fbc15253419a76f5d5ae576bb3bbb21a` |
| 4 | `JOOXCR-399-ssrf` | `779e3fc8ec155c2f72b6e7ef420b79c9a75c442d` |
| 5 | `JOOXCR-404-autofix-design` | `58f815832db318dd2efe7630a939ef76e8d9d3ae` |
| 6 | `JOOXCR-397-backup-monitoring-off` | `62bc29c6ac4b9df19e041e8fd1f42a47fa3bc71f` |
| 7 | `JOOXCR-412-memory-audit` | `7ef1f78f6c8224eb7cd989bdd47103acf012d66f` |
| 8 | `JOOXCR-413-search-investigation` | `5c742b1b2e87312e6e12880fa61e0d9aaa1bcf6f` |
| 9 | `JOOXCR-bughunt-catalog` | `6e63ac8ffdefee84b38846a7c03220f2e4ec9379` |
| 10 | `JOOXCR-bughunt-crawl` | `6df0c6411fd54867d74a50edd4175d2ed2969bfe` |
| 11 | `JOOXCR-bughunt-data` | `267c3b4ae5c9c1a57cc9a01468baf099a8d472dc` |
| 12 | `JOOXCR-bughunt-frontend` | `84ce9d99541c125060b05e28a766d8b091b42c7b` |
| 13 | `JOOXCR-bughunt-media` | `8ea4c57912716b359268b42ce00b823b3704babf` |
| 14 | `JOOXCR-bughunt-notify` | `6be9c127f3872f7732f3869c8a1ac15d5180accc` |
| 15 | `JOOXCR-bughunt-ops` | `90d0f7888fb672f8e7d3653f77b397fe170d8041` |
| 16 | `JOOXCR-bughunt-persist` | `ed56837fa9db907321040a32cf8e6a2f1e9add70` |
| 17 | `JOOXCR-bughunt-security` | `aebe7e384860f07a6f2fd95146da4811ebaaf194` |
| 18 | `JOOXCR-bughunt-settings` | `91ad1f25710035939853075ea136fefa48f833c1` |
| 19 | `JOOXCR-415-dep-security` | `5a91fd24e83fa80ad17c37c31b486f000a2dbafb` |

Follow-up fix commit (not a merge): `e41d31aa62611329b186c8ef94e15901f5faa608` — SSRF coverage + IPv6 documentation-range classifier fix + E2E keyboard-walk wait.

Subjects use `chore(readiness): Merge <branch> into readiness (JOOXCR-373)`.

## Conflicts (403)

| File | Resolution |
|---|---|
| `UiShellController.php` | Kept guest banner `<span>` (439 E2E) and 403 period-inside-link copy |
| `crawl.js` | Kept 403 “Thu thập” empty-state + `btn` class |
| `search.js` | Kept 403 `aria-label` and HEAD Drama example chips / single-char hint |
| `reports.mjs` | Combined hash restore after reload (403) with `attached` wait for hidden switch (439) |

## Final gate numbers

| Gate | Result |
|---|---|
| `composer lint` (+ PHPStan) | Pass |
| Unit/Feature `composer test:coverage` | **2508** passed, 1 skipped, **24 345** assertions |
| Aggregate coverage | **94.59%** (18 954 / 20 038) · floor 90% |
| Per-file coverage (`composer coverage:per-file`, floor 85%) | **95.75%** overall · **474** measurable app files · **0** below floor |
| Integration `docker/ci/integration` | **128** tests, **1436** assertions, 1 skipped (+ mongo-outage 1/20) |
| Shell `composer test:shell` | Pass on re-run (first parallel run flaked `install.test.sh` readiness with `READINESS_TIMEOUT=1`) |
| Render smoke | Pass · 37 views |
| Browser E2E `QA_BASE=http://localhost:18099` | **36/36** scenarios, **684** steps |
| `composer audit` | **No security vulnerability advisories found** (after 415 + `composer install`) |
| Guest route/field matrix | Pass (`test_every_route_matches_the_guest_matrix…`, 733 assertions) |
| `php -l` on changed PHP (since 400b tip) | Pass (PHP 8.5) |

## Extra verifications

| Check | Result |
|---|---|
| Guest status/summary has no `workers` | Pass |
| Heartbeat index bounded | Pass (unit + feature) |
| Redis `eval` order | Already fixed in JOOXCR-390 (`SourceRequestPacer`); still present on readiness |
| SSRF classifier / probe tests | Pass (incl. sinkhole / special-use cases) |
| DB connection budget guard | Pass |
| Migrations in this wave | **None** |

## Docs produced

- `docs/03-operations/release-wave1.md` — deploy WAVE 1 RC runbook  
- this file — `result-merge-wave2.md`

HEAD at documentation time: `e41d31aa62611329b186c8ef94e15901f5faa608` (plus any commit that only adds these result/docs files).
