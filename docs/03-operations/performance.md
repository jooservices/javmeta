# API response times on staging

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

Reproduce direct profiling from a checkout with staging access, through the required runtime wrapper: `scripts/docker/runtime php@8.5 php tools/profile-performance.php 30`. The script uses an in-process array cache, enforces read-only PostgreSQL, logs each SQL query over 50 ms without bindings, and runs `EXPLAIN (ANALYZE, BUFFERS)` for distinct slow SELECTs. A first call here means the first operation after bootstrap; it is not a cold PostgreSQL buffer-cache measurement. Optional arguments select a separate worktree's class files for a read-only before/after comparison; no tracked staging file is modified. Keep raw output private because plans contain catalog terms.

Before JOOXCR-255 the performer text search took 0.7 to 1.4 s for any text (it scanned every performer, bio and alias); trigram indexes and one indexed branch per column made it 6 to 70 ms in isolation.

To repeat the measurement, create a temporary key with a high `abuse_rpm`, call each endpoint with the `Host: jav.soulevil.com` header, and revoke the key.
