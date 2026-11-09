# API response cache

Read-heavy API responses and the guest UI bridge use a bounded server-side response cache. The cache covers movie and performer lists and facets, catalog responses, suggestions, status summaries, and movie detail. Owner UI requests and API-key requests are isolated by principal; API-key identity and sorted scopes are part of the fingerprint. Guest responses are cached only after the UI bridge applies its public-field redaction. Every UI API response is `private, no-store`; CDN caching of UI API routes is disabled.

## Settings and limits

`api.response_cache.enabled` disables response-cache reads and writes without changing endpoint behavior. The Settings page also exposes a TTL for each endpoint class:

| Class | Default TTL |
| --- | ---: |
| Lists | 5 seconds |
| Facets | 30 seconds |
| Catalog | 30 minutes |
| Suggestions | 10 seconds |
| Status | 5 seconds |
| Movie detail | 60 seconds |

The cache uses 64 fixed slots per class, stores only JSON `200` responses, and rejects bodies larger than 128 KiB. Query values are normalized from an endpoint-specific allowlist while the complete raw query is included in the fingerprint, so invalid and unknown values cannot reuse a valid response. Oversized/unsupported query shapes bypass caching. A slot records and verifies its complete fingerprint, so a collision causes eviction and a miss rather than returning another response. A Redis lock prevents concurrent rebuilds for a slot; while a rebuild is in progress, a still-valid stale entry can be served for up to 10 seconds. A cold request that cannot acquire the lock returns `503` with `Retry-After: 1` instead of starting a duplicate build.

At the configured limits there are at most 6 × 64 = 384 response slots. The raw response-body ceiling is 384 × 128 KiB = 48 MiB. Redis serialization, keys, fingerprints, and allocator overhead can raise the working set; allow approximately 96 MiB for this cache. Metrics use a separate fixed set of counters and do not add request-derived keys.

## Invalidation and failure behavior

Movie and performer persistence, gallery and identity reconciliation, title-failure updates, and sample-dataset load/wipe paths rotate `javmeta:api-response:epoch:v2` inside their database transaction. The epoch is stored as a UUID in the existing database cache table, not in Redis, using the primary database connection; its primary-key lookup avoids scanning domain tables and stays in the data transaction. It is part of each fingerprint, so writes invalidate all response classes without scanning or deleting Redis keys. The epoch row expires after one year; response entries expire in seconds or minutes, long before a generation could be reused. A failed epoch write rolls back the associated data transaction.

Redis is an optimization. A response-cache read, lock, write, or metric failure bypasses response caching and computes the endpoint response. Rate limiting fails closed with `503` and `Retry-After: 1` if its cache backend is unavailable. Oversized or uncacheable responses also return normally. API usage logging and API-key authentication continue to run on every API request. Performance facets use 64 fixed slots and skip caching when the serialized entry exceeds 128 KiB; status summaries have no second in-process cache, so the API response TTL controls their freshness.

Guest and owner UI API responses stay `private, no-store` and may include an ETag for conditional requests. Do not add `public`, `s-maxage`, or other shared-cache headers to these routes.

For Cloudflare, bypass every `/ui-api/*` request and purge previously cached UI API objects before enabling login. Do not enable edge caching for owner or guest UI API traffic. Disabling `api.response_cache.enabled` stops server-side cache reuse; purge any edge cache separately if an immediate purge is needed.

There are no database migrations. Rollback is to disable `api.response_cache.enabled` (and remove the Cloudflare rule if it was separately enabled); old Redis entries expire automatically.
