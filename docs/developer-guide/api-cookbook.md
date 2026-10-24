# API cookbook

[Guide index](README.md) · [OpenAPI contract](../../resources/openapi/javmeta.json)

Choose the origin of a disposable development instance as `BASE`, supply an active key as `KEY` and the separate owner token as `OWNER_TOKEN` in your shell. The examples below use `$KEY` placeholders, never a real credential. Replace `SSIS-001` and performer ID `1` with records in that instance. For key administration, replace key ID `1` with the intended ID: the final command revokes it. Run selected commands, not the whole block, against a shared environment.

These commands are copied byte-for-byte from [the tested script](../../tools/docs/api-cookbook.sh). Curl transport is exercised with fixture credentials on loopback; every method/path/body is separately tested against Laravel with SQLite in `ApiCookbookTest`. This verifies syntax and application contracts without modifying staging.

```bash
#!/usr/bin/env bash
# Examples use only placeholders. Run against a disposable development instance.
set -euo pipefail
: "${BASE:?Set BASE to the application origin}"
: "${KEY:?Set KEY to an active API key}"
: "${OWNER_TOKEN:?Set OWNER_TOKEN to the owner admin token}"
curl --fail-with-body -sS -H "X-API-Key: $KEY" "$BASE/api/v1/auth/verify"
curl --fail-with-body -sS -H "X-API-Key: $KEY" "$BASE/api/v1/movies?q=SSIS&genre=Drama&genre_all=1&released_from=2025-01-01&runtime_min=60&has_cover=1&sort=relevance&per_page=5"
curl --fail-with-body -sS -H "X-API-Key: $KEY" "$BASE/api/v1/movies/facets?genre=Drama"
curl --fail-with-body -sS -H "X-API-Key: $KEY" "$BASE/api/v1/movies/SSIS-001"
curl --fail-with-body -sS -H "X-API-Key: $KEY" "$BASE/api/v1/movies/SSIS-001/related"
curl --fail-with-body -sS -H "X-API-Key: $KEY" -H 'Content-Type: application/json' --data '{"codes":["SSIS-001"]}' "$BASE/api/v1/movies:bulk"
curl --fail-with-body -sS -H "X-API-Key: $KEY" "$BASE/api/v1/performers?q=Yua&cup=D&blood_type=O&height_min=150&has_bio=1&sort=name&per_page=5"
curl --fail-with-body -sS -H "X-API-Key: $KEY" "$BASE/api/v1/performers/facets?cup=D"
curl --fail-with-body -sS -H "X-API-Key: $KEY" "$BASE/api/v1/performers/1"
curl --fail-with-body -sS -H "X-API-Key: $KEY" "$BASE/api/v1/suggest?q=SSIS&limit=5"
curl --fail-with-body -sS -H "X-API-Key: $KEY" "$BASE/api/v1/search?q=Drama&understand=0&per_page=5"
curl --fail-with-body -sS -H "X-API-Key: $KEY" "$BASE/api/v1/meta/genres"
curl --fail-with-body -sS -H "X-API-Key: $KEY" "$BASE/api/v1/meta/catalog/makers?sort=movie_count&per_page=5&page=1"
curl --fail-with-body -sS -H "X-API-Key: $KEY" "$BASE/api/v1/movies/calendar?month=2026-10"
curl --fail-with-body -sS -H "X-API-Key: $KEY" "$BASE/api/v1/status/summary"
curl --fail-with-body -sS -H "X-API-Key: $KEY" "$BASE/api/v1/status/sources"
curl --fail-with-body -sS -H "X-API-Key: $KEY" -H 'Content-Type: application/json' --data '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' "$BASE/api/v1/mcp"
curl --fail-with-body -sS -H "X-API-Key: $KEY" -H 'Content-Type: application/json' --data '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"lookup_movies","arguments":{"genre":"Drama","per_page":5}}}' "$BASE/api/v1/mcp"
curl --fail-with-body -sS -H "X-API-Key: $KEY" -H 'Content-Type: application/json' --data '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"get_movie","arguments":{"code":"SSIS-001"}}}' "$BASE/api/v1/mcp"
curl --fail-with-body -sS -H "X-API-Key: $KEY" -H 'Content-Type: application/json' --data '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"search","arguments":{"q":"Drama","entity":"all","per_page":5}}}' "$BASE/api/v1/mcp"
# Owner operations: these CREATE a key and REVOKE key 1; do not run this whole script on staging.
curl --fail-with-body -sS -H "X-Owner-Token: $OWNER_TOKEN" "$BASE/api/v1/admin/keys"
curl --fail-with-body -sS -H "X-Owner-Token: $OWNER_TOKEN" -H 'Content-Type: application/json' --data '{"label":"cookbook-reader","abuse_rpm":60,"scopes":["movies","catalog"]}' "$BASE/api/v1/admin/keys"
curl --fail-with-body -sS -H "X-Owner-Token: $OWNER_TOKEN" "$BASE/api/v1/admin/keys/1/usage"
curl --fail-with-body -sS -H "X-Owner-Token: $OWNER_TOKEN" "$BASE/api/v1/admin/report/quality"
curl --fail-with-body -sS -X DELETE -H "X-Owner-Token: $OWNER_TOKEN" "$BASE/api/v1/admin/keys/1"
```

## Families and pagination

| Family | Contract notes |
| --- | --- |
| `movies` | Filters combine; comma-separated `actress_id` requires all performers, multiple genres mean any unless `genre_all=1`. Source lists mean any. See [all UI filters](../user-guide/movies.md) and `MovieSearchRequest` for exact accepted values. `per_page` is 1–100. Follow `meta.pagination.next_cursor` while `has_more` is true, keeping filters and sort unchanged. |
| `movies/facets` | Send the same filters as the list; returns current-result counts and top values, not global totals. |
| `movies/{code}`, `movies:bulk`, `related` | Detail by normalized code; bulk POST takes `codes`; related uses a known movie. The [OpenAPI schema](../../resources/openapi/javmeta.json) describes per-code bulk outcomes and limits. |
| `performers`, `performers/facets` | Name/bio filters include cup, blood type, location, age, height, measurements, debut year, activity, source and presence checks. Lists accept page pagination as used by the UI; inspect `meta.pagination`. Detail accepts numeric ID or UUID. |
| `suggest` | At least 2 query characters; `limit` 1–20 per group. Returns movies, performers, genres, makers and series. |
| `search` | Hybrid movie/performer results plus `interpretation`; `q` 2–300 characters, `per_page` 1–50. `understand=0` disables interpretation, not semantic retrieval. Omit it to interpret a question. Cached for 60 seconds. Results may relax interpreted keyword conditions; inspect `interpretation.relaxed` when present. |
| `meta/genres`, `meta/catalog/{kind}` | Catalog kinds: genres, makers, series, labels, years, cups, blood_types, locations. Filter by `q`; sort `movie_count` or `name`; pages up to 200 items. Profile catalogs use `performer_count`. |
| `movies/calendar` | `month=YYYY-MM`, defaults to current month; returns days with counts and movie samples. |
| `status` | Summary counts, ingest/queue/workers/runs and per-source status. These are observed ingestion facts, not a guarantee of every external site's health. |
| `admin/keys`, `admin/report/{type}` | Requires `X-Owner-Token`, not an ordinary API key. Reports: quality, cost, sources (cached for 60 seconds). Key creation returns plaintext once. Revoke is DELETE; usage is a per-key read. |

`GET /api/v1/movies` with `semantic=1` is a separate semantic-only mode: only `q` and `per_page` may accompany it; filters, sort and cursor are rejected. The hybrid `/search` endpoint combines retrieval approaches instead. See `MovieSearchRequest` and `HybridSearchService`.

## Keys, scopes and limits

`X-API-Key` must contain an active key. Missing, invalid or revoked keys return 401. Supported scopes are `movies` (also `/search`), `bulk`, `performers`, `catalog` (also `/suggest`), `status`, `mcp`. Missing a required family scope returns 403. A null scope list means unrestricted access; the UI sends no scopes when no checkboxes are selected, so that creates an unrestricted key. Empty-array behavior should not be inferred from that UI shortcut.

`LogApiUsage` applies the key's `abuse_rpm` across authenticated data/MCP calls in a 60-second limiter window. The creation default is 60, allowed range 1–10000. A rejected over-limit request returns 429 and `Retry-After` seconds; wait before retrying. Owner token administration does not use this API-key limiter. Owner browser bridge calls bypass the key limiter and usage rows; login separately uses `throttle:6,1`.

## Responses and errors

Successful data responses put resources in `data` and pagination in `meta.pagination`. Do not infer pagination fields from array length. Application problem exceptions use `type`, `title`, numeric `status`, `detail`, and optional `errors` (see `ApiProblemException`, `HasApiResponses` and `PublicApiContractTest`). Examples of problem types are `unauthorized`, `forbidden_scope`, `invalid_filter`, `rate_limited`. Invalid movie filters return 400; some owner form validation returns Laravel's 422 validation response. Reports can return a standard error envelope rather than a problem exception. Check status and body instead of assuming all errors share one shape.

MCP additionally has JSON-RPC errors and tool `isError` results; see [MCP usage](mcp.md). Do not log authentication headers or full key-creation responses.

## Contract source

The public contract is `resources/openapi/javmeta.json`, served at `/api/openapi.json`. `tests/Feature/Api/OpenApiContractTest.php` checks the public document and compares every API route/method with the specification in both directions. Any new `/api` route needs a matching path/method there. This is route coverage, not proof that every schema detail is correct: add behavior tests too.
