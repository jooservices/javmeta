# XCrawler production database tuning audit

**Ticket:** JOOXCR-389
**Audit date:** 2026-11-05
**Scope:** PostgreSQL `.12`, MariaDB `.11`, MongoDB `.13`, Redis `.14`
**Mode:** Diagnosis only. No production settings, data, services, or applications were changed.

## Executive summary

- PostgreSQL has 47.1 GiB RAM, 12 GiB `shared_buffers`, 32 MiB `work_mem`, 32 GiB `effective_cache_size`, and `max_connections=150`. The sampled `xcrawler` role had 107 sessions against its 120-session role limit. TCP peers from the three crawler nodes accounted for 110 sockets. The read-only role masks per-session state and application details, so the reported 130 idle sessions could not be attributed to exact processes. The production template and Horizon defaults indicate a plausible application-side cause: as many as 22 Horizon worker processes per node, plus API/control processes; three crawler nodes could therefore start 66 workers before other app connections. Dynamic worker overrides and the deployed `.env` were not inspected, so this is a hypothesis to verify.
- PostgreSQL shared-buffer hit ratio rounded to 100.00%; the sampled settings do not justify a memory change. Large-table sequence counters and estimated dead tuples warrant targeted follow-up, not blind index deletion or broad vacuuming. The `pg_stat_statements` relation was unavailable, preventing a top-query-by-total-time ranking.
- MariaDB's config allocates an 8 GiB InnoDB buffer pool and enables `log_queries_not_using_indexes`. A bounded, sanitized 1 MiB tail sample of its 142 MB slow log contained 2,644 query records; the longest sampled query took 3 ms despite `long_query_time=2`. This is consistent with the no-index logging option bypassing the duration threshold. SQL access was rejected by the host ACL, so live status, index usage, and normalized query digests were not available.
- Redis currently uses `noeviction`, AOF with `appendfsync everysec`, and no memory ceiling (`maxmemory=0`). That policy protects queue/Horizon keys from eviction, but memory growth remains unbounded. Current use was small (38.3 MiB; peak 127 MiB) on a 31.35 GiB VM.
- MongoDB used 0.74 GiB of a 7.31 GiB WiredTiger cache, with 241 current connections and two active operations. The 34 sampled XCrawler archive collections had only `_id_` indexes. The repository's archive writer upserts by URL or performer external ID, so those filters lack supporting indexes and are likely to scan collections. The live database name (`xcrawler`) differs from the production template's `javmeta_archive`; reconcile this before any index rollout.

## Method and limits

The four VMs were inspected one at a time over SSH. Database checks were read-only and bounded with statement timeouts, `LIMIT`, small `SCAN` pages, or bounded MongoDB stats. Redis was queried with `INFO`, `SLOWLOG GET 20`, and limited `SCAN`/TTL samples. Configuration and logs were read through filtered commands. No full table/collection scans, query plans, index builds, config writes, service restarts, or maintenance commands were run.

The snapshot is not a synchronized time series. PostgreSQL table counters are cumulative since their statistics reset; the table-stat reset timestamps were not captured. `n_dead_tup` is an estimate, not a physical-bloat measurement. No `pgstattuple` or equivalent scan was run. Several PostgreSQL follow-up queries for WAL counters and duplicate-index signatures returned errors, so those items remain unverified. The `pg_stat_statements` relation was unavailable; extension installation and preload state were not verified. A follow-on PostgreSQL RO login attempt failed, so it was not retried; the successful RO session masked `pg_stat_activity.state`, `client_addr`, and `application_name` for other roles.

MariaDB rejected the allowed admin account's connection with host error 1130 (`127.0.0.1` is not allowed). This is a host ACL denial before SQL ran; it does not establish whether the credentials are valid. No alternate login path was attempted. Redis `CONFIG GET` is disabled by the server's `rename-command CONFIG ""` hardening, so effective memory settings came from `INFO` and the filtered config file. MongoDB admin read-only status and collection-stat commands succeeded.

All configuration values below are observations from the audit window, not durable recommendations. Any proposed change requires a separate approved implementation and a fresh pre-change check.

## Workload traced from javmeta

| Component | Repository evidence | Production relevance and limits |
|---|---|---|
| Primary SQL | `deploy/production.env.example` selects `DB_CONNECTION=pgsql`; `config/database.php` defines PostgreSQL and a 2-second PDO connection timeout. MySQL/MariaDB connectors exist but are only selected by explicit environment configuration. | PostgreSQL is the application's declared primary SQL database. No checked-in production default selects MariaDB. The deployed environment was not read. |
| Queue and workers | The production template selects Redis queues. `config/javmeta_queues.php` defines 17 Horizon queue names: six standard/search queues, five browser queues, five retry queues, and one observability queue. Defaults allow 22 worker processes per node: 6×1 + 5×2 + 5×1 + 1×1. `config/horizon.php` maps each queue to a Redis supervisor. The queue operations guide documents database-backed worker-count settings and per-minute Horizon reconciliation. | Three observed crawler-node IPs imply up to 66 default worker processes if all three run the standard profile. Dynamic queue/node overrides, actual deployed worker counts, API and scheduler process counts, and persistent connections were not collected. This is a plausible contributor to PostgreSQL connection pressure, not a proven count. |
| Redis cache/session | `config/queue.php` defaults to Redis queue; `config/cache.php` defaults cache to `database`; `config/session.php` defaults session to `database`. The production template sets `CACHE_STORE=database` and does not set `SESSION_DRIVER`. Redis connection defaults use queue DB 0 and cache DB 1; key prefix defaults to `javmeta:` and Horizon prefix to `javmeta:horizon:`. | Queue/Horizon use is confirmed by the production template. The template does not select Redis for cache or session. Live application environment values were not read, so observed Redis DB 1 contents cannot be conclusively assigned to an active cache/session driver. |
| Mongo archive | `MongoSiteArchive::upsertMovie()` filters on `url`; `upsertPerformer()` filters on `{entity_type, external_id}` or `{entity_type, url}`, then uses `updateOne(..., upsert=true)` and may `findOne()` with that filter. Mongo connection config uses a 2-second server-selection and socket timeout. | These filters have no supporting indexes in the sampled archive collections. The production template names the Mongo database `javmeta_archive`, while the inspected XCrawler database was `xcrawler`; confirm the deployed target before implementing index provisioning. |
| MariaDB | No MariaDB selection appears in the production environment template. `config/database.php` contains optional MySQL/MariaDB connectors. | No confirmed javmeta production use was found. A zero incoming TCP client snapshot supports low network use at that instant; MariaDB's host ACL prevented SQL verification, and local Unix socket clients were not enumerated. |

Useful source locations: [`config/database.php`](../../config/database.php#L21), [`deploy/production.env.example`](../../deploy/production.env.example#L26), [`config/javmeta_queues.php`](../../config/javmeta_queues.php#L7), [`config/horizon.php`](../../config/horizon.php#L13), [`config/cache.php`](../../config/cache.php#L20), [`config/session.php`](../../config/session.php#L23), [`config/queue.php`](../../config/queue.php#L18), [`MongoSiteArchive.php`](../../app/Services/Archive/MongoSiteArchive.php#L29), and [`queue.md`](queue.md#L51).

## VM hardware and current settings

Physical storage backing is unknown on all VMs. Linux reported VMware virtual disks with `ROTA=0`; this does not establish the host datastore or physical media type.

| Service | VM hardware snapshot | Disk snapshot |
|---|---|---|
| PostgreSQL `.12` | 12 vCPU, Intel Xeon E5-2680 v4 @ 2.40 GHz; 50.5 GB RAM (47.1 GiB); 8 GiB swap, unused. | 256 GB virtual disk, `ROTA=0`; root LV 249 GiB, 19.3 GB used (8%). |
| MariaDB `.11` | 8 vCPU, Intel Xeon E5-2680 v4; 33.7 GB RAM (31.3 GiB); 8 GiB swap, unused. | 512 GB virtual disk, `ROTA=0`; root LV 501 GiB, 12.5 GB used (3%). |
| Redis `.14` | 4 vCPU; 33.7 GB RAM (31.35 GiB); 8 GiB swap, unused. | 64 GB virtual disk, `ROTA=0`; root 60.7 GiB, 12.0 GB used (20%). |
| MongoDB `.13` | 4 vCPU, Intel Xeon E5-2680 v4; 16.8 GB RAM (15.6 GiB); 4 GiB swap, unused. | 256 GB virtual disk, `ROTA=0`; root LV 249 GiB, 11.7 GB used (5%). `LimitNOFILE=64000`. |

### PostgreSQL `.12`

Server version was PostgreSQL 18.6. Effective settings observed:

| Setting | Current value |
|---|---:|
| `shared_buffers` | 12 GiB (~25.5% of VM RAM) |
| `work_mem` | 32 MiB per sort/hash operation |
| `effective_cache_size` | 32 GiB (~68% of VM RAM) |
| `maintenance_work_mem` | 1 GiB |
| `max_connections` | 150; `superuser_reserved_connections=3` |
| `xcrawler` role connection limit | 120 |
| Autovacuum | on; 3 workers; 60 s naptime; vacuum scale factor 0.2/threshold 50; analyze scale factor 0.1/threshold 50; cost delay 2 ms |
| Checkpoints/WAL | checkpoint timeout 300 s; completion target 0.9; max/min WAL 4/1 GiB; WAL buffers 16 MiB |
| Query/IO instrumentation | `log_min_duration_statement=-1`; `track_io_timing=off`; `pg_stat_statements` relation unavailable |
| Parallel query | 8 global workers; 2 workers per gather |

The connection snapshot showed 139 sessions total: `xcrawler` 107, `sonarqube` 20, and 12 under other roles. `xcrawler` was 13 sessions below its role limit and the server was 11 sessions below `max_connections`; after reserved slots, general headroom was narrow. A separate socket snapshot showed 133 PostgreSQL TCP peers: `.20` 40, `.21` 35, `.22` 35, `.19` 22, and local `.12` 1. The three crawler-node addresses accounted for 110 sockets. Mapping `.19` to SonarQube is plausible but was not verified from the masked SQL session metadata. These two snapshots were taken at different moments and should not be treated as an exact reconciliation.

The RO role exposed role-level counts but not per-session state or application/client metadata. Therefore the requested “130 idle of 150” could not be independently confirmed as idle, and the exact process owning each `xcrawler` connection remains unknown. The crawler nodes are the dominant observed source. The likely application-side mechanism is a large set of long-lived Horizon workers plus API/control processes and/or database-backed worker overrides; verify deployed process counts and settings before reducing concurrency.

#### PostgreSQL workload and statistics

`pg_stat_database` reported a 100.00% shared-buffer hit ratio when rounded (78,314,631,291 hits / 256,469 reads), 168 temp files totaling 636 MB, and 11 deadlocks. These counters are cumulative and were not normalized by a captured reset time. `pg_stat_statements` was unavailable, so no reliable top statements by total execution time can be reported. Statement logging is disabled and IO timing is off.

| Relation | Total size | Estimated live / dead tuples | Seq scans / tuples | Recent autovacuum / analyze evidence |
|---|---:|---:|---:|---|
| `crawl_queue` | 233 MB | 647,041 / 64,794 (~9.1% dead estimate) | 6,037,832 / 1.064 trillion | vacuum Nov 4 20:34 UTC; autoanalyze Nov 5 02:35 UTC |
| `movies` | 371 MB | 309,245 / 45,238 (~12.8%) | 199,156 / 16.78 billion | vacuum Nov 4 02:13 UTC; autoanalyze Nov 4 21:50 UTC |
| `movie_sources` | not in top-size sample | not recorded here | 408,483 / 42.35 billion | not recorded here |
| `performer_media` | 1,193 MB | 1,323,873 / 206,756 (~13.5%) | 18 / 1,230,899 | vacuum Nov 4 17:59 UTC; autoanalyze Nov 4 18:53 UTC |
| `movie_observations` | 1,209 MB | 4,169,714 / 100 | 18,066 / 17.2 billion | vacuum Nov 4 10:20 UTC; autoanalyze Nov 4 16:50 UTC |
| `movie_media` | 929 MB | 1,736,361 / 545 | 8 / 1,031,579 | table vacuum counters were not in the sampled excerpt |
| `crawl_fetch_attempts` | 353 MB | 726,492 / 0 | 24 / 252,473 | table vacuum counters were not in the sampled excerpt |

Sequence counters on `crawl_queue`, `movies`, and `movie_sources` are candidates for query-plan review, not proof that indexes are missing; they are cumulative and the app has recent partial indexes for pending, retry, and failed crawl lanes (`2026_11_02_000002_add_crawl_queue_pending_kind_index.php`, `2026_11_03_000001_add_crawl_retry_lane.php`). The largest observed unused-index counters included `movies_search_vector_gin_idx`, `movies_title_jp_trgm_gin_idx`, `movies_community_score_id_desc_idx`, `performers_bio_text_trgm_gin_idx`, `movies_title_en_trgm_gin_idx`, and `performer_name_grams_gram_idx` at zero uses in the collected interval. Do not drop these without a complete stats interval and workload/plan review. The duplicate-index check did not complete, so duplicate indexes are unverified.

Checkpointer statistics since 2026-09-30 showed 10,241 timed checkpoints versus 11 requested checkpoints, 1,850 completed checkpoints, 44,743,733 buffers written, and 14,413 ms sync time. These counters do not indicate a checkpoint emergency in this snapshot. WAL byte/record counters were not obtained; WAL behavior remains unassessed.

### MariaDB `.11`

MariaDB service was active. Read-only filtered configuration showed:

| Setting | Current config value |
|---|---:|
| `innodb_buffer_pool_size` | 8 GiB (~25.5% of VM RAM) |
| `innodb_log_file_size` | 512 MiB |
| `innodb_log_buffer_size` | 64 MiB |
| `max_connections` | 200 |
| `performance_schema` | ON |
| `slow_query_log` | ON |
| `long_query_time` | 2 s |
| `log_queries_not_using_indexes` | ON in `/etc/mysql/mariadb.conf.d/60-tuning.cnf` |

SQL access was blocked by the host ACL, so no live buffer pool hit rate, connection/processlist, schemas, table/index sizes, performance-schema digest, or index-missing statement report is available. A filtered TCP socket snapshot showed zero incoming TCP clients to port 3306 at that instant; Unix-socket clients were not counted. The slow log `/var/lib/mysql/ubuntu-slow.log` was 142,426,709 bytes. A sanitized 1 MiB tail sample contained 2,644 query-time records, with 0.441 s total and 0.003 s maximum query time; raw query text was not emitted. Because MariaDB logs no-index statements regardless of `long_query_time` when that option is enabled, the sample and config strongly suggest that this setting is responsible for much of the fast-query log noise. Query digests were not available to verify this or identify missing indexes.

### Redis `.14`

Redis 8.10.2 standalone was active, with ~15.1 days uptime at collection. `INFO` showed:

| Metric | Current value |
|---|---:|
| `maxmemory` / policy | 0 (unbounded) / `noeviction` |
| used memory / RSS / peak | 38.3 MiB / 78.4 MB / 127.0 MiB |
| clients / blocked | 322 / 0 |
| commands processed / ops per second | 245.9 million / 894 |
| rejected connections / evicted keys | 0 / 0 |
| expired keys | 1,002,651 |
| AOF | enabled; `appendfsync everysec`; AOF 28.5 MB (base 17.0 MB); last write OK |
| RDB | last save status OK; no BGSAVE active at snapshot |
| slow log | 7 entries returned by bounded `SLOWLOG GET 20`; maximum 20.4 ms |
| slow-log threshold / max length | 10,000 µs / 128 |

`CONFIG GET` was unavailable because `CONFIG` is renamed/disabled; effective `maxmemory` and policy were reported by `INFO`. Filtered `/etc/redis/redis.conf` confirmed AOF and every-second fsync. A bounded `SCAN COUNT 100` page per DB and up to 40 TTL samples found db0 with 4,761 keys (3,094 expiring; avg TTL ~39 h) and db1 with 14,268 keys (14,245 expiring; avg TTL ~12 h). Sampled names were predominantly `xcrawler:*`, including `xcrawler:horizon:*`; this is a sample, not a full key census. Cache hit ratio calculated across all Redis commands is ~56.6% and is not a cache-only measure because queue commands are mixed in.

Redis `noeviction` already protects queue/Horizon data from eviction. With a future `maxmemory` ceiling, writes fail when the ceiling is reached rather than deleting keys; applications must handle that failure. [Redis eviction policy documentation](https://redis.io/docs/latest/develop/reference/eviction/).

### MongoDB `.13`

MongoDB 8.0.32 status showed 4 vCPU and 15.6 GiB RAM on the VM. `connections.current=241` (including the audit shell), `available=25,359`, `totalCreated=573,017`, `rejected=0`, and `active=2`. Connections were not close to the reported server availability limit. WiredTiger cache maximum was ~7.31 GiB; current cache use was 790,735,934 bytes (~0.74 GiB), with ~206 KB dirty. This is about 10% of the configured cache ceiling; increasing cache size is not supported by current evidence. MongoDB status and cache fields are documented in [serverStatus](https://www.mongodb.com/docs/manual/reference/command/serverstatus/) and [WiredTiger](https://www.mongodb.com/docs/manual/core/wiredtiger/).

The `xcrawler` database had 34 collections and 508,464 documents: logical data size ~692 MB, compressed storage ~223 MB, and total index size ~11.8 MB. Collection stats were gathered for all 34 collections. Larger examples included `141jav` (199,163 documents, 237 MB logical, 90 MB storage, 3 MB indexes), `tokyohot` (39,160 documents, 162 MB logical), and `ffjav` (82,986 documents, 89 MB logical). Every sampled collection had only the `_id_` index. `$indexStats` use counts were zero since the process/stat interval began Oct 31 for most `_id_` indexes; these counts are interval-scoped and do not show that the index is dispensable. MongoDB documents their scope at [`$indexStats`](https://www.mongodb.com/docs/manual/reference/operator/aggregation/indexstats/).

The archive repository filters by URL or performer external ID before upserting and may perform a second lookup with the same filter. With only `_id_` indexes, these application lookups are likely collection scans. This is a strong code/schema mismatch, but collection-level operation counters and explain plans were not collected. Add non-unique URL and performer external-ID indexes through an idempotent repository provisioner/migration, after confirming the actual target database and checking duplicate semantics. Do not make them unique in the first rollout.

## Findings and proposals

Nothing in this section was applied. A/B/C describe future implementation routes, not permission to change production.

### A) Safe reload-only or no-restart changes

| Setting/object | Current value and evidence | Proposed value | Expected benefit | Risk | Restart? | Exact rollback |
|---|---|---|---|---|---|---|
| PostgreSQL `crawl_queue` autovacuum | Scale factor 0.2, threshold 50; 647k estimated live and 65k dead, 6.04M seq scans; last vacuum Nov 4 20:34 UTC. | Set table storage parameters to `autovacuum_vacuum_scale_factor=0.02`, `autovacuum_vacuum_threshold=1000`; analyze settings unchanged initially. | Trigger vacuum around 14k dead tuples rather than ~129k under global defaults, reducing dead-tuple accumulation on a high-churn queue. | More frequent vacuum I/O; assess during crawl peak and watch vacuum duration/lag. | No restart; table storage parameter takes effect immediately after metadata lock. | `ALTER TABLE crawl_queue RESET (autovacuum_vacuum_scale_factor, autovacuum_vacuum_threshold);` |
| PostgreSQL `performer_media` | Defaults 0.2/50; 1.32M live and 207k dead estimate (~13.5%); last vacuum Nov 4 17:59 UTC. | Set scale factor 0.05 and threshold 1000; leave analyze unchanged for first rollout. | Trigger around 67k dead tuples instead of ~265k. | More vacuum I/O and possible contention; validate with table-level dead tuple and vacuum-duration trends. | No restart; table storage parameter takes effect immediately after metadata lock. | `ALTER TABLE performer_media RESET (autovacuum_vacuum_scale_factor, autovacuum_vacuum_threshold);` |
| PostgreSQL `movies` | Defaults 0.2/50; 309k live and 45k dead estimate (~12.8%); last vacuum Nov 4 02:13 UTC. | Set scale factor 0.05 and threshold 1000; leave analyze unchanged initially. | Trigger around 16k dead tuples instead of ~62k. | More vacuum I/O; current evidence is an estimate, not measured physical bloat. | No restart; table storage parameter takes effect immediately after metadata lock. | `ALTER TABLE movies RESET (autovacuum_vacuum_scale_factor, autovacuum_vacuum_threshold);` |
| MariaDB slow-log no-index filter | `log_queries_not_using_indexes=ON` in `/etc/mysql/mariadb.conf.d/60-tuning.cnf`, `long_query_time=2`; 142 MB file and 2,644 fast query records in a 1 MiB tail sample (max 3 ms). SQL runtime access denied. | Turn `log_queries_not_using_indexes` OFF at runtime and in `/etc/mysql/mariadb.conf.d/60-tuning.cnf`, keep `slow_query_log=ON` and `long_query_time=2`. Apply only after an authorized SQL path verifies the source. | Stops the likely fast no-index log flood while preserving queries over 2 s; reduces disk churn and improves signal. | Short fast queries that use no index will no longer be recorded. Missing-index analysis must use a separate bounded digest/plan capture. | No restart expected; MariaDB variable is dynamic. Confirm live variable is dynamic and available after access is restored. | Restore `log_queries_not_using_indexes=ON` in `/etc/mysql/mariadb.conf.d/60-tuning.cnf` and runtime (`SET GLOBAL log_queries_not_using_indexes=ON;`). |

PostgreSQL memory settings (`shared_buffers`, `work_mem`, `effective_cache_size`, `maintenance_work_mem`) are not proposed for change: their current ratios are plausible for the observed RAM, shared-buffer hit is near 100%, and no memory-pressure evidence was collected. Do not increase `max_connections`; the role is already near its cap and each additional session can consume memory. PostgreSQL memory guidance notes that `work_mem` applies per operation and `max_connections` increases resource consumption ([PostgreSQL 18 resource settings](https://www.postgresql.org/docs/18/runtime-config-resource.html)).

### B) Changes that need a restart

| Setting/object | Current value and evidence | Proposed value | Expected benefit | Risk | Restart and safe window | Exact rollback |
|---|---|---|---|---|---|---|
| Redis `maxmemory` | `maxmemory=0`; VM has 31.35 GiB RAM; observed peak 127 MiB; current `noeviction`, AOF everysec. `CONFIG` is disabled, so runtime `CONFIG SET` is unavailable. | Add `maxmemory 20gb` to `/etc/redis/redis.conf`; retain `maxmemory-policy noeviction`, AOF, and `appendfsync everysec`. Recheck growth rate and dataset before choosing a final cap. | Bounds Redis memory and leaves roughly 11 GiB for OS, AOF rewrite copy-on-write, and other services. | Once at cap, queue/cache writes fail rather than evicting queue data; monitor rejected writes and memory headroom. Current dataset is much smaller, so the proposed ceiling is preventive, not driven by current pressure. | Redis restart required for the file-based change. Plan a low-crawl window, pause new dispatch, allow workers to finish, restart, then verify AOF recovery and Horizon reconnect. Expect a brief connection interruption; reserve ~5 minutes for reconnect/verification. | Remove the added `maxmemory` line (restoring effective `maxmemory=0`) and restart Redis in a planned window. Keep `noeviction` unchanged. |
| PostgreSQL `pg_stat_statements` instrumentation | Extension relation unavailable; `log_min_duration_statement=-1`; no top query totals available. Current `shared_preload_libraries` value was not captured. | In a separately approved change, verify the current preload list. If needed, add `pg_stat_statements` while preserving existing entries, restart PostgreSQL, then `CREATE EXTENSION pg_stat_statements` in the application database. If already preloaded, extension creation may not need a restart. Capture a fresh baseline after the collection window is agreed. | Enables normalized query calls, total execution time, rows, and block counters to identify real query/index candidates. | Brief planned outage if preload change is needed; small shared-memory/CPU overhead; normalized query text may still reveal sensitive query structure. | Conditional restart. If needed, schedule a low-crawl period, drain/stop Horizon and API DB clients, then restart and verify connections. Estimate 1–3 minutes of DB interruption, dependent on host recovery. | Restore the exact pre-change `shared_preload_libraries` list from a saved config copy and restart if it was changed, then `DROP EXTENSION IF EXISTS pg_stat_statements` in the database if installed. Capture the current list before editing; it was not available in this audit. |

PostgreSQL's checkpointer snapshot does not justify changing checkpoint/WAL settings. WAL counters were not captured, so defer tuning `max_wal_size`, checkpoint cadence, or WAL retention until a complete counter window exists.

### C) Application/repository changes

| Setting/object | Current value and evidence | Proposed value | Expected benefit | Risk | Restart? | Exact rollback |
|---|---|---|---|---|---|---|
| PostgreSQL application connection budget and attribution | 107 `xcrawler` sessions vs role limit 120; 110 TCP sockets from the three crawler-node addresses. Horizon defaults allow 22 worker processes/node; actual per-node overrides and process counts are unknown. RO metadata masked per-session state/application. | Add a distinct PostgreSQL `application_name` per role/node (`javmeta:<role>:<node>`) and publish connection counts by service/node. First target: keep `xcrawler` at or below 90 sessions (75% of role limit) after measuring live worker overrides; tune per-node Horizon worker settings only with queue-depth/throughput evidence. | Identifies holders and adds 30-session role headroom for deploy/reconnect spikes; lets worker concurrency be managed against DB capacity. | Reducing workers can lower crawl throughput or change queue lag. No fixed worker count is justified before collecting deployed overrides and queue metrics. | Application rollout/reload only; Horizon workers must recycle to pick up connection metadata or worker-count changes. | Revert the app change/connection label. If worker settings are changed, restore the exact pre-change `worker.*` values captured from the settings store; do not reset to code defaults blindly. |
| Mongo archive filter indexes | All 34 collections in `xcrawler` had only `_id_`; archive code filters `url` and `{entity_type, external_id}`. | Add non-unique `{url: 1}` named `archive_url_idx` and `{entity_type: 1, external_id: 1}` named `archive_entity_external_id_idx` to every existing and newly created site collection. Confirm whether production actually targets `xcrawler` or `javmeta_archive`; check duplicates before considering uniqueness. | Avoids repeated full collection scans for archive upserts and follow-up lookups as collections grow. | Index build uses disk/CPU and adds storage/write cost; non-unique indexes preserve existing duplicate behavior. Build one collection at a time and monitor. | No database process restart; repository migration/provisioner deployment and online index builds required. | For every affected collection in the confirmed database: `db.getSiblingDB("<confirmed-db>").getCollection("<siteSlug>").dropIndex("archive_url_idx")` and `dropIndex("archive_entity_external_id_idx")`. Revert the provisioner so new collections stop receiving them. |
| Mongo connection observability | `connections.current=241`, `active=2`, `available=25,359`; no per-client inventory was collected. | Add a stable Mongo client application name per role/node and expose pool/connection counts before setting a pool cap. Keep current server cache and connection ceiling unchanged until per-client measurements show pressure. | Attributes the 241 sessions and supports evidence-based pool sizing. | No immediate reduction in connection count; changing pool caps without topology data can reduce concurrency or cause waits. | Application rollout/reload only. | Revert the client label/metrics setting; no server setting changes are proposed. |
| MariaDB use and missing-index evidence | No production template selects MariaDB; live SQL was denied by host ACL. Slow-log tail has fast entries but query text/digests were not extracted. | Verify whether any deployed service selects MariaDB. If in use, restore an approved read-only SQL path and inspect bounded performance-schema digests/no-index samples with normalized values before proposing indexes. | Establishes whether this server serves javmeta and prevents speculative index changes. | No server/application tuning until usage and query plans are verified. | No restart for evidence collection; no code change identified yet. | Not applicable; diagnostic proposal only. |

## Recommended follow-up order

1. Restore an approved, read-only MariaDB SQL route and confirm whether any production application connects to it. Verify the live slow-log variable before changing its logging behavior.
2. Capture PostgreSQL connection attribution from an authorized view (`application_name`, client address, state, role) and the deployed Horizon worker overrides on `.20`, `.21`, `.22`; compare against queue depth and throughput before scaling workers down.
3. In a planned PostgreSQL maintenance window, enable `pg_stat_statements` only after recording the current preload list; gather a representative workload interval before index changes.
4. Review Mongo database-name drift between the live `xcrawler` stats and `MONGO_DATABASE=javmeta_archive` template. Then build non-unique archive indexes one collection at a time through a reviewed code change.
5. Add the Redis memory ceiling in a scheduled low-crawl window after checking growth and AOF rewrite headroom. Keep `noeviction` so queue/Horizon data is never selected for eviction.

## Sources

- PostgreSQL 18: [resource consumption settings](https://www.postgresql.org/docs/18/runtime-config-resource.html), [routine vacuuming](https://www.postgresql.org/docs/18/routine-vacuuming.html).
- MariaDB: [slow query log overview](https://mariadb.com/docs/server/server-management/server-monitoring-logs/slow-query-log/slow-query-log-overview), [server system variables](https://mariadb.com/docs/server/server-management/variables-and-modes/server-system-variables).
- Redis: [eviction policies](https://redis.io/docs/latest/develop/reference/eviction/).
- MongoDB: [serverStatus](https://www.mongodb.com/docs/manual/reference/command/serverstatus/), [WiredTiger](https://www.mongodb.com/docs/manual/core/wiredtiger/), [`$indexStats`](https://www.mongodb.com/docs/manual/reference/operator/aggregation/indexstats/).
