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

To repeat the measurement, create a temporary key with a high `abuse_rpm`, call each endpoint with the `Host: jav.soulevil.com` header, and revoke the key.
