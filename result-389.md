# JOOXCR-389 result

**Status:** Audit complete; no production changes applied.
**Date:** 2026-11-05

The full evidence, workload trace, proposals, risks, restart windows, and rollbacks are in [`docs/03-operations/db-tuning-audit.md`](docs/03-operations/db-tuning-audit.md).

## Key findings

- **PostgreSQL `.12`:** 107 `xcrawler` sessions against a 120-session role limit; 110 TCP sockets came from three crawler-node IPs. Read-only statistics masked session state and application identity, so the reported idle-session holder was not conclusively identified. Horizon defaults allow up to 22 workers per node, a plausible application-side contributor. Current memory sizing and buffer hit rate do not support immediate memory changes. `pg_stat_statements` is unavailable. Targeted autovacuum thresholds are proposed for high-churn tables.
- **MariaDB `.11`:** Config has an 8 GiB buffer pool and enables no-index query logging. Its 142 MB slow log contains abundant fast entries in a bounded sample. SQL access was blocked by host ACL, so hit rate, connection inventory, and query/index digests remain unknown. Proposal: turn off the no-index log filter after access is restored, preserving the 2-second slow-query threshold.
- **Redis `.14`:** `noeviction` and AOF everysec protect queue/Horizon state, but `maxmemory=0` permits unbounded growth. Current use is small. Proposal: set a 20 GiB ceiling in a planned restart window while retaining `noeviction`.
- **MongoDB `.13`:** 241 connections, two active; WiredTiger uses 0.74 GiB of a 7.31 GiB cache. All 34 sampled archive collections have only `_id_`, while repository upserts filter by URL/external ID. Proposed non-unique indexes require resolving `xcrawler` versus `javmeta_archive` database-name drift first.

## Collection limits

No table/collection full scans, query plans, index builds, config changes, service restarts, or maintenance actions were run. PostgreSQL per-session details, top-query totals, WAL counters, and duplicate-index comparison remain incomplete. MariaDB SQL runtime statistics and query digests were unavailable because its host ACL rejected the connection. Findings are point-in-time and must be rechecked before implementation.

## Review state

All recommendations are proposals grouped as A (no-restart), B (restart), or C (application/repository change) in the full audit. The requested Confluence reconciliation remains unpublished pending owner review; the relevant homelab inventory and service pages were read during this audit.
