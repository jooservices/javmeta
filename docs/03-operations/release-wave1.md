# Production deploy WAVE 1 — release candidate

**Branch:** `JOOXCR-373-readiness`  
**Machine that built the candidate:** WSL  
**Date:** 2026-11-07  
**Do not push from this worktree as part of the merge job.** No production host was accessed.

This document is the deploy WAVE 1 release candidate assembled by merge wave 2 on readiness (after JOOXCR-439 / `result-400b` merge gates). Deploy WAVE 2 tickets (i18n, facets, downloads, photos, sentry-zero, quality, monitoring-log, god-class, QA-prod-like, mongo-index) are intentionally **out of scope**.

## Changes per ticket

### JOOXCR-392 / 392b — PostgreSQL connection budget (merged)

**User-visible:** none.  
**Operational:**

- Idle PostgreSQL PDOs are released after queue jobs, scheduled tasks, Horizon supervisor/master loops, and `schedule:work` boot (transactions stay open).
- Fleet worker budget from role limit minus reserve (defaults 120 − 30 = 90). Over-budget layouts fall back to code defaults; reconcile refuses over-budget scaling.
- Prometheus gauges at `GET /metrics`; OpenObserve warning alert for connection demand.
- Docs: `docs/03-operations/postgres-connections.md`.

### JOOXCR-393 — crawl stability (**skipped**)

No commits on `JOOXCR-393-crawl-stability` relative to readiness (work existed only as uncommitted worktree changes). Not part of this candidate.

### JOOXCR-403 — UX complete (merged)

**User-visible:** remaining Vietnamese nav/labels; Discover heading; crawl settings split + name filter; logout confirm; empty player state; report/settings layout; movie keyboard open; toast/feed polish; search aria-label.  
**Operational:** UI inventory doc; expanded browser E2E steps/scenarios.

### JOOXCR-406 — heartbeat index (merged)

**User-visible:** guests no longer receive a `workers` field on status/summary.  
**Operational:** worker heartbeat index capped (retention 1h, max 200 entries, 30s write interval); owner summary workers capped.

### JOOXCR-399 — SSRF / sinkhole DNS (merged)

**User-visible:** none.  
**Operational:** DNS answer classifier + browser DNS health probe + `crawler:dns-ssrf-check`; richer Sentry SSRF context; ops doc `browser-dns-ssrf.md`. Companion CrawlerX guard change is separate (see `result-399.md`).

### JOOXCR-404 — autofix design (merged, docs only)

Design note only: `docs/decisions/autofix.md`. No runtime change.

### JOOXCR-397 — backup monitoring off (merged)

**User-visible:** settings switch “Bật giám sát sao lưu” (default off).  
**Operational:** backup age checks / readiness contribution only when `backup.monitoring_enabled` is true.

### JOOXCR-415 — Symfony advisories (merged after independent verify)

**User-visible:** none.  
**Operational:** `composer.lock` Symfony components to patched 8.1.x / contracts 3.8.x. Branch had no `result-dep-security.md`; readiness still had 9 advisories; audit on the branch was clean, then merge + `composer install` cleared `composer audit`.

### JOOXCR-412 / 413 / bughunt-* (merged, docs only)

Ops investigations under `docs/03-operations/` (memory audit, search investigation, bug-hunt/*). No runtime change.

### Readiness follow-up (JOOXCR-373)

- SSRF classifier IPv6 documentation-range mask fix; extra unit/feature coverage for SSRF helpers.
- Browser E2E keyboard-walk waits for movie detail actions (favourite) before asserting.

## Migrations

**None** in this wave. No rollback scripts required for schema.

## Config / env / settings for production

Add to each node’s private env if missing (defaults match code when unset):

| Variable | Default | Purpose |
|---|---|---|
| `JAVMETA_POSTGRES_ROLE_CONNECTION_LIMIT` | `120` | Role connection ceiling used by the budget |
| `JAVMETA_POSTGRES_CONNECTION_RESERVE` | `30` | Non-worker reserve |
| `JAVMETA_POSTGRES_WORKER_NODE_COUNT` | `2` | Worker Horizon nodes in the fleet estimate |
| `JAVMETA_POSTGRES_PRIMARY_NODE_COUNT` | `1` | Primary Horizon nodes in the fleet estimate |

Database setting (optional; default already off):

| Setting key | Default | Purpose |
|---|---|---|
| `backup.monitoring_enabled` | `false` | Enable only after backup manifests are healthy |

Leave guest mode / login policy unchanged unless an operator intentionally flips them.

## Pre-deploy checklist

1. Candidate SHA on `JOOXCR-373-readiness` verified; wave-2 merge result file present.
2. `composer audit` clean on the release tree; Symfony packages ≥ patched versions.
3. Migrations: confirm none new since previous production release for this wave.
4. Env on `.20` / `.21` / `.22`: Postgres budget keys present or accept defaults.
5. Confirm CrawlerX SSRF decoy fix (JOOXCR-399 companion) is on the fetch host if DNS decoys are still present.
6. vm-tools / browser DNS: public resolvers (not ISP sinkhole-only) — see `browser-dns-ssrf.md`.
7. Backup monitoring stays **off** until manifests are proven.
8. Canary plan agreed; rollback SHA known; no deploy WAVE 2 branches included.

## Canary plan

Same release artifact on every crawler node; order:

1. **`.20` (primary)** — deploy, migrate if any (none expected), restart app/Horizon/scheduler units, run verification below for ≥30 minutes.
2. **`.21` then `.22` (workers)** — same release; restart Horizon workers; confirm reconcile stays under budget.
3. Promote only after canary checks pass; do not mix WAVE 2 deploy branches.

## Verification

### URLs

| URL | Expect |
|---|---|
| `/api/health` | Dependencies healthy / degraded only for expected idle workers on a quiet node |
| `/ui-api/status/summary` as **guest** | JSON **without** a `workers` key |
| `/ui-api/status/summary` as **owner** | `workers` present and capped |
| `/metrics` | Postgres demand / role_limit / budget_limit / warning gauges |
| `/app` | Owner UI loads; guest banner when guest mode on |

### Sentry (project xcrawler) — groups that must go quiet after full roll-out

| Topic | Groups / fingerprint | Note |
|---|---|---|
| SSRF blocked fetch | 58, 62 (`ssrf_blocked` + source) | Should stop once decoy filter + DNS are fixed |
| Redis `eval` argument order | 167, 168, 169 | Fixed in JOOXCR-390 (`SourceRequestPacer`); confirm no new events |
| Postgres “too many connections” | prior connection storms | Budget + idle release should stop recurrence |

### Prometheus / Alertmanager

- Alert `javmeta_postgres_connection_demand_warning` not firing at steady state (demand under 75% of role limit by default).
- Existing crawl / source / node alerts unchanged; blackbox and HAProxy exporters from earlier wave still healthy.
- Optional: run `php artisan crawler:dns-ssrf-check` on a host that can resolve public DNS.

## Rollback

1. Redeploy previous known-good release SHA on the same node order (`.20` first if primary-only change, else reverse canary).
2. Restore previous `composer.lock` / vendor only by redeploying that SHA (no partial Symfony pin).
3. Env keys for the Postgres budget may stay; removing them restores code defaults.
4. `backup.monitoring_enabled` can stay false.
5. **No migration rollback** for this wave.
6. Do not FLUSH Redis or wipe databases as part of rollback.
