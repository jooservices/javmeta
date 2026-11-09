# API response cache

Read-heavy public API responses and the guest UI bridge use a bounded Redis response cache. The cache covers movie and performer lists and facets, catalog responses, suggestions, status summaries, and movie detail. Owner UI requests and API-key requests are isolated by principal; API-key identity and sorted scopes are part of the fingerprint. Guest responses are cached only after the UI bridge applies its public-field redaction.

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

The cache uses 64 fixed slots per class, stores only JSON `200` responses, and rejects bodies larger than 128 KiB. Query values are normalized from an endpoint-specific allowlist; list page sizes are capped, oversized/unsupported query shapes bypass caching. A slot records and verifies its complete fingerprint, so a collision causes eviction and a miss rather than returning another response. A Redis lock prevents concurrent rebuilds for a slot; while a rebuild is in progress, a still-valid stale entry can be served for up to 10 seconds.

At the configured limits there are at most 6 × 64 = 384 response slots. The raw response-body ceiling is 384 × 128 KiB = 48 MiB. Redis serialization, keys, fingerprints, and allocator overhead can raise the working set; allow approximately 96 MiB for this cache. Metrics use a separate fixed set of counters and do not add request-derived keys.

## Invalidation and failure behavior

Movie and performer persistence and the sample-dataset load/wipe paths increment `javmeta:api-response:epoch:v1` after their local database transaction succeeds. The epoch is part of each fingerprint, so writes invalidate all response classes without scanning or deleting Redis keys. The epoch expires after one year; response entries expire in seconds or minutes, long before an epoch could be reused.

Redis is an optimization. A cache read, lock, write, metric, or epoch-bump failure is fail-open: requests compute and return the normal endpoint response, and a failed epoch bump is logged. Oversized or uncacheable responses also return normally. API usage logging and API-key authentication continue to run on every API request.

Guest UI bridge responses receive `public, max-age=0, s-maxage=...` only when a safe response was stored (or served from that cache), plus stale directives and `Vary: Accept-Language`. Cache misses that cannot be stored remain `no-store`. Owner responses stay `private, no-store` and never receive a public cache policy.

For Cloudflare, configure the cache rule to bypass requests with an Authorization header or session/authentication cookies, and set the cache key to include the full query string and `Accept-Language`. Do not enable edge caching for owner or API-key traffic. Disabling `api.response_cache.enabled` stops server-side cache reuse; purge any edge cache separately if an immediate public purge is needed.

There are no database migrations. Rollback is to disable `api.response_cache.enabled` (and remove the Cloudflare rule if it was separately enabled); old Redis entries expire automatically.
