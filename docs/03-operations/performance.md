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

Before JOOXCR-255 the performer text search took 0.7 to 1.4 s for any text (it scanned every performer, bio and alias); trigram indexes and one indexed branch per column made it 6 to 70 ms in isolation.

### JOOXCR-261: performer list profiling, 2026-10-25

WSL shared PHP 8.5 runtime, real staging catalog (29,766 movies / 30,777 canonical performers before; 29,799 / 30,809 after). Each row is 30 sequential repository calls in one bootstrapped process, bypassing HTTP, authentication and response serialization. PostgreSQL was read-only. These are application-layer samples, **not replacements for the web-server measurements above**. Crawling continued between runs; results are observations, not controlled causal estimates. Every SELECT over 50 ms was logged and distinct slow queries were explained with ANALYZE and BUFFERS.

| Repository operation | Before p50 / p95 | After p50 / p95 | First before / after |
|---|---|---|---|
| `performers?per_page=24` | 46.3 / 92.6 ms | 44.9 / 80.2 ms | 44.9 / 43.3 ms |
| `performers?q=aiko` | 17.5 / 20.6 ms | 13.0 / 20.8 ms | 24.5 / 20.8 ms |

The new batch counts are restricted to returned IDs; count sorting retains the existing correlated count. Both measured list paths are below 400 ms in this sample. An earlier run had default-list p95 increase from 104.9 to 119.7 ms: load variance is material, so a causal speedup is not established. Initial `EXPLAIN (ANALYZE, BUFFERS)` showed a sequential scan plus top-N name sort (about 22 ms / 2,925 shared hit blocks) and a source-relation sequential scan. The migration adds a partial `(COALESCE(name_romaji, name_kanji, name_kana, ''), id)` index and a `performer_sources(performer_id)` index, smaller relation first.

**Pending QA:** no indexes were installed on staging. The isolated PostgreSQL provisioner failed persisting its metadata (`Invalid cross-device link`); its environment generator subsequently refused the worktree database. PostgreSQL index build/lock duration and after-index plans are therefore unmeasured. The migration uses transactional `CREATE INDEX IF NOT EXISTS`, without `CONCURRENTLY`; QA must measure the write-lock interval before deploying it. SQLite regression tests exercise equivalent B-tree definitions, idempotency, rollback and unchanged name order. End-to-end warm p95 and expired-cache cold timing remain unverified.

To repeat the measurement, create a temporary key with a high `abuse_rpm`, call each endpoint with the `Host: jav.soulevil.com` header, and revoke the key.
