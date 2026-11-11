# API response times on staging

## JOOXCR-270 controlled HTTP baseline

**Run status — 2026-11-11: invalid; no benchmark was run.** This run did not have authorization for staging activity or current evidence that staging and its host/database load were healthy and quiet. The latest Jira health observation is an HTTP 503 from 2026-10-28; it is historical, not current health evidence. No benchmark route requests were sent. For search, status, movie facets, and performer facets, warm and after-expiry p50/p95, route errors, and staging load are all **not measured**; the <400 ms target is **not assessed**. Earlier measurements below do not satisfy this baseline.

| Route | Warm p50 / p95 / errors | After-expiry p50 / p95 / errors | Staging load | Target |
|---|---|---|---|---|
| `GET /api/v1/search` | Not measured — no route requests | Not measured — no route requests | Not measured | Not assessed |
| `GET /api/v1/status/summary` | Not measured — no route requests | Not measured — no route requests | Not measured | Not assessed |
| `GET /api/v1/movies/facets` | Not measured — no route requests | Not measured — no route requests | Not measured | Not assessed |
| `GET /api/v1/performers/facets` | Not measured — no route requests | Not measured — no route requests | Not measured | Not assessed |

### Running a controlled measurement

Use `php tools/profile-performance.php --help` for the complete option list. The tool is deliberately restricted to the documented staging origin `https://jav.soulevil.com`; it rejects private or reserved DNS answers, pins the resolved public addresses, verifies TLS, does not follow redirects, and sends GET requests only. It checks `/api/health` for status `ok` and `/api/v1/auth/verify` for HTTP 200 before sampling. A failed preflight produces a JSON invalid-run report and sends no benchmark route requests.

The API key must be supplied as `JAVMETA_STAGING_API_KEY` by an approved secret source, with access to the `movies`, `performers`, and `status` scopes and enough rate-limit capacity for the bounded run. The tool never prints the key or response bodies. Each route gets 30 sequential warm samples and 30 independent after-expiry samples. Before priming each route, the tool ensures its pre-run cache key has been expired for at least one second; the operator attests that no other caller will refill it. Every warm response must complete before the earliest possible expiration measured from the start of the prime request. Each cold sample waits for the route TTL plus one second after the previous request that could have filled the cache; the report includes all 30 wait durations and calculates p50/p95 from all 30 verified cold samples. The tool stops on the first failed benchmark request or unverified cache condition to avoid adding load after an error. The 30 independent cold samples take about 100 minutes across all four routes, plus warm requests and request time.

For a valid cold condition, verify that `perf:warm` is disabled for the run and that no other caller will repopulate these cache keys. Capture timestamped host 1/5/15-minute load averages and PostgreSQL CPU/memory utilization from the same approved read-only monitoring source immediately before starting and again within 60 seconds after the routes finish. The start snapshot must be no more than 60 seconds old when measurement begins. At completion, paste a JSON line in the format shown by `--help`; it records the ending snapshot and requires an operator attestation that the host/database remained quiet throughout the complete measurement interval. The source and both snapshots are recorded at run and route level. A missing, stale, future-dated, mismatched-source or non-quiet confirmation invalidates the assessment. Quiet status is an operator attestation because this ticket does not define numeric load thresholds. The benchmark client's load average is recorded separately.

Only after staging activity is authorized, staging is healthy, and the initial quiet load snapshot is available, run the tool with the secret injected into the process environment and the initial load values captured within 60 seconds before the run:

```bash
RUNTIME_PASS_ENV=JAVMETA_STAGING_API_KEY \
  /home/joos/Workspaces/scripts/docker/runtime php@8.5 php tools/profile-performance.php \
  --base-url https://jav.soulevil.com \
  --host-load-average "$HOST_LOAD_AVERAGE" \
  --db-cpu-percent "$DB_CPU_PERCENT" \
  --db-memory-percent "$DB_MEMORY_PERCENT" \
  --load-captured-at "$LOAD_CAPTURED_AT" \
  --load-source openobserve \
  --authorized-staging --confirm-quiet-load --confirm-cold-cache
# After all samples complete, paste one JSON line with the ending load snapshot:
# {"source":"openobserve","captured_at":"2026-11-11T12:00:00Z",
#  "host_load_average_1m_5m_15m":"1,1,1","database_cpu_percent":10,
#  "database_memory_percent":20,"operator_confirmed_quiet_for_interval":true}
```

The four routes have response-cache TTLs of 60 seconds (search), 15 seconds (status), and 60 seconds (each facets route). The tool reports nearest-rank p50/p95, HTTP/transport errors, request count, cache TTL, timestamped start/end load evidence, and whether each p95 is strictly below 400 ms. Any failed request, incomplete batch, unhealthy preflight, stale or future load snapshot, non-quiet interval, or unverified cache condition invalidates the target assessment. Preserve the JSON summary without credentials or response bodies and record the accepted values here after a valid run.

Measured 2026-10-24 (second round, after the performer search fix of JOOXCR-255) on the WSL staging host while 24 sources were crawling (load average 8 to 15): 30 sequential calls per endpoint through the web server with a key without a rate limit. The key used was revoked afterwards. The first round (JOOXCR-196) measured `performers?q=a`, which is a 400 (text too short), so it never exercised the performer text search; that gap is closed here.

| Endpoint | p50 | p95 |
|---|---|---|
| `movies?per_page=24` | 110 ms | 125 ms |
| `movies?q=love` | 116 ms | 163 ms |
| `movies?genre=Drama&sort=release_date` | 123 ms | 190 ms |
| `movies/{code}` | 85 ms | 109 ms |
| `movies/{code}/related` (cached 10 min) | 87 ms | 226 ms |
| `movies/calendar` | 57 ms | 92 ms |
| `performers?per_page=24` | 117 ms | 445 ms |
| `performers?q=aiko` | 89 ms | 454 ms |
| `performers?blood_type=O` | 120 ms | 169 ms |
| `suggest?q=mi` | 179 ms | 234 ms |
| `meta/catalog/genres` | 60 ms | 72 ms |
| `meta/catalog/makers` | 60 ms | 104 ms |
| `status/summary` (cached 15 s) | 76 ms | 992 ms |
| `search?q=beautiful+office+drama+2025` (hybrid, first ask) | 738 ms | 3,359 ms |

Target: p95 under 400 ms for list and lookup calls. Not met yet, and stated plainly:

- `search` (keyword plus vector search over movies and performers, with the question read into filters) is 0.4 to 0.7 s alone and much slower under the crawl load; asking the same question again within a minute is answered from the cache. It needs a lighter path for the performer vectors and the embedding call before it meets the target.
- `performers?per_page=24` and `performers?q=` have a p95 near 450 ms under load (the list counts movies per performer).
- The first call after a cache expiry is slow for `status/summary`.

### JOOXCR-261: hybrid search, 2026-10-25

WSL shared PHP 8.5 runtime; 30 sequential `HybridSearchService::search('beautiful office drama 2025')` calls per version in a bootstrapped staging process. PostgreSQL session was read-only; only the process loaded worktree classes, without deploying staging. Baseline: 29,766 movies / 30,777 canonical performers; after: 29,801 / 30,813. Every SELECT over 50 ms was logged, followed by EXPLAIN ANALYZE BUFFERS for distinct slow queries. Crawling continued, so load and catalog size changed between runs. These service timings exclude HTTP/authentication/bootstrap/serialization and do not replace the historical HTTP table.

| Operation | Before p50 / p95 | After p50 / p95 | First before / after |
|---|---|---|---|
| hybrid search, result cache bypassed | 627.3 / 1,572.9 ms | 488.9 / 677.5 ms | 1,556.8 / 677.5 ms |

The keyword full-text and performer match branches now use a UNION, allowing independent indexed plans. The two vector branches share one query embedding and use `Http::pool`. A full keyword performer page skips the performer vector request; sparse pages still fuse keyword and vector ranks. `search.budget_ms` (default 300, editable in Cài đặt → Tìm kiếm) bounds optional HTTP time across model interpretation, embedding, dependency checks and Elasticsearch; expiration returns keyword results. It does not cancel PostgreSQL or serialization, so it is **not a hard response-time limit**. Requests outside hybrid search retain their previous HTTP timeout values.

**Target assessment:** first direct after-call is under 1.5 s, but uncached p95 remains above 400 ms. An earlier round yielded p95 611.4 → 576.0 ms: variability is material, and these are observations rather than a controlled causal speedup. No warmed HTTP p95 or post-deployment improvement is claimed. QA must repeat the HTTP measurement with cold result-cache requests and include result quality when tuning the budget.

The in-process service profiler remains available alongside the HTTP benchmark:
`php tools/profile-performance.php 30 [comparison-root [job[,job...] [class.php[,class.php...]]]]`.
Its optional job filter accepts `performers`, `performers-aiko`, `status`, `facets`,
`suggest`, and `search`. When a comparison root is supplied, the optional class-file
list selects files loaded from that checkout before application bootstrap; otherwise
the current checkout is profiled. This mode forces the array cache and a read-only
PostgreSQL session, logs SELECT queries slower than 50 ms without bindings, and runs
`EXPLAIN (ANALYZE, BUFFERS)` for each distinct slow query. It does not contact staging
or measure HTTP latency. A first in-process call after bootstrap is not a cold
PostgreSQL buffer-cache measurement. Historical SQL plans and catalog terms should
remain private.

Before JOOXCR-255 the performer text search took 0.7 to 1.4 s for any text (it scanned every performer, bio and alias); trigram indexes and one indexed branch per column made it 6 to 70 ms in isolation.

### JOOXCR-261: performer list profiling, 2026-10-25

WSL shared PHP 8.5 runtime, real staging catalog (29,766 movies / 30,777 canonical performers before; 29,799 / 30,809 after). Each row is 30 sequential repository calls in one bootstrapped process, bypassing HTTP, authentication and response serialization. PostgreSQL was read-only. These are application-layer samples, **not replacements for the web-server measurements above**. Crawling continued between runs; results are observations, not controlled causal estimates. Every SELECT over 50 ms was logged and distinct slow queries were explained with ANALYZE and BUFFERS.

| Repository operation | Before p50 / p95 | After p50 / p95 | First before / after |
|---|---|---|---|
| `performers?per_page=24` | 46.3 / 92.6 ms | 44.9 / 80.2 ms | 44.9 / 43.3 ms |
| `performers?q=aiko` | 17.5 / 20.6 ms | 13.0 / 20.8 ms | 24.5 / 20.8 ms |

The new batch counts are restricted to returned IDs; count sorting retains the existing correlated count. Both measured list paths are below 400 ms in this sample. An earlier run had default-list p95 increase from 104.9 to 119.7 ms: load variance is material, so a causal speedup is not established. Initial `EXPLAIN (ANALYZE, BUFFERS)` showed a sequential scan plus top-N name sort (about 22 ms / 2,925 shared hit blocks) and a source-relation sequential scan. The migration adds a partial `(COALESCE(name_romaji, name_kanji, name_kana, ''), id)` index and a `performer_sources(performer_id)` index, smaller relation first.

**Pending QA:** no indexes were installed on staging. The isolated PostgreSQL provisioner failed persisting its metadata (`Invalid cross-device link`); its environment generator subsequently refused the worktree database. PostgreSQL index build/lock duration and after-index plans are therefore unmeasured. The migration uses transactional `CREATE INDEX IF NOT EXISTS`, without `CONCURRENTLY`; QA must measure the write-lock interval before deploying it. SQLite regression tests exercise equivalent B-tree definitions, idempotency, rollback and unchanged name order. End-to-end warm p95 and expired-cache cold timing remain unverified.

For a future HTTP rerun, use the JOOXCR-270 procedure above with an already authorized API key; key creation or revocation is a separate staging write and is not part of this benchmark tool.

### JOOXCR-341: performer normalization facets, 2026-11-03

One direct `PerformerRepository::facets()` call against the integration PostgreSQL catalog seeded with 26,000 performers took 90.41 ms end to end. Its single grouped query per indexed expression took 12 ms for `LOWER(cup)`, 11 ms for `LOWER(blood_type)`, and 11 ms for `LOWER(location)`. Existing functional indexes cover those expressions. The test also verifies only canonical Cup A–K, blood groups A/B/AB/O plus the optional unknown bucket, and canonical Tokyo are returned. This is one synthetic integration sample, not a production or HTTP p95 measurement.

### JOOXCR-261: status and facets, 2026-10-25

30 sequential direct service/repository calls per operation, one app bootstrap through the shared PHP 8.5 runtime on WSL. Real staging PostgreSQL was read-only; baseline catalog: 29,766 movies / 30,777 canonical performers; after: 29,799 / 30,810. Crawling continued during the samples. Every SELECT over 50 ms was logged, followed by EXPLAIN ANALYZE BUFFERS for distinct slow queries. These measurements exclude HTTP, authentication, bootstrap, serialization and shared Redis cache access; they measure recomputation on every call and do not replace the historical endpoint table above. After code was loaded from the worktree into the profiling process only; staging was not deployed.

| Recomputed operation | Before p50 / p95 | After p50 / p95 | First before / after |
|---|---|---|---|
| `status/summary` data | 1,299.0 / 1,662.3 ms | 378.9 / 700.5 ms | 1,210.9 / 369.4 ms |
| whole-catalog `movies/facets` | 522.4 / 870.8 ms | 343.5 / 861.1 ms | 1,016.1 / 844.8 ms |

Status now aggregates 24-hour run totals in SQL instead of hydrating every run. Facets deduplicate movie/genre-label pairs before grouping and avoid redundant whole-catalog self-subqueries. Distinct movie counts are retained across source duplicates and shared genre labels. No schema changes are included in this area.

`perf:warm` force-refreshes both existing cache keys every minute when `perf.warm_enabled` is true. An additional `perf:warm --status-only` event runs every ten seconds: a once-per-minute event alone would leave a 45-second hole in the existing 15-second status TTL. Facets keep their 60-second TTL; filtered facets remain on demand. Failed recomputation leaves the previous value intact until its original expiry and does not skip the other cache. Scheduler downtime, overlap or expiry can still expose a cold request.

**Target assessment:** the observed first calls are under 1.5 s; recomputation p95 still exceeds 400 ms. No end-to-end warmed-cache result is claimed: the scheduler and changes have not been deployed. QA must repeat 30 HTTP requests, include a first request after expiry, and verify the subminute scheduler before accepting the target. Baseline `suggest?q=mi` was also profiled: p50 113.1 / p95 283.2 ms; no suggestion code was changed. Earlier direct runs yielded status p95 1,310.1 → 985.2 ms and facets 1,788.5 → 658.0 ms; the variation means these are observations rather than a controlled causal speedup.

### JOOXCR-346: planned synthetic 2-million-movie scale run

**Status — not measured.** No 2,000,000-movie dataset, scale p95, backup duration, or restore duration has been measured. The `<400 ms` target is **not assessed** at this size. Existing measurements above remain historical observations and must not be extrapolated into a scale result.

#### Preconditions and isolation

- Run only against an isolated, test-only worktree namespace with synthetic data. Provision the worktree database and any required Redis and Elasticsearch namespaces before the run; use the shared PHP runtime and `docker/dev/shared-run --mode wt:<id>` where repository commands need it. The runner expects its test environment to exist and applies worktree-specific namespaces; it does not authorize access to staging or production. See [`docker/dev/shared-run`](../../docker/dev/shared-run).
- Record the application commit, migration level, database and search versions, relevant index definitions, CPU and memory limits, available disk, cache state, and background-work state. Keep other ingestion and benchmark workloads out of the measurement interval, or record the contention and mark the target assessment invalid.
- Generate synthetic records only. Do not copy production or staging rows or backups. Use a least-privilege test API identity with only the scopes needed for these requests and enough test-only rate-limit capacity; never include its credential in logs or evidence.
- The current [`tools/profile-performance.php`](../../tools/profile-performance.php) benchmark is restricted to its documented staging origin, so it is not a runner for this test. A separate test-only HTTP runner is a prerequisite; do not relax the staging guard as part of this documentation ticket.
- Define whether backup/restore covers PostgreSQL alone or the full application data set. The [backup runbook](backup.md) says full restore also depends on an Elasticsearch filesystem snapshot repository, which is not currently provisioned for the shared service and is tracked by JOOS-114. Until a test-only snapshot path is ready, PostgreSQL-only timing is partial evidence and cannot be reported as full-service restore readiness.

#### Synthetic dataset

A separate implementation task should provide a deterministic generator that inserts exactly 2,000,000 movies into the test database in bounded batches (for example, no more than 5,000 rows per batch). Record the generator revision, fixed seed, batch size, and data profile so another run can reproduce it. Populate the fields and relationships required by the selected list, search, and detail paths, with common terms, rare terms, and no-match cases. Document their synthetic frequencies and relationship cardinalities; where measured traffic data is unavailable, label them as assumptions and do not call the profile production-representative. Do not add schema fields or migrations for the benchmark.

At 500,000, 1,000,000, 1,500,000, and 2,000,000 rows, record the verified movie count, related-row counts, database and index bytes, and available disk. After loading, verify schema constraints and planner statistics before timing requests. A count mismatch, failed batch, or incomplete relationship set invalidates the run; retain the failure in its evidence.

When the run uses Elasticsearch, compare the document count of the configured movie index with the generator manifest before timing. Record any mismatch and invalidate the run: an empty index can trigger the PostgreSQL fallback and a partial index returns incomplete results, so search timings would be misleading.

#### HTTP measurements

Use the test-only HTTP origin and bounded page sizes from the [movie API contract](../developer-guide/api-cookbook.md). Measure at least:

- `GET /api/v1/movies?per_page=24` for the default list;
- `GET /api/v1/movies?q=<seeded-term>&per_page=24` for search, using fixed common and rare seeded terms;
- `GET /api/v1/movies/{code}` for detail, using a fixed reproducible sample of generated codes.

For each endpoint and cache condition, send 100 warm-up requests, then collect 1,000 measured requests in each of three independent runs using one sequential client. Keep cold-cache and warm-cache suites separate when the route is cached; warm-cache samples reuse primed keys, while cold-cache samples use unique seeded query keys or verified expiry in the isolated namespace. Keep cold-suite warm-ups on keys separate from its measured keys, and never flush a shared cache. If the cache condition cannot be verified, invalidate that suite. Never combine cache cohorts or average p95 values across endpoints. Time the full HTTP request with a monotonic clock, through response completion. Record the sample count, p50, p95, status and error counts, timeouts, query set, cache condition, request pacing, and load snapshot for every run.

Calculate nearest-rank p95 separately for each run: sort its `N` measured latencies and select the value at one-based rank `ceil(0.95 × N)`. Report each run's p95; do not pool runs or drop failed requests. Any HTTP error, timeout, incomplete sample set, unexpected rate limit, or unverified cache condition remains recorded and invalidates the target assessment. Assess `<400 ms` per endpoint and run; do not hide a slow route in an overall average.

Validate every measured response before it counts. A search response must be non-empty and contain the expected matches of its seeded cohort (common or rare term); a detail response must return the requested code. The runner performs these checks without retaining response bodies, and a response that fails them is a failed request that invalidates the run, so an index or dataset problem can never produce a valid-looking p95.

#### Backup and restore timings

After the test-only dataset and backup path are ready, run three separately identified backup/restore trials. Start backup timing when the test-only backup command begins; stop when the encrypted artifact and checksums are complete and verified. Start restore timing when restore begins; stop only after the target services report healthy and the restored movie and related-row counts match the generator manifest. Record backup and restore durations, artifact bytes, checksum result, validation result, and resource snapshots per trial. Keep every failed or partial attempt in the report; a retry is a new trial, not a replacement for the failure.

For a full-service trial, validate every service, not only service health and the PostgreSQL movie and related-row counts: compare the per-service counts required by the [backup runbook](backup.md) (PostgreSQL rows, MongoDB documents and Elasticsearch index documents) with the backup manifest. A trial with a missing or mismatching service count does not establish a full restore.

No numeric restore-time objective is defined here. Report measured durations as evidence only until a separate owner-approved RTO target exists. If any trial is incomplete or restore validation fails, do not claim restore readiness.

#### Evidence review and follow-ups

Before interpreting the result, attach a sanitized run manifest containing the commit and schema level, generator profile and seed, namespace suffix, row-count checkpoints, endpoint and query cohorts, warm-up and sample counts, per-run percentiles and errors, cache conditions, load snapshots, backup checksums and timings, and all failures. Do not store credentials, production data, or raw response bodies.

Review and retain evidence for each area below; the plan does not authorize schema, infrastructure, retention, or alert changes:

- **Indexes:** query plans and timing for the measured paths, indexes used, index bytes, relevant table growth, and any observed write cost. Identify required index changes as a separately reviewed migration task.
- **Retention:** retention rules for the synthetic database, backup artifacts, logs, and metrics; confirm the test namespace and exact cleanup scope before a later cleanup. Do not treat a test cleanup as permission to delete shared data.
- **Alerts:** the metrics and thresholds reviewed for database size, disk headroom, request latency/errors, and backup age/duration; include test-only trigger and recovery evidence where available. Do not send test alerts to production channels.

Keep the work split into independently verifiable follow-ups: build and validate the bounded synthetic generator and isolated namespace; add the test-only HTTP runner and collect p95 evidence; enable a complete test-only backup/restore rehearsal including the Elasticsearch prerequisite; then review index, retention, and alert evidence and publish the scale assessment. No follow-up results are implied by this plan.
