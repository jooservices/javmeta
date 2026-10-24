# API response times on staging

Measured 2026-10-24 on the WSL staging host while 24 sources were crawling (load average about 8), with 30 sequential calls per endpoint through the web server, a key without a rate limit, and the page cache warm for the cached endpoints. The key used for the measurement was revoked afterwards.

| Endpoint | p50 | p95 |
|---|---|---|
| `movies?per_page=24` | 107 ms | 173 ms |
| `movies?q=love` | 129 ms | 252 ms |
| `movies?genre=Drama&sort=release_date` | 121 ms | 204 ms |
| `movies/{code}` | 100 ms | 210 ms |
| `movies/{code}/related` (cached 10 min) | 173 ms | 564 ms |
| `movies/calendar` | 91 ms | 136 ms |
| `performers?per_page=24` | 192 ms | 362 ms |
| `suggest?q=mi` | 135 ms | 177 ms |
| `meta/catalog/genres` | 58 ms | 160 ms |
| `meta/catalog/makers` | 57 ms | 120 ms |
| `status/summary` (cached 15 s) | 69 ms | 159 ms |

Target: p95 under 400 ms for every list or lookup call. Before the cache, `related` and `status/summary` took about one second each (JOOXCR-196). The first call after a cache expiry is still slow for these two.

To repeat the measurement, create a temporary key with a high `abuse_rpm`, call each endpoint with the `Host: jav.soulevil.com` header, and revoke the key.
