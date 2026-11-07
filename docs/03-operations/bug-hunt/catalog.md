# Bug hunt: catalog, detail pages, lists, filters, facets and the UI API

Ticket: JOOXCR-414. Branch: `JOOXCR-bughunt-catalog` (base `JOOXCR-373-readiness`, commit 1b667d9).
Report only. No application code or existing tests were changed and nothing was deployed.

Scope: `app/Http/Controllers/Ui/**`, `CatalogController`, `MovieSearchController`, `MovieFacetsController`,
`MovieRelatedController`, `MovieLookupController`, `PerformerController`, `PerformerFacetsController`,
`Http/Resources`, `Http/Requests` (catalog, movie search, performer search, calendar), `CatalogService`,
`MovieSearchService`, `MovieRepository` (list, facets, cursors), `PerformerRepository` (list, facets, show),
`CursorCodec`, `UiApiController`, `routes/web.php`, `routes/api.php`, `docs/03-operations/guest-mode.md`.

Method: read the full request path for each endpoint, wrote scratch PHPUnit reproductions under
`tests/Scratch/` (run through `docker/ci/run`, SQLite in-memory as configured by `phpunit.xml`), and ran
read-only SELECTs on production PostgreSQL (`xcrawler`) and read-only Sentry group queries (project 2, 30 days).
Production statements: about 5 SELECTs in total, all bounded, no errors.

Reproduction tests are in `tests/Scratch/BughuntCatalogScratchTest.php`. The file is reproduced verbatim in the
appendix, and it is deleted from the worktree before the commit (see the last section).

## Counts by severity

| Severity | Count | IDs |
| --- | --- | --- |
| S1 (security / outage / unbounded per request) | 2 | 005, 006 |
| S2 (wrong results or visible defect, or a 500) | 7 | 001, 002, 003, 004, 007, 008, 009 |
| S3 (minor / robustness / drift) | 8 | 010, 011, 012, 013, 014, 015, 016, 017 |
| Total | 17 | |

Status key: **CONFIRMED** = a scratch test or production query failed or returned the bad result as shown.
**SUSPECTED** = reasoned from code, not run here (the reason is given).

## Findings

| id | sev | status | file:line | summary |
| --- | --- | --- | --- | --- |
| BH-catalog-001 | S2 | CONFIRMED | `app/Http/Controllers/MovieSearchController.php:34` | Free-text-less browse with the default `sort=relevance`: page 2 returns 400 "The sort parameter cannot change between pages." |
| BH-catalog-002 | S2 | CONFIRMED (SQL), path SUSPECTED | `app/Services/Search/MovieSearchService.php:66`, `app/Repositories/MovieRepository.php:499` | Relevance page 1 served by Elasticsearch emits `last_sort: null`; page 2 from Postgres matches zero rows |
| BH-catalog-003 | S2 | SUSPECTED | `app/Services/Search/MovieSearchService.php:42`, `:63-64`, `:124-125` | Elasticsearch path caps `total` at 50 for `per_page=10` and `has_more` at 50 hits |
| BH-catalog-004 | S2 | CONFIRMED | `bootstrap/app.php:28`, `app/Http/Middleware/ThrottleGuestUiApi.php:24` | Guest rate limit bypassed by a spoofed `X-Forwarded-For` (trusted proxies is `'*'`) |
| BH-catalog-005 | S1 | CONFIRMED (rows), alerts SUSPECTED | `app/Http/Controllers/Ui/UiApiController.php` (GUEST_ALLOWED), `app/Listeners/RecordConsumerMissIncident.php:16`, `app/Listeners/SendMissedLookupAlert.php:22` | Anonymous guest lookups of unknown codes write a crawl event each and enqueue a per-code alert with an unbounded key |
| BH-catalog-006 | S1 | CONFIRMED | `app/Repositories/PerformerRepository.php:442`, `app/Http/Resources/PerformerResource.php:48` | `GET /performers/{id}` loads and serializes every gallery row (performer 1624: 45,014 rows) with no cap |
| BH-catalog-007 | S2 | CONFIRMED (catalog), SUSPECTED (facets) | `app/Services/Catalog/CatalogService.php:28`, `app/Http/Controllers/PerformerFacetsController.php:21`, `app/Services/Performance/PerformanceCache.php:39` | A non-UTF-8 `q` makes `json_encode` throw: 500 on the catalog endpoint |
| BH-catalog-008 | S2 | CONFIRMED | `app/Repositories/MovieRepository.php:75`, `app/Services/Catalog/CatalogService.php:92`, `app/Http/Requests/CatalogRequest.php:38` | A huge `page` gives a 500 (float-to-int overflow, and `TypeError` in `array_slice`) |
| BH-catalog-009 | S2 | CONFIRMED (indexes/code), latency SUSPECTED | `app/Repositories/MovieRepository.php:180` (`count()` on every page), `:305`, `:260` | Every list page counts the full filtered set; leading-wildcard `ILIKE` filters have no usable index |
| BH-catalog-010 | S3 | SUSPECTED | `app/Repositories/MovieRepository.php:260`, `:305`, `:414` | `%`/`_` in `code`, `maker`, `series`, `label` and the text-search performer branch are not escaped |
| BH-catalog-011 | S3 | SUSPECTED (explained by cache TTL) | `app/Services/Performance/PerformanceCache.php:37-44` | Facets `total` (cached 60 s) and list `total` (live) disagree: 309,887 vs 309,979 |
| BH-catalog-012 | S3 | SUSPECTED | `CatalogService.php:28`, `PerformerFacetsController.php:21`, `PerformanceCache.php:39`, `SearchController.php:29` | Cache key families are unbounded: raw `q`, page, and any filter combination, reachable by guests |
| BH-catalog-013 | S3 | CONFIRMED (code) | `app/Services/Catalog/CatalogService.php:52-94`, `app/Repositories/PerformerRepository.php:28` | Performer facet catalog loads every distinct value per cache miss and sorts and slices in PHP |
| BH-catalog-014 | S3 | SUSPECTED | `app/Http/Controllers/Ui/UiApiController.php:236` | Guest redaction rewrites legitimate text containing ` /word` into `[redacted path]` |
| BH-catalog-015 | S3 | CONFIRMED (code) | `app/Http/Requests/CatalogRequest.php:38` | Invalid `page` is silently coerced to 1 while invalid `per_page` returns 400 |
| BH-catalog-016 | S3 | CONFIRMED (code) | `app/Services/Calendar/MovieCalendarService.php:40` | The default calendar month is the UTC month; for the owner (ICT, UTC+7) it is the wrong month from 00:00 to 07:00 on the 1st |
| BH-catalog-017 | S3 | SUSPECTED | `app/Services/Search/CursorCodec.php:36` | `json_encode` of a non-finite sort value throws an uncaught `JsonException` (500) when encoding a cursor |

## Details

### BH-catalog-001 (S2, CONFIRMED): browse with default sort breaks at page 2

- **Reproduction:** `test_scratch_relevance_sort_without_query_breaks_page_two`. Three movies, then
  `GET /api/v1/movies?per_page=2&sort=relevance` (no `q`), then the returned `next_cursor` with `sort=relevance`.
- **Evidence:** page 2 returns `400` with message "The sort parameter cannot change between pages." Test FAILED
  (expected 200, one item).
- **Root cause (confirmed by code reading):** `MovieRepository::search()` line 176 rewrites `relevance` to
  `release_date` when there is no `q`, and the cursor encodes that effective sort. The controller (line 34)
  compares the explicit request `sort=relevance` with the cursor's `release_date` and rejects it. Whether the UI
  sends `sort=relevance` on paging was not checked (see "could not verify").
- **Fix:** resolve the effective sort once in the controller (or the service) before the cursor check, and compare
  against that. Do not let the repository change the sort silently.
- **Proposed test:** `MovieSearchControllerCursorTest::test_relevance_without_query_pages_when_sort_is_repeated`.

### BH-catalog-002 (S2, CONFIRMED at SQL level, path SUSPECTED): Elasticsearch page 1 cannot continue

- **Mechanism:** `MovieSearchService::search()` returns `'last_sort' => null` on the ES path (line 66). The
  controller encodes `last_id` into `next_cursor`. The next request has a cursor, so `canUseElasticsearch()` is false
  and `MovieRepository::applyRankCursor()` (line 499) runs with `lastSort = null`. The predicate becomes
  `ts_rank < NULL OR (ts_rank = NULL AND id < x)`, which is never true.
- **Production SQL (read-only, term `ssis`):** control `count(*)` = **949** matches. The same filter with the
  page-2 predicate built from `NULL::real` = **0**. Statement:
  `SELECT count(*) FROM movies WHERE search_vector @@ plainto_tsquery('simple','ssis') AND (ts_rank(search_vector, plainto_tsquery('simple','ssis')) < NULL::real OR (ts_rank(...) = NULL::real AND id < 100000000));`
- **Why SUSPECTED for the path:** ES is disabled under the testing environment (`app()->environment('testing')`),
  so the PHPUnit suite cannot reach it. Whether production has ES enabled and healthy was not checked.
- **Fix:** on the ES path, return the real rank or a stable ES-independent sort key in the cursor. Simplest: do not
  hand the ES page to the Postgres cursor; for the cursor, compute the rank in Postgres for the last hit's uuid.
  Alternatively reject a relevance cursor with a null sort and make page 2 use id-order consistently.
- **Proposed test:** `MovieSearchServiceTest::test_es_first_page_cursor_continues_without_empty_second_page`
  (fake ES returning 12 uuids, per_page 10, page 2 must return 2 movies).

### BH-catalog-003 (S2, SUSPECTED): Elasticsearch totals are capped

- **Evidence (code):** `MovieSearchService.php:42` requests `min(100, max($perPage * 5, $perPage))` hits. `total` is
  `count($uuids)` (lines 63 and 124) and `has_more` is `count($uuids) > $perPage` (lines 64 and 125). For `per_page=10` the total can
  never exceed 50, while the Postgres path reports the real total (for example 949 for `ssis`).
- **Impact:** the same query shows 50 on the ES path and 949 on the Postgres path. Combined with 002, pages past 1
  are unreachable.
- **Fix:** take the total from Postgres `countFiltered()` or from the ES `hits.total` (with `track_total_hits`), and
  do not derive `has_more` from the capped hit list.
- **Proposed test:** `MovieSearchServiceTest::test_es_total_matches_postgres_count`.

### BH-catalog-004 (S2, CONFIRMED): guest rate limit bypassed with a spoofed X-Forwarded-For

- **Mechanism:** `bootstrap/app.php:28` trusts every proxy (`trustProxies(at: '*')`) with `X-Forwarded-For`.
  `ThrottleGuestUiApi` keys its bucket on `$request->ip()` (`ThrottleGuestUiApi.php:24`). With every hop trusted,
  `ip()` returns the client-supplied first address, so each request can pick a fresh bucket.
- **Reproduction:** `test_scratch_guest_rate_limit_not_bypassed_by_spoofed_forwarded_for`: 21 guest requests to
  `/ui-api/meta/genres`, each with a different `X-Forwarded-For`. Expected the 21st to be 429; got **200**. Test
  FAILED. Control `test_scratch_guest_rate_limit_control_same_ip` (no spoofing) PASSED with 429 on the 21st.
- **Fix:** trust only the real load balancer's address(es) (for example the Docker network or the Cloudflare/LB IP
  list) and key the limiter on the socket address otherwise.
- **Proposed test:** `ThrottleGuestUiApiTest::test_spoofed_forwarded_for_does_not_reset_guest_bucket`.

### BH-catalog-005 (S1, CONFIRMED rows / alerts SUSPECTED): anonymous lookups write to the database

- **Mechanism:** `GUEST_ALLOWED` includes `movies/[A-Za-z0-9._-]+`, so an anonymous guest can request
  `/ui-api/movies/<anything>`. Unknown codes go through `MovieLookupController` → `ConsumerMissReporter::report()`
  (no length cap, only a `trim`) → `ConsumerMissedLookup` → `RecordConsumerMissIncident` (writes a crawl event with
  the raw query) and `SendMissedLookupAlert` (debounce key `miss:movie:<lowercased code>`).
- **Reproduction:** `test_scratch_guest_unknown_code_lookup_writes_no_incident`: three guest requests for
  `ZZZ-<unique>`, each returns 404 and each **adds one `crawl_events` row** (3 written, 0 expected). Test FAILED.
- **Impact:** unauthenticated writes per distinct string. The per-IP guest limit (004) is bypassable, so the write
  rate is not bounded. The alert debounce is per distinct code, so each new junk code is a new alert.
  Production context: `crawl_events` holds about 820k rows (estimate, pg_stat).
- **Fix:** do not record misses for guest requests (or only for owner and API-key requests), cap and validate the
  stored `query` (for example 64 chars and the normalized code shape), and debounce alerts by entity type rather
  than by raw string.
- **Proposed test:** `UiGuestModeTest::test_guest_unknown_code_does_not_write_incident` (the scratch test is the draft).

### BH-catalog-006 (S1, CONFIRMED): performer detail returns every gallery image

- **Mechanism:** `findForDisplay()` eager-loads `media` with no limit (`PerformerRepository.php:442`).
  `PerformerResource` passes the whole collection to `FormatsPhotos::photos()` (`PerformerResource.php:48`), which
  groups and maps every row to a DTO. The response has no pagination.
- **Production (read-only):** performer **1624 has 45,014 gallery rows**, 1630 has 41,491, 1660 has 29,243
  (`SELECT performer_id, count(*) FROM performer_media WHERE kind='gallery' GROUP BY 1 ORDER BY 2 DESC LIMIT 3`).
- **Reproduction:** `test_scratch_performer_show_caps_gallery_images`: one performer with 300 gallery rows returns
  **300 images in one response**. Test FAILED (expected at most 100). At 45k rows, the same path hydrates 45k models
  and builds 45k DTOs in one PHP request. Memory and response size were not measured here (estimate: several MB).
- **Fix:** keep `media` out of the detail payload, and serve galleries from a paginated sub-resource
  (`/performers/{id}/photos?cursor=`) with a per-gallery cap. For the detail response, return counts and the first N images.
- **Proposed test:** `PerformerShowTest::test_show_caps_gallery_images_and_paginates_the_rest`.

### BH-catalog-007 (S2, CONFIRMED for catalog; SUSPECTED for facets): malformed UTF-8 in q gives 500

- **Reproduction:** `test_scratch_catalog_rejects_malformed_utf8_query`: `GET /api/v1/meta/catalog/makers?q=%FF`.
  Result: **500**, `JsonException: Malformed UTF-8 characters` at `CatalogService.php:28`. Test FAILED (expected 400).
- **Same root cause, not run:** `PerformerFacetsController.php:21` and `PerformanceCache.php:39` call
  `json_encode($filters, JSON_THROW_ON_ERROR)` on the raw validated input, so the facet endpoints are expected to 500
  the same way.
- **Fix:** validate `q` as UTF-8 in `CatalogRequest` / `MovieSearchRequest` (reject with 400), and build cache keys
  from a hash of normalized bytes (`hash('sha256', serialize(...))` or `md5` over a fixed tuple) that cannot throw.
- **Proposed test:** `CatalogControllerTest::test_malformed_utf8_query_is_rejected_with_400`.

### BH-catalog-008 (S2, CONFIRMED): huge page number gives a server error

- **Mechanism:** `CatalogRequest` only does `max(1, (int) page)`. `(page - 1) * per_page` overflows to a float, which
  PHP cannot cast to int.
- **Reproduction (movies catalog):** `test_scratch_catalog_huge_page_is_not_a_server_error`,
  `page=9223372036854775807`: **500**. `test_scratch_catalog_huge_page_exception` shows the cause:
  `ErrorException: The float 1.8446744073709552E+21 is not representable as an int, cast occurred`
  (Laravel promotes the PHP warning, so it becomes a 500).
- **Reproduction (performer catalog):** `test_scratch_performer_catalog_huge_page_is_not_a_server_error`,
  `/api/v1/meta/catalog/cups?page=9223372036854775807`: **500**, `TypeError: array_slice(): Argument #2 ($offset) must be of type int, float given`
  (`CatalogService.php:92`).
- **Silent-wrong variant:** where warnings are not promoted, the cast yields `0`, so the request silently serves page 1.
- **Fix:** bound `page` (for example `max:10000`, or return an empty page with the true `last_page` when past the end)
  and compute offsets with an overflow check.
- **Proposed test:** `CatalogControllerTest::test_page_beyond_limit_returns_400_not_500` for both kinds.

### BH-catalog-009 (S2, CONFIRMED indexes / SUSPECTED latency): each list page is O(matches)

- **Evidence (code):** `MovieRepository::search()` runs `$total = $query->count()` on every call (line 180), including
  cursor pages, so a page-2 request re-counts the whole filtered set. The count runs after `applyFilters()`, which
  includes `ILIKE '%…%'` on `display_code` / `code_normalized` (line 260) and `maker`, `series`, `label` (line 305).
- **Evidence (production, pg_stat / pg_indexes):** `movies` has about **309,986** rows. The indexes are the primary
  key, unique `code_normalized`, `search_vector` GIN, two `title_*` trigram GIN indexes, and sort indexes for
  release, rating and update date. There is no trigram index on `maker`, `series`, `label`, `display_code`, or
  `code_normalized`, so a leading-wildcard `ILIKE` on those columns is a sequential scan.
- **Principle:** a request should cost O(page size). This one costs O(matches) on every page, plus full scans for
  the performer-name and code filters.
- **Not measured:** actual latency (no EXPLAIN on production, per the scope rules).
- **Fix:** count only on the first page (or use an estimate and an explicit `total_is_estimate` flag), and add trigram
  indexes for the leading-wildcard columns or switch those filters to prefix search.
- **Proposed test:** `MovieRepositoryTest::test_cursor_page_does_not_run_a_count_query`.

### BH-catalog-010 (S3, SUSPECTED): LIKE wildcards are not escaped in several filters

- **Mechanism:** `escapeLike()` is applied to `genre` and `actress`, but `code` (line 260: `'%' . $code . '%'`),
  `maker` / `series` / `label` (line 305) and the performer branch of the text search (line 414:
  `'%' . $q . '%'`, passed to `performerIdsMatching()`) are not escaped.
- **Effect:** `q=a_b` matches "axb", `code=%` matches every code, and `maker=_` matches any maker with at least one
  character.
- **Fix:** route every user-supplied pattern through `escapeLike()`.
- **Proposed test:** `MovieRepositoryTest::test_text_filters_treat_wildcards_as_literals`.

### BH-catalog-011 (S3, SUSPECTED, explained): facets total and list total disagree

- **Evidence (code):** `PerformanceCache::facets()` caches the facet payload for 60 s
  (`PerformanceCache.php:37-44`), while the list `total` is computed live. The owner's 309,887 vs 309,979 difference
  (92 rows) is consistent with ingestion within one cache window.
- **Not verified:** the exact request and time. No production request was made.
- **Fix:** include `computed_at` (or the cache age) in the facets `meta`, and show it in the UI next to the total.
- **Proposed test:** `PerformanceCacheTest::test_facets_meta_reports_computed_at`.

### BH-catalog-012 (S3, SUSPECTED): unbounded cache key families

- **Evidence (code):** keys include raw `q` and page (`CatalogService.php:28`, 120 s), the full filter set
  (`PerformanceCache.php:39`, 60 s; `PerformerFacetsController.php:21`, 60 s), and the raw search text
  (`SearchController.php:29`, 60 s, up to 300 chars). The values are hashed so key size is bounded, but the number of
  entries is not. Combined with 004 an anonymous client can create entries at will.
- **Fix:** clamp page depth, normalize `q` (trim, lowercase, collapse spaces), and cap the number of distinct entries
  with a rate limit on cache writes.
- **Proposed test:** `CatalogCacheKeyTest::test_key_family_is_bounded_for_page_and_query`.

### BH-catalog-013 (S3, CONFIRMED by code): performer catalog loads all values per miss

- **Evidence:** `CatalogService::loadPerformers()` calls `facetGroups()` (`PerformerRepository.php:28`), which returns
  every distinct value of the column, then counts, sorts and slices in PHP (lines 52-94). The cost is O(distinct
  values) per cache miss, not O(page). Cache key includes `q` and page (see 012).
- **Fix:** do the grouping, filtering, ordering and `LIMIT/OFFSET` in SQL.
- **Proposed test:** `CatalogServiceTest::test_performer_facet_page_is_limited_in_sql`.

### BH-catalog-014 (S3, SUSPECTED): guest redaction mangles legitimate text

- **Mechanism:** `redactInternalReferences()` runs on every string in the guest payload. Line 236 replaces any
  `(^|[\s:=])/…` with `[redacted path]`, so the text "Cut /Director's" becomes "Cut [redacted path]". Strings whose
  `parse_url` host has no dot are dropped entirely.
- **Fix:** restrict path redaction to fields that carry paths (URLs and `file`/`path` keys), not to free text.
- **Proposed test:** `UiApiControllerTest::test_guest_redaction_keeps_legitimate_text`.

### BH-catalog-015 (S3, CONFIRMED by code): inconsistent pagination validation

- **Evidence:** `CatalogRequest.php:38` coerces `page` with `max(1, (int) …)`, so `page=abc`, `page=0` and `page=-5`
  silently become page 1. `per_page=abc` returns 400 (`min:1` on `(int) 'abc' = 0`).
- **Fix:** validate `page` like `per_page` (integer, min 1, max bound) and return 400.
- **Proposed test:** `CatalogRequestTest::test_invalid_page_is_rejected`.

### BH-catalog-016 (S3, CONFIRMED by code): calendar default month uses UTC

- **Evidence:** `config/app.php:71` sets `timezone => 'UTC'`. `MovieCalendarService.php:40` uses `now()->format('Y-m')`
  when no `month` is given. The owner is ICT (UTC+7), so from 00:00 to 07:00 ICT on the 1st of each month the default
  calendar shows the previous month.
- **Fix:** compute the default month in `config('javmeta.timezone')` (ICT) or in the client, and state the zone in the response.
- **Proposed test:** `MovieCalendarServiceTest::test_default_month_uses_the_owner_timezone`.

### BH-catalog-017 (S3, SUSPECTED): cursor encoding can throw on non-finite values

- **Evidence:** `CursorCodec::encode()` (line 36) uses `json_encode(..., JSON_THROW_ON_ERROR)`. A `NAN` or `INF`
  `last_sort` (from a float column) would throw an uncaught `JsonException` and return 500. PostgreSQL does not
  normally return these for `real` / `numeric`, so this is low likelihood.
- **Fix:** reject non-finite sort values before encoding, or encode them as strings.
- **Proposed test:** `CursorCodecTest::test_non_finite_sort_value_is_rejected_not_thrown`.

## Fix first (ranked)

1. **BH-catalog-006**: performer detail loads and returns all gallery rows (45,014 for one performer). Unbounded memory and response.
2. **BH-catalog-005**: anonymous lookups write crawl events and per-code alerts. Unauthenticated write amplification.
3. **BH-catalog-004**: guest rate limit bypass through `X-Forwarded-For` with `trustProxies('*')`. Enables 005 at volume.
4. **BH-catalog-008**: huge `page` gives a 500 on both catalog kinds. Trivial to trigger.
5. **BH-catalog-007**: malformed UTF-8 in `q` gives a 500 on catalog and the facet keys.
6. **BH-catalog-001**: browse with default sort breaks at page 2. Visible on the most common page.
7. **BH-catalog-002 / 003**: Elasticsearch relevance path cannot paginate and its totals are capped. Fix together.
8. **BH-catalog-009**: list cost is O(matches) per page, and leading-wildcard filters have no index.
9. **BH-catalog-010**: unescaped LIKE wildcards in code, maker, series, label and text search.
10. **BH-catalog-016**: calendar default month uses UTC instead of the owner's ICT.

## Checked and found OK

- **Cursor integrity (`CursorCodec`):** the payload is HMAC-SHA256 over the app key and compared with `hash_equals`,
  the sort is whitelisted, `last_id` must be a positive int, and a missing or malformed separator is rejected with 400
  (reasoned from code).
- **Cursor vs sort change (explicit sort):** a cursor is rejected when the explicit sort differs from the cursor's
  sort (the problem in 001 is the *effective*-sort case only).
- **Impossible calendar dates in `released_from` / `released_to`:** `date_format:Y-m-d` rejects `2026-02-31` with 400.
  Confirmed by `test_scratch_movie_search_rejects_impossible_calendar_date` and
  `test_scratch_date_format_accepts_impossible_date` (both PASSED). Good; earlier suspicion dropped.
- **Symbol-only `q`:** rejected by the `\p{L}\p{N}` check (reasoned from `MovieSearchRequest`).
- **Keyset cursors on Postgres for release_date (NULLS FIRST), rating (NULLS LAST) and update_date:** the NULL and
  tie branches are complete (reasoned against `MovieRepository::applyNullsFirstCursor` / `applyNullsLastCursor`).
- **Guest route allowlist (`UiApiController::GUEST_ALLOWED`):** write methods return 403 for guests, `on_disk` is blocked
  for guests, and the path regex rejects `?`, `..` across segments and other characters. Path traversal into
  `/api/v1/ops` was not reproduced.
- **Guest rate limiter with a single client IP:** 21st request is 429 (`test_scratch_guest_rate_limit_control_same_ip`, PASSED).
- **Sentry, last 30 days (project 2):** no issue groups matching `JsonException`, `ErrorException`, "Malformed UTF",
  or "not representable". The matched groups are all crawler-side fetch errors, outside this scope. So 007 and 008 are
  not yet visible in production telemetry.
- **Guest-mode doc route claims:** `api/v1/status` exists (registered in `AppServiceProvider.php:65`), and
  `GuestModeRoutePolicy`, `noindex` headers and the guest `Retry-After` 429 body exist as documented.

## Could not verify

- **UI JavaScript paging:** whether the browser sends `sort=relevance` explicitly on page 2 (decides how visible 001 is). Not read.
- **Elasticsearch in production:** whether ES is enabled and healthy (002, 003). The test environment disables ES.
- **Facets vs list totals at a given moment** (011): no request metadata was given and no production request was made.
- **Memory and response size for 45k-row performer detail** (006): measured nothing on production. Estimated only.
- **Alert delivery:** whether a Telegram alert is sent per distinct code (005): `SendMissedLookupAlert` was not traced to its transport.
- **Latency of list and facet queries** (009): no EXPLAIN on production data, per scope rules.
- **HTTP caching headers** for the non-UI API (ETag, `Cache-Control` on `/api/v1`): not checked.
- **Error-message leakage on the owner path** (non-guest 500s): not checked.
- **Redis key counts** (012): not queried (Redis out of scope for this hunt).
- **Prometheus:** not queried.
- **Performer id overflow** (for example `/performers/99999999999999999999`): reasoned to behave like 008 on PostgreSQL but not run (the test database is SQLite).

## Scratch tests (deleted from the worktree; content reproduced below)

- File: `tests/Scratch/BughuntCatalogScratchTest.php` (11 tests). Run with:
  `docker/ci/run php vendor/bin/phpunit tests/Scratch/BughuntCatalogScratchTest.php`
- Results at the time of the run: FAILED (bug reproduced): `relevance_sort_without_query_breaks_page_two`,
  `catalog_rejects_malformed_utf8_query`, `catalog_huge_page_is_not_a_server_error`, `catalog_huge_page_exception`,
  `performer_catalog_huge_page_is_not_a_server_error`, `guest_rate_limit_not_bypassed_by_spoofed_forwarded_for`,
  `guest_unknown_code_lookup_writes_no_incident`, `performer_show_caps_gallery_images`.
  PASSED (controls / OK): `movie_search_rejects_impossible_calendar_date`, `date_format_accepts_impossible_date`,
  `guest_rate_limit_control_same_ip`.

## Appendix: scratch test source (BughuntCatalogScratchTest.php)

```php
<?php

declare(strict_types=1);

namespace Tests\Scratch;

use App\Models\Movie;
use App\Models\Performer;
use App\Models\PerformerMedia;
use App\Services\Auth\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/** SCRATCH: reproduction only for the catalog bug hunt (JOOXCR-414). Not to be committed. */
final class BughuntCatalogScratchTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'javmeta.ui_login_required' => false,
            'javmeta.ui_guest_rate_limit_per_minute' => 20,
            'javmeta_domains.command_center_host' => 'ops.internal.test',
        ]);
        Cache::flush();
        $this->apiKey = app(ApiKeyService::class)->create('bughunt scratch')->plaintext;
    }

    /** BH-catalog: page 1 without q emits a release_date cursor; page 2 sent with the default sort=relevance is rejected. */
    public function test_scratch_relevance_sort_without_query_breaks_page_two(): void
    {
        Movie::factory()->count(3)->create();

        $first = $this->getJson('/api/v1/movies?per_page=2&sort=relevance', ['X-API-Key' => $this->apiKey]);
        $first->assertOk();
        $cursor = $first->json('meta.pagination.next_cursor');
        $this->assertIsString($cursor);

        $second = $this->getJson('/api/v1/movies?per_page=2&sort=relevance&cursor=' . rawurlencode($cursor), ['X-API-Key' => $this->apiKey]);

        $second->assertOk();
        $this->assertCount(1, $second->json('data'));
    }

    /** BH-catalog: a non-UTF-8 q on the catalog endpoint must be a 400, not an uncaught JsonException. */
    public function test_scratch_catalog_rejects_malformed_utf8_query(): void
    {
        Movie::factory()->create(['maker' => 'Alpha']);

        $response = $this->getJson('/api/v1/meta/catalog/makers?q=' . rawurlencode("\xFF"), ['X-API-Key' => $this->apiKey]);

        $response->assertStatus(400);
    }

    /** BH-catalog: a huge page number must yield an empty page (or 400), never a 500 from offset overflow. */
    public function test_scratch_catalog_huge_page_is_not_a_server_error(): void
    {
        Movie::factory()->create(['maker' => 'Alpha']);

        $response = $this->getJson('/api/v1/meta/catalog/makers?per_page=200&page=9223372036854775807', ['X-API-Key' => $this->apiKey]);

        $this->assertLessThan(500, $response->getStatusCode());
    }

    /** BH-catalog: an impossible calendar date (2026-02-31) must be rejected with 400 before it reaches the database. */
    public function test_scratch_movie_search_rejects_impossible_calendar_date(): void
    {
        $response = $this->getJson('/api/v1/movies?released_from=2026-02-31', ['X-API-Key' => $this->apiKey]);

        $response->assertStatus(400);
    }

    /** Control: PHP-side view of the same rule, to show the validator itself accepts the date. */
    public function test_scratch_date_format_accepts_impossible_date(): void
    {
        $passes = Validator::make(['d' => '2026-02-31'], ['d' => 'date_format:Y-m-d'])->passes();

        $this->assertFalse($passes, 'date_format:Y-m-d accepted 2026-02-31');
    }

    /** Control: the guest limiter works for a single client IP (20 per minute, so the 21st is 429). */
    public function test_scratch_guest_rate_limit_control_same_ip(): void
    {
        $last = null;
        for ($i = 0; $i < 21; $i++) {
            $last = $this->get('/ui-api/meta/genres');
        }

        $this->assertSame(429, $last->getStatusCode());
    }

    /** BH-catalog security: spoofing X-Forwarded-For (trustProxies '*') must not give a fresh guest rate-limit bucket. */
    public function test_scratch_guest_rate_limit_not_bypassed_by_spoofed_forwarded_for(): void
    {
        $last = null;
        for ($i = 0; $i < 21; $i++) {
            $last = $this->get('/ui-api/meta/genres', ['X-Forwarded-For' => '203.0.113.' . ($i + 1)]);
        }

        $this->assertSame(429, $last->getStatusCode());
    }

    /** BH-catalog abuse: an anonymous guest's unknown-code lookups must not write crawl incidents (3 requests, 3 new rows expected today). */
    public function test_scratch_guest_unknown_code_lookup_writes_no_incident(): void
    {
        $before = \App\Models\CrawlEvent::query()->count();
        for ($i = 0; $i < 3; $i++) {
            $this->get('/ui-api/movies/ZZZ-' . str_repeat('9', 20) . $i)->assertNotFound();
        }

        $this->assertSame($before, \App\Models\CrawlEvent::query()->count(), 'guest lookups wrote crawl events');
    }

    /** Diagnostic: surface the exception behind the huge-page 500 instead of the handled response. */
    public function test_scratch_catalog_huge_page_exception(): void
    {
        Movie::factory()->create(['maker' => 'Alpha']);
        $this->withoutExceptionHandling();
        try {
            $this->getJson('/api/v1/meta/catalog/makers?per_page=200&page=9223372036854775807', ['X-API-Key' => $this->apiKey]);
            $this->assertTrue(true);
        } catch (\Throwable $e) {
            $this->fail(get_class($e) . ': ' . substr($e->getMessage(), 0, 300));
        }
    }

    /** BH-catalog: the performer-facet catalog path with a huge page must not overflow into a 500 (array_slice offset). */
    public function test_scratch_performer_catalog_huge_page_is_not_a_server_error(): void
    {
        Performer::factory()->create(['cup' => 'C']);
        $this->withoutExceptionHandling();
        try {
            $this->getJson('/api/v1/meta/catalog/cups?per_page=50&page=9223372036854775807', ['X-API-Key' => $this->apiKey])->assertOk();
            $this->assertTrue(true);
        } catch (\Throwable $e) {
            $this->fail(get_class($e) . ': ' . substr($e->getMessage(), 0, 300));
        }
    }

    /** BH-catalog: performers.show returns every gallery image of a performer; 300 rows must not all come back in one response. */
    public function test_scratch_performer_show_caps_gallery_images(): void
    {
        $performer = Performer::factory()->create();
        PerformerMedia::factory()->count(300)->create([
            'performer_id' => $performer->id,
            'kind' => PerformerMedia::KIND_GALLERY,
            'meta' => ['gallery_id' => 'g1', 'position' => 1],
            'source_slug' => 'scratch',
        ]);

        $response = $this->getJson('/api/v1/performers/' . $performer->id, ['X-API-Key' => $this->apiKey]);

        $response->assertOk();
        $images = 0;
        foreach ($response->json('data.photos.galleries') as $gallery) {
            $images += count($gallery['images']);
        }
        $this->assertLessThanOrEqual(100, $images, 'performer show returned ' . $images . ' gallery images in one response');
    }
}
```
