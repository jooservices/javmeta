# Memory-discipline audit of the application

**Ticket:** JOOXCR-412
**Audit date:** 2026-11-07
**Base:** branch `JOOXCR-412-memory-audit` at `1b667d9` (see "Base caveat" below)
**Scope:** `app/` (Http, Data, Repositories, Services, Console, Jobs, Listeners, Observability, Logging), scheduled commands in `routes/console.php`
**Mode:** Report only. No source file was changed. Production was read with `SELECT` and `pg_stat*`/`pg_stats` queries only. No HTTP request, Redis command or write was sent to any server.

## Owner principle

A web request must cost O(page size), never O(table size). Large record sets are processed in chunks by workers, aggregates are precomputed, every list has a hard maximum, every in-memory structure and every cache or Redis key family has a documented bound and a TTL.

## 1. Numbers

### 1.1 Real table sizes

Row counts are planner estimates from `pg_stat_user_tables.n_live_tup` (read 2026-11-06 to 2026-11-07). Classes: SMALL <= 10k, MEDIUM 10k-100k, LARGE > 100k. Where a per-row or per-movie figure was needed, it was measured with a single indexed or small read-only probe (noted).

| Table | Rows (estimate) | Class | Note |
|---|---:|---|---|
| movie_observations | 4,180,064 | LARGE | 1,000-movie probe: 14.45 rows per movie |
| movie_genres | 2,159,825 | LARGE | brief said 2,267,554; estimate drift, class unchanged |
| movie_media | 1,743,439 | LARGE | heaviest movie 25395 = 4,871 rows (measured) |
| performer_name_grams | 1,733,835 | LARGE | identity matching |
| performer_media | 1,324,153 | LARGE | heaviest performer 1624 = 45,014 gallery rows (measured) |
| crawl_events | 804,481 | LARGE | no retention job (M-027) |
| crawl_fetch_attempts | 770,348 | LARGE | pruned daily by `crawler:prune-fetch-attempts` |
| crawl_queue | 667,200 | LARGE | status: done 92.6%, pending 6.9%, failed 0.53%, retry_pending 0.007% (pg_stats) |
| movie_performers | 563,526 | LARGE | |
| movie_sources | 410,336 | LARGE | |
| movie_codes | 391,640 | LARGE | |
| crawl_runs | 382,299 | LARGE | no retention job (M-027) |
| performer_name_keys | 231,561 | LARGE | |
| performer_sources | 156,665 | LARGE | |
| performers | 156,664 | LARGE | distinct location 47, cup 11, blood_type 4 (pg_stats) |
| performer_identity_reviews | 146,781 | LARGE | |
| movies | 309,959 | LARGE | distinct maker 826, series 2,136 (pg_stats) |
| performer_identity_event_movie_links | 47,928 | MEDIUM | |
| performer_aliases | 13,095 | MEDIUM | |
| genres | 13,404 | MEDIUM | |
| api_usage_log | 2,464 | SMALL | no retention job (M-027) |
| failed_jobs | 2,020 | SMALL | |
| api_keys | 560 | SMALL | |
| crawl_stats_hourly | 5,303 | SMALL | pruned by `crawler:stats-rollup` |
| sources | 39 | SMALL | |
| alert_events | 166 | SMALL | |
| notification_templates | 77 | SMALL | |
| users | 1 | SMALL | single owner |
| library_files, media_scenes, media_subtitles, download_items, user_marks, cache, cache_locks, sample_dataset_rows | 0 | SMALL / empty | no planner stats; see "could not verify" |

Cache backend: the Postgres `cache` table has 0 rows, so the application cache lives in Redis (db1), as the owner stated. The production env template defaults `CACHE_STORE=database`; this was not checked on the server. Redis policy from `docs/03-operations/db-tuning-audit.md` (2026-11-05): `maxmemory=0`, `noeviction`.

### 1.2 Recipe patterns: raw hits under `app/`

| Pattern | Raw hits (`grep -rnF`) | Notes |
|---|---:|---|
| `->get()` | 96 | |
| `->all()` | 152 | most are `request->all()`, `config->all()`, `settings->all()`, collection `->all()` on already-bounded data |
| `::all(` | 0 | |
| `->pluck(` | 73 | |
| `->toArray()` | 38 | |
| `->collect(` / `collect(` | 1 / 11 | |
| `DB::select(` | 5 | `BusinessQueryRepository::select` is the generic raw-SQL entry point (see "could not verify") |
| `fetchAll` | 0 | |
| `iterator_to_array(` | 1 | `PerformerRepository::filter`, input is an argument array |
| `array_merge(` | 22 | |
| `Cache::get(` / `Cache::put(` / `Cache::remember(` | 30 / 20 / 12 | `Cache::forever` used 5 times |
| `smembers` / `hgetall` / `lrange` / `zrange` / `keys(` | 1 / 1 / 0 / 0 / 46 | `keys(` mostly `array_keys`; the only Redis `hgetall` is FeedPool diagnostics (per-minute hash, expires) |
| `Redis::` | 8 | |
| `->each(` | 1 | `RecrawlIncompleteCommand` |
| `chunkById(` / `lazyById(` / `cursor()` | 9 / 7 / 5 | all verified as bounded except the `cursor()` + `with()` pair (M-024) |
| `paginate(` / `limit(` / `take(` | 3 / 46 / 3 | |
| `chunk(` | 13 | |
| `json_encode` | 52 | not a memory issue unless the input is unbounded (covered by the row findings) |
| `fopen(` / `file_get_contents` | 5 / 15 | file reads reviewed; see M-026, M-028 |

Filtered set (after removing `request`, `config`, validation, `->table(` and `array_keys` false positives): 319 hits in the collection patterns above. Coverage is stated in section 1.4.

### 1.3 Verdict counts

Classified sites: 29 finding rows (section 3.1, M-001 to M-029) and 25 bounded rows (section 3.2, O-01 to O-25).

| Verdict | Findings | Meaning |
|---|---:|---|
| HIGH | 4 | Meets the brief's HIGH rule: LARGE table reaches PHP memory in a request or worker without bound, or an unbounded cache/Redis structure |
| RISK | 25 | Bounded in practice or small today, but no hard bound, or O(table) DB work on a request or minute path |
| OK (bounded) | 25 | Bounded by limit, chunk, window, fixed config, or the table is SMALL and static |

### 1.4 Coverage

Read in context (each hit with its surrounding query or loop): the `app/Http` and `app/Data` hits; the repository and service methods named in the findings and the neighbouring hits in the same files (including the Performer, Movie, UserMark, UiMarks, ApiKey, Library, CrawlQueue, CrawlStats, CrawlStatus, ObservabilityService, ReportDataService, QueryInterpreter, Source, Suggest, AlertOperations, NotificationDelivery and HealthStatus repositories); the Identity service loops (chunking verified from the loop structure); the commands named in the findings and the scheduled set in `routes/console.php`.

Not read line by line (classified from the grep line and the file's context only): Telegram and Notification services and repositories beyond the hits above, Media and SceneSearch services (per-file queries on empty tables), the Observability metric classes other than PublishMetrics and the three counter classes, Jobs and Listeners (no filtered hit), the Elasticsearch indexer internals, and the Report and node sampler services beyond NodeSampleStore. These are listed in "could not verify".

## 2. Method

1. Recipe grep over `app/` (section 1.2), filtered for non-data false positives.
2. Each finding was read in its file and traced to its route, command or schedule.
3. Table classes from section 1.1. Planner statistics (`pg_stats`) were used for distinct counts and most-common-value frequencies, which give tail sizes without a scan.
4. Three indexed probes (one movie, one performer, one 1,000-movie id range) measured per-row tails and the conflict rate. No `count(*)` was run on a LARGE table.
5. Memory estimates assume: an Eloquent model with 10-20 attributes = 1.3 KB; a `stdClass` row with 2 properties = 0.3 KB; a PHP array with 2 keys = 0.38 KB; a `PhotoDto` plus its array = 0.5 KB; a JSON photo entry = 0.2 KB; framework baseline = 20 MB; PHP memory_limit 128 MB (FPM and Horizon). These are assumptions, not measurements. Section 6 lists how to measure them.

## 3. Findings

Verdict key: HIGH, RISK, OK. "Context" is where the code runs: HTTP, queue job, scheduled command (cron), console (operator).

### 3.1 Findings (HIGH and RISK)

| id | file:line | loads | table (class) | context | bounded today | worst-case memory | verdict | fix pattern |
|---|---|---|---|---|---|---|---|---|
| M-001 | `app/Repositories/OwnerReportServiceRepository.php:136-140`; `app/Services/Reports/ReportService.php:69,138-150` | every conflicting (movie_id, field) pair as stdClass, then mapped to arrays | movie_observations 4.18M (LARGE) | HTTP: `GET /api/v1/admin/report/quality` (routes/api.php:45, Admin/ReportController.php:20 cache 60 s); `GET /ui-admin/report/{type}` (routes/web.php:70, UiAdminController::report) | none | ~240k pairs (probe: 768 of 10,000 groups conflict = 7.7%, of ~3.1M groups) x ~0.7 KB = **~165 MB peak**, over the 128 MB limit | **HIGH** | DB-side aggregate (count per field, count distinct movie) + precomputed snapshot |
| M-002 | `app/Repositories/PerformerRepository.php:442,453`; `app/Http/Controllers/PerformerController.php:74`; `app/Http/Resources/PerformerResource.php:82`; `app/Http/Resources/Concerns/FormatsPhotos.php:19-57` | all `performer_media` rows of the performer (`with media`), each to a `PhotoDto`, all serialised | performer_media 1.32M (LARGE); heaviest performer 1624 = **45,014** gallery rows (measured) | HTTP: `GET /api/v1/performers/{id}` | none | 45k x ~2.0 KB = ~90 MB + JSON ~9 MB + baseline 20 MB = **~110-140 MB** (at or over 128 MB) | **HIGH** | preview with cap + `truncated` flag; paginated photos endpoint (per_page <= 200) |
| M-003 | `app/Services/Search/MovieSearchService.php:47,109`; `app/Repositories/MovieRepository.php:178`; `app/Services/Movies/MovieLookupService.php:106`; `app/Repositories/UiMarksMovieRepository.php:61`; `app/Services/Ui/UiMarksService.php:45-49`; `app/Http/Resources/MovieResource.php:70-74,118-136`; `app/Http/Resources/MovieSummaryResource.php:83-89` | every `movie_media` row (gallery, magnet, hls) of each movie in the page; list resources need only the sample cover | movie_media 1.74M (LARGE); heaviest movie 25395 = **4,871** rows (measured); `pg_stats` tail confirms several movies above 500 | HTTP: `GET /api/v1/movies` (per_page <= 100), search, related (LIMIT 12 via search), detail `GET /movies/{code}`, `GET /ui-me/movies/{kind}` (up to 500 codes, no pagination) | per_page <= 100 and 500 codes; media per movie **unbounded** | typical page ~5 MB; 100 movies with ~1,000 media each ~150 MB; UI list with ~20 movies of ~4k media each ~125-130 MB | **HIGH** | constrained eager load (`coverMedia`) for list resources; gallery preview cap + `truncated` on detail; paginate UI list (<= 200) |
| M-004 | `app/Services/Workers/HorizonLayoutSnapshot.php:61` (SADD, no expiry); `app/Console/Commands/Observability/PublishMetricsCommand.php:188` (SMEMBERS); `app/Services/Observability/NodeEgressProbe.php:226` (`Cache::forever` meta per node/host), `:561-590` (`Cache::forever(NODE_INDEX_KEY)`; prune only of the legacy hostname) | one entry per node identity: Redis set `javmeta:horizon:worker-layout:nodes`, cache index `NODE_INDEX_KEY`, forever meta keys | node identities (container hostnames churn per deploy; `app/Support/NodeIdentity.php`) | scheduled: `obs:publish-metrics` every minute (routes/console.php:68); `node:egress-probe` every 2 min | none: no TTL, no cap | ~60-100 B per identity; negligible today, unbounded by design | **HIGH** (rule: unbounded cache/Redis structure without TTL; practical impact low) | TTL + cap + prune by last-seen |
| M-005 | `app/Repositories/MovieRepository.php:381-412` (facets, unfiltered branch); via `app/Services/Performance/PerformanceCache.php:37-47`; `perf:warm` every minute with refresh (routes/console.php:82) | GROUP BY / DISTINCT over whole `movie_genres` join `genres`, `movie_codes`, `movie_performers` | movie_genres 2.16M, movie_performers 564k, movie_codes 392k (LARGE) | HTTP cache miss on `GET /movies/facets`; scheduled every minute | cache 60 s; PHP output <= ~100 rows | PHP < 1 MB; DB does an O(table) aggregate per minute | RISK (DB) | precompute facet rollup on a schedule; serve unfiltered facets from the table |
| M-006 | `app/Repositories/PerformerRepository.php:225-226` (DISTINCT cup / blood_type / location per filter), `:296-330` (facets); `app/Http/Controllers/PerformerFacetsController.php:21` (cache key from filters, 60 s) | `SELECT DISTINCT column` over all performers for every filtered search or facet request | performers 157k (LARGE); distinct location 47, cup 11, blood_type 4 | HTTP: performer search with cup/blood_type/location, facets | distinct cardinality tiny | < 0.1 MB PHP; **full scan per request** | RISK (DB) | canonical values in SQL or small lookup table; cache distinct values 1 h |
| M-007 | `app/Services/Catalog/CatalogService.php:26-30,52-54`; `app/Repositories/PerformerRepository.php:28-34` (facetGroups) | GROUP BY `LOWER(col)` over performers; all groups to PHP, normalised, sorted, sliced | performers 157k (LARGE) | HTTP `GET /meta/catalog/{cups,blood_types,locations}` on cache miss (120 s). Cache key includes the free-text `query` (line 28) | groups = distinct values (small) | < 0.1 MB PHP; **full scan per miss** | RISK (DB) | precompute catalog rows; key by normalised query; cap query length |
| M-008 | `app/Services/Search/QueryInterpreter.php:402-408`; `app/Repositories/QueryInterpreterServiceRepository.php:86-106` (facetNames, catalogNames) | DISTINCT maker / series / genre labels over movies and genres, returned as full PHP arrays | movies 310k (LARGE); distinct maker 826, series 2,136 | HTTP `/search/parse` on cache miss (120 s) | cardinality of data (not capped) | ~0.5 MB PHP; **full scan per miss** | RISK | precompute name lists nightly; cap with truncated flag |
| M-009 | `app/Http/Controllers/MetaGenreController.php:18-26` | DISTINCT labels from genres, sorted in PHP; no cache, no pagination | genres 13.4k (MEDIUM) | HTTP `/meta/catalog/genres` | none (no cap) | ~1-2 MB per request | RISK (low) | cache 10 min; precompute |
| M-010 | `app/Services/Calendar/MovieCalendarService.php:29-60`; `app/Repositories/MovieRepository.php:106-130` (releaseCalendarRows) | every movie released in the requested month; PHP keeps 8 per day | movies 310k (LARGE); busiest month unverified | HTTP `/movies/calendar` (month validated `YYYY-MM`), cache 120 s | month window only; no LIMIT | ~0.5 KB x rows of the busiest month (est. 1-3k rows, ~1.5 MB) | RISK | DB-side per-day grouping with `ROW_NUMBER() <= 8`, or monthly precompute |
| M-011 | `app/Repositories/LibraryFileRepository.php:52-60`; `app/Services/Library/UiLibraryService.php:34-36`; `app/Http/Controllers/Ui/UiLibraryController.php:18-21` | DISTINCT codes of all present library files, as one array | library_files 0 rows in stats (owner library size unknown) | HTTP `/ui-admin/library/codes` | none | ~1 MB per ~10k codes | RISK (HIGH if the library exceeds ~200k files) | paginate or precompute; cap |
| M-012 | `app/Repositories/UserMarkRepository.php:32-42` (forOwner, LIMIT 2000), `:62-67` (follows, no LIMIT), `:91-115` (saveNamedSearch scope read, no LIMIT); `app/Services/Ui/UiMarksService.php:19-25,59-63` | owner marks as arrays; response unpaginated | user_marks 0 rows (single owner) | HTTP `/ui-me/marks`, `/ui-me/feed`, toggle | forOwner LIMIT 2000 only | ~2 MB | RISK (low) | paginate <= 200; LIMIT on follows and saved searches |
| M-013 | `app/Repositories/ApiKeyAdministrationRepository.php:33-40`; `app/Http/Controllers/Admin/ApiKeyController.php:14-16`; `app/Http/Controllers/Ui/UiAdminController.php:17-20` | all api_keys with snapshots, one response | api_keys 560 (SMALL) | HTTP admin keys | none | ~3 MB | RISK (low) | paginate <= 200 |
| M-014 | `app/Http/Controllers/Ui/UiPlayerController.php:24,40,50-52` | library files for a code, subtitles and scenes per file, all unlimited | library_files, media_subtitles, media_scenes (0 rows in stats) | HTTP `GET /ui-player/{code}` | none | KB per file; unbounded by source | RISK (low) | LIMIT 50 per list with `truncated` |
| M-015 | `app/Http/Controllers/SearchController.php:24-40` | cached payload of up to 50 movie and 50 performer summaries (est. ~250 KB) per (q, per_page, understand) | Redis db1 (cache); `maxmemory=0`, `noeviction` (db-tuning-audit 2026-11-05) | HTTP search, guest-reachable; key from user `q` (<= 300 chars) | TTL 60 s; key cardinality = request rate x 60 s | 100 distinct queries/min x 250 KB x 60 s ~ 25 MB of Redis | RISK | cache ids only or <= 12 items; per-client throttle on distinct queries; document a Redis memory budget |
| M-016 | `app/Http/Controllers/StatusController.php:32-35` (sourceRows GROUP BY); `app/Repositories/CrawlStatusServiceRepository.php:56-74` (queueStatus: 2 GROUP BY + count); `app/Repositories/CrawlQueueRepository.php:28-37` via `OperationsDashboardService.php:35` | aggregates over the whole crawl_queue per cache miss | crawl_queue 667k (LARGE) | HTTP `/status/sources` (15 s cache) and operations dashboard | output = sources x statuses (small) | < 0.1 MB PHP; **full scan per miss** | RISK (DB) | counters maintained at queue transitions, or a 15 s precompute by a worker |
| M-017 | `app/Repositories/CrawlQueueRepository.php:213-260` (pacingActivityBySource: claimed group-by + correlated NOT EXISTS into crawl_fetch_attempts, plus attempts group-by); `app/Console/Commands/Crawler/FeedPoolCommand.php:91,101`; schedule `routes/console.php:65` (every 5 s) | aggregates over claimed queue rows and crawl_fetch_attempts | crawl_fetch_attempts 770k (LARGE); crawl_queue 667k | scheduled every 5 s, twice per run | by source (small) | < 0.1 MB PHP; DB work per run | RISK (DB) | maintain a per-source rollup on each attempt; keep the window index check |
| M-018 | `app/Repositories/ObservabilityServiceRepository.php:19-33` via `PublishMetricsCommand.php:616`; schedule `routes/console.php:68` (`obs:publish-metrics` everyMinute, **no `withoutOverlapping`**) | GROUP BY movie_id, field with COUNT(DISTINCT value_hash) over all observations | movie_observations 4.18M (LARGE) | scheduled every minute | 10 output rows | < 0.1 MB PHP; **full aggregate every minute**; overlapping runs possible | RISK (DB; the top DB item) | precompute the conflict counter (incremental or hourly rollup); add `withoutOverlapping()` |
| M-019 | `app/Console/Commands/Observability/PublishMetricsCommand.php:540-580` (apiMetrics: `->get()` of all api_usage_log rows of the last minute; PHP sort for p50/p95) | Eloquent model per API call in the last minute | api_usage_log 2,464 (SMALL; no retention) | scheduled every minute | window only | ~1.3 KB x requests per minute: 10k rpm ~13 MB; 50k rpm ~65 MB plus sort | RISK | DB-side count / avg / percentile_cont; select only needed columns; retention job |
| M-020 | `app/Services/Report/NodeSampleStore.php:14-54` (REGISTRY_KEY array, appended, TTL refreshed by every write, never capped); `PublishMetricsCommand.php:209-213`; `app/Services/Report/ReportDataBuilder.php:71` (`all()` loads every node x 180 samples) | node names; up to 180 samples per node | cache (Redis) | scheduled (every minute) and report build | per node <= 180 (config max); registry **uncapped** | 5 nodes x 180 x ~1 KB ~1 MB; registry grows with identities | RISK | cap registry (e.g. 100) and prune by last sample time |
| M-021 | `app/Services/Crawl/FetchMethodPreference.php:160-178` (rememberHost: array grows per distinct host, TTL refreshed); `:104-124` (one cache read per host per check) | per-source list of crawled hosts; N+1 reads per crawl decision | cache (Redis) | queue jobs (crawl decision per fetch) | state per host bounded (window 10); **host list uncapped** | small today; scales with crawled host set | RISK | cap at 200 hosts (oldest out); `Cache::many` for state reads |
| M-022 | `app/Services/Crawl/CrawlQueueService.php:386-394` -> `app/Repositories/CrawlQueueRepository.php:474-480` (failedForSource `->get()` of all failed rows of one source, then requeue each) | every failed crawl_queue row of one source as Eloquent models | crawl_queue failed ~0.53% = ~3.5k rows (pg_stats) | console: `crawler:retry-requeue` | none | ~1.1k rows x ~3 KB ~3 MB today; scales with failed count | RISK | `lazyById(500)` or `chunkById` with the requeue inside |
| M-023 | `app/Console/Commands/Crawler/RecrawlIncompleteCommand.php:26,35-40` (`--limit` option uncapped; `->limit($limit * 3)->get()->each`) | movie_codes joined to movies, sorted by crawled_at | movie_codes 392k, movies 310k (LARGE) | scheduled hourly (routes/console.php:72) | default 200 x 3 rows; `--limit` has no maximum | ~1 MB default; unbounded with a large `--limit` | RISK (low) | cap `--limit` at 1000; chunk; check the crawled_at index |
| M-024 | `app/Console/Commands/SwitchEmbeddingsCommand.php:74-82` | `cursor()` with `with(['codes','performers','genres'])`: **cursor() ignores eager loads**, so each movie lazy-loads relations | movies 310k (LARGE) | operator command | streamed (memory OK) | none for memory; ~3 extra queries per movie (~1M queries) | RISK (time) | `chunkById(500)` with `with()` |
| M-025 | `app/Services/Identity/PerformerIdentityService.php:1015-1027` (backfillCandidates: when `review_name_similarity < 0.82`, lazily scans all roots for each source) | every canonical performer per source | performers 157k (LARGE) | console identity backfill | memory bounded (`lazyById(200)`); CPU O(sources x roots) | none for memory | RISK (time; config dependent) | keep the trigram block; boot-time check on the config floor |
| M-026 | `app/Services/Library/LibraryScanner.php:34-85` (`$seen` id set over the whole library); `app/Repositories/LibraryFileRepository.php:91-97` (presentIdsInFolder plucks all present ids per folder) | library file ids as PHP arrays | library_files 0 rows in stats (owner library size unknown) | scheduled hourly (routes/console.php:80) | none | ~80 B per id: 100k files ~8 MB; 1M files ~80 MB+ | RISK (HIGH above ~500k files) | set-based mark-and-sweep in SQL (`UPDATE ... WHERE last_seen_at < ?`); no id arrays |
| M-027 | retention gaps (routes/console.php has prune only for fetch attempts, crawl_stats_hourly, legacy streams) | DB growth: crawl_events 804k, crawl_runs 382k, api_usage_log 2.5k, failed_jobs 2k with no prune | crawl_events, crawl_runs (LARGE) | DB growth; raises the cost of M-016, M-017, M-018 | none | n/a (DB) | RISK | chunked retention commands (pattern: `PruneFetchAttemptsCommand`) |
| M-028 | `app/Console/Commands/Crawler/SourceHealthCommand.php:89-98` (source-health-history.jsonl read fully to find the last line; never trimmed) | file lines | 1 line per day | scheduled daily (04:00) | none | ~365 KB per year | RISK (low) | read the tail with `fseek`, or keep a last-line marker |
| M-029 | `app/Repositories/AlertOperationsServiceRepository.php:33-40,70-77` (activeEvents, resolved-pending lists); `app/Services/Downloads/DownloadService.php:49` (pluck all download_items) | alert and download rows, unlimited | alert_events 166; download_items 0 (SMALL) | UI admin / alerts | none | < 1 MB | RISK (low) | LIMIT 200 with `truncated` |

### 3.2 Bounded sites (OK)

| id | file:line | why it is bounded |
|---|---|---|
| O-01 | `app/Http/Controllers/MovieRelatedController.php:32-66` | LIMIT 12, cached 600 s per movie id |
| O-02 | `app/Http/Requests/MovieBulkRequest.php` (`BULK_LIMIT = 100`) | codes capped at 100 per request; `MovieLookupService::findByCodes` only loads those |
| O-03 | `app/Repositories/UserMarkRepository.php:46-56` (movieCodes) | LIMIT 500 |
| O-04 | `app/Repositories/UiMarksMovieRepository.php:49` (feedCodes) | LIMIT 60 |
| O-05 | `app/Repositories/LibraryFileRepository.php:76-87` (onDisk) | LIMIT 500 (no `truncated` flag: minor) |
| O-06 | `app/Repositories/PerformerRepository.php:94-100` (search) | per_page <= 100, LIMIT per_page + 1, per-page batch counts |
| O-07 | `app/Repositories/MovieRepository.php:47-80` (movieCatalog) | DB-side GROUP BY with `forPage`; PHP holds one page |
| O-08 | `app/Services/Reports/ReportService.php` (quality) via `OwnerReportServiceRepository::forEachQualityChunk` (line 32-37) | `chunk(1000)`, ordered by id |
| O-09 | `app/Services/Identity/PerformerIdentityService.php` (`streamBackfill`, `candidateRootIdPages`, `sourceBatches`, `createdMovieLinkPages`, `copyMovieLinks`, `ensureSourceLinks`) | chunks of 200/400/500; `lazyById(200)`; `chunkById(500)` |
| O-10 | `app/Services/Identity/PerformerCoCreditRule.php:59-60,137`; `PerformerCoCreditAudit.php:34,58,77,87,101` | `lazyById(200)` / `chunkById(200)` |
| O-11 | `app/Console/Commands/BackfillPerformerNormalizationCommand.php:21-83,149-150` | ID-cursor chunks, `--chunk` capped at 5000 |
| O-12 | `app/Console/Commands/BackfillEmbeddingsCommand.php:197-211` | `limit($batchSize)` by id cursor |
| O-13 | `app/Services/Crawl/CrawlStatsRollup.php:57-71`; `CrawlStatsServiceRepository.php:68-75` (attemptsForHour uses `cursor()`) | one hour per call; `cursor()` streams attempts |
| O-14 | `app/Services/Persist/MoviePersister.php:274-281,611-614` (observationsFor) | per movie (~14 observation rows) |
| O-15 | `app/Services/Dataset/SampleDataset.php:70-72,137,194-196` | fixed dataset size from config (sample only) |
| O-16 | `app/Services/Health/StagingReadiness.php:22,149-160` (readiness-history) | trimmed to `HISTORY_LIMIT = 30` on every write |
| O-17 | `app/Logging/ArrayLogStore.php`; `app/Providers/AppServiceProvider.php:116-118` | bound only when `APP_ENV=testing` |
| O-18 | `app/Repositories/ReportDataServiceRepository.php:63-73,82-90,160-168` | per window; `recentSnapshots` LIMIT |
| O-19 | `app/Services/Operations/OperationsDashboardService.php:63-75` (recentFailures(12), recentEvents(12)) | LIMIT 12 |
| O-20 | `app/Repositories/SuggestMovieRepository.php:40-85` (facet, genres); `SuggestPerformerRepository.php:36-44` | LIMIT from request (<= 20) |
| O-21 | `app/Services/Search/HybridSearchService.php:46` (pool <= 60); `MovieSearchService.php:36-48` (ES uuids <= 100) | explicit pool caps |
| O-22 | `app/Console/Commands/Crawler/RetryListCommand.php:20-34` | LIMIT 500 |
| O-23 | `app/Console/Commands/Crawler/StatsReportCommand.php:21-27,52-64` (`--hours` 1..8760) | option capped; output rows = hours x groups |
| O-24 | `app/Repositories/SourceRepository.php:50-58`; `CrawlQueueRepository` grouped aggregates | grouped by source (39 rows) |
| O-25 | `app/Services/Media/MediaQueue.php:77-78`, `MediaPolicy.php:31-34`, `SceneSearchService.php:20`, `BrowserServicesConfigController` (settings), `UiSettingsController` (settings) | per file or fixed settings; media tables are empty in production stats |

Note on O-05 and O-19: the owner rule asks for a `truncated` flag on capped lists. These are not HIGH.

## 4. HIGH task specs

Each spec names the files, the change, the tests that must fail before the change, and the acceptance checklist. Test runtimes: tests marked `@group memory` seed 20k+ rows and run in the nightly job, not on every PR.

### H-1 (M-001): Quality report conflict section without row hydration

**Files**

- `app/Repositories/OwnerReportServiceRepository.php`: replace `movieFieldConflicts(): array` (line 136) with `fieldConflictSummary(): array{movies_with_a_conflict:int, by_field:array<string,int>}`.
- `app/Services/Reports/ReportService.php`: `fieldConflicts()` (line 138) consumes the summary; keep the output keys `movies_with_a_conflict`, `rate_percent`, `by_field`.
- New `app/Console/Commands/Reports/RefreshFieldConflictsCommand.php` (`reports:field-conflicts`, hourly, `withoutOverlapping()`), writes the summary to cache key `javmeta:report:field-conflicts` with TTL 3 h (or to `report_snapshots`).
- `routes/console.php`: schedule the command hourly.

**Change**

1. Compute with two SQL aggregates, never with rows in PHP:
   - by_field: `SELECT field, COUNT(*) AS n FROM (SELECT movie_id, field FROM movie_observations GROUP BY movie_id, field HAVING COUNT(DISTINCT value_hash) > 1) x GROUP BY field` (<= 10 rows)
   - movies_with_a_conflict: `SELECT COUNT(DISTINCT movie_id) FROM (<same subquery>) y` (1 row)
2. `fieldConflicts()` reads the cached summary; on a cold cache it calls the aggregate once and stores it.
3. Delete `movieFieldConflicts()` if no caller remains (`rg movieFieldConflicts` must be empty).

**Tests** (`tests/Feature/Reports/QualityReportMemoryTest.php`)

1. `quality_report_conflict_section_matches_legacy_counts`: seed 50 movies with known conflicts; assert `by_field` and `movies_with_a_conflict` equal the legacy values. Passes before and after (golden guard).
2. `quality_report_does_not_hydrate_conflict_rows` (`@group memory`): seed 100,000 conflicting pairs in chunks; assert `memory_get_peak_usage()` growth across `ReportService::quality()` < 32 MB. **Fails before** (~165 MB).
3. `conflict_summary_is_served_from_cache_on_second_call`: call `quality()` twice; assert the second call issues zero queries on `movie_observations`. **Fails before** (one query per call).
4. `refresh_command_is_idempotent_and_non_overlapping`: run the command twice; assert one cache write per run and the schedule entry has `withoutOverlapping`. **Fails before** (command missing).
5. `admin_report_quality_payload_stays_small`: `GET /api/v1/admin/report/quality` returns 200 and the `field_conflicts` payload is <= 1 KB with 100,000 seeded pairs. Passes before and after (regression guard on payload shape).

**Acceptance**

- [ ] Test 2 passes with peak growth < 32 MB on 100,000 seeded pairs.
- [ ] Output JSON keys unchanged (test 1 and 5).
- [ ] One aggregate query per refresh; the request path reads the cache.
- [ ] `rg movieFieldConflicts app` is empty.
- [ ] Scheduled in `routes/console.php` with `withoutOverlapping()`.

### H-2 (M-002): Performer detail without the full gallery

**Files**

- `app/Repositories/PerformerRepository.php:441-443,452-453` (`findForDisplay`): remove `'media'` from both eager loads; keep `aliases`, `sources`, `withCount('movies')`.
- `app/Repositories/PerformerRepository.php` (new method `galleryPreview(int $performerId, int $limit = 24): Collection`): `PerformerMedia::where('performer_id', $id)->where('kind', 'gallery')->orderBy('id')->limit($limit)->get()` and `count` for the total (index on `performer_id`).
- `app/Http/Controllers/PerformerController.php:74` (`show`): call `galleryPreview` and `setRelation('mediaPreview', ...)`; pass the total.
- `app/Http/Resources/PerformerResource.php:82`: build `photos` from `mediaPreview`; add `photos_total` and `truncated`.
- New `app/Http/Controllers/PerformerPhotosController.php` and `app/Http/Requests/PerformerPhotosRequest.php` (per_page 1..200, default 60): `GET /api/v1/performers/{id}/photos`, `simplePaginate` on `performer_media` where kind = gallery, ordered by `id`.
- `routes/api.php`: register the route. `resources/openapi/javmeta.json`: document the new endpoint and the new fields.

**Change**

The detail response keeps the first 24 gallery items, reports the total, and sets `truncated: true` when the gallery is larger. Full galleries are available only through the paginated endpoint.

**Tests** (`tests/Feature/Performers/PerformerShowMemoryTest.php`)

1. `performer_show_returns_at_most_preview_photos`: seed 500 gallery rows; `GET /api/v1/performers/{id}`; assert at most 24 images, `photos_total` = 500, `truncated` = true. **Fails before** (returns 500).
2. `performer_show_issues_no_unbounded_media_query`: `DB::listen`; assert no statement selects `performer_media` without a `limit`. **Fails before.**
3. `performer_show_memory_growth_bounded` (`@group memory`): seed 20,000 gallery rows in chunks; assert memory growth of the request < 32 MB. **Fails before** (~40 MB+ at 2 KB per row).
4. `performer_photos_endpoint_caps_per_page`: `per_page=500` returns 422 or clamps to 200; response has <= 200 items and `meta.total`. **Fails before** (route missing).
5. `performer_photos_pages_are_stable`: 500 seeded rows over pages of 200; assert no duplicates and no gaps. **Fails before** (route missing).

**Acceptance**

- [ ] Test 3 peak growth < 32 MB on 20,000 rows.
- [ ] `GET /performers/{id}` cannot return more than 24 gallery items.
- [ ] `photos_total` and `truncated` present; OpenAPI updated.
- [ ] UI performer page renders with the preview and links to the paginated endpoint (UI change tracked separately if needed).

### H-3 (M-003): Movie lists load only the cover; detail caps the gallery

**Files**

- `app/Models/Movie.php`: add `coverMedia()` = `hasOne(MovieMedia::class)->where('kind', MovieMedia::KIND_SAMPLE)->orderBy('id')`.
- `app/Repositories/MovieRepository.php:178` (`search`): replace `'media'` with `'coverMedia'`.
- `app/Services/Search/MovieSearchService.php:47` and `:109`: replace `'media'` with `'coverMedia'`.
- `app/Repositories/UiMarksMovieRepository.php:61` (`summaries`): replace `'media'` with `'coverMedia'`.
- `app/Http/Resources/MovieSummaryResource.php:83-89`: read the cover from `coverMedia` (fallback to `media` only when loaded).
- `app/Services/Movies/MovieLookupService.php:106` (`queryWithDisplayRelations`): load `mediaNonGallery` (hasMany where kind != gallery) and a separate capped gallery preview of 100 rows.
- `app/Http/Resources/MovieResource.php:69-74,118-136`: gallery from the preview; add `gallery_total` and `truncated`.
- `app/Services/Ui/UiMarksService.php:45-49` (`movies`): paginate the codes list (per_page <= 200) instead of loading up to 500 movies at once; new request class or reuse `UiMarkedMoviesRequest`.

**Tests** (`tests/Feature/Movies/MovieMediaEagerLoadTest.php`)

1. `movie_search_loads_cover_media_only`: seed 20 movies x 300 gallery rows; `MovieRepository::search`; assert `relationLoaded('media') === false` for each movie. **Fails before.**
2. `movie_search_page_memory_growth_bounded` (`@group memory`): seed 100 movies x 300 gallery rows (30,000); assert memory growth of one page search < 32 MB. **Fails before** (~40 MB+ of models).
3. `movie_detail_caps_gallery_with_truncated_flag`: a movie with 1,000 gallery rows; `GET /api/v1/movies/{code}` returns <= 100 gallery items, `gallery_total` = 1000, `truncated` = true. **Fails before.**
4. `movie_summary_cover_url_unchanged` (golden): cover_url from the sample media still returned. Passes before and after.
5. `ui_marks_movies_paginates_with_hard_max`: `/ui-me/movies/{kind}?per_page=500` returns 422 or <= 200 items with `meta`. **Fails before** (no pagination).

**Acceptance**

- [ ] Test 2 peak growth < 32 MB.
- [ ] Movie list resources never load gallery rows.
- [ ] Detail response capped at 100 gallery items with `truncated`.
- [ ] `/ui-me/movies/{kind}` paginated with a hard max of 200.

### H-4 (M-004): Node identity registries get a TTL and a cap

**Files**

- `app/Services/Workers/HorizonLayoutSnapshot.php:61`: replace `sadd` with `zadd(NODE_REGISTRY_KEY, now()->timestamp, node)`; then `zremrangebyscore(key, '-inf', now()->subDay()->timestamp)`, `zremrangebyrank(key, 0, -201)`, and `expire(key, 604800)`. Use a new key name (`javmeta:horizon:worker-layout:nodes:v2`) so the old SET is left to expire.
- `app/Console/Commands/Observability/PublishMetricsCommand.php:188`: read `zrangebyscore(v2, now()-180, '+inf')` instead of `smembers`.
- `app/Services/Observability/NodeEgressProbe.php:587`: replace `Cache::forever(self::NODE_INDEX_KEY, $nodes)` with `Cache::put(..., now()->addDays(7))`; in `rememberNode` (line 575), keep the last 200 nodes after sort.
- `app/Services/Observability/NodeEgressProbe.php:226`: replace `Cache::forever($metaKey, $storedMeta)` with `Cache::put(..., now()->addDays(30))`.

**Tests** (`tests/Unit/Workers/HorizonNodeRegistryTest.php`, `tests/Unit/Observability/NodeEgressRegistryTest.php`; Redis via the test connection)

1. `horizon_registry_is_capped_at_200`: call `handle()` for 300 distinct nodes; assert `zcard <= 200`. **Fails before** (SET of 300).
2. `horizon_registry_prunes_nodes_older_than_one_day`: seed stale entries (2 days old); after `handle()`, they are gone. **Fails before.**
3. `horizon_registry_key_has_ttl`: assert `ttl > 0`. **Fails before** (no TTL, `-1`).
4. `egress_node_index_is_capped_and_expires`: `rememberNode` for 300 nodes; assert <= 200 entries and TTL > 0. **Fails before** (forever).
5. `egress_meta_keys_have_ttl`: assert the meta key has a TTL. **Fails before.**

**Acceptance**

- [ ] No `Cache::forever` remains in `NodeEgressProbe`.
- [ ] Horizon registry is a sorted set with a cap, a prune and a TTL.
- [ ] Old SET key expires on its own (documented in the deploy note).

## 5. Permanent guardrails

1. **Large-table ratchet** (`tests/Architecture/LargeTableLoadRatchetTest.php`). Static scan of `app/Http`, `app/Services`, `app/Repositories`, `app/Console` for `->get()`, `->all()` and `->pluck()` on builders whose base table is LARGE (list from section 1.1). A statement passes only if its chain contains `limit(`, `take(`, `paginate(`, `chunk`, `lazyById`, `cursor(`, an aggregate `selectRaw` / `groupBy`, or `->count()`. Baseline file `tests/Architecture/large-table-baseline.php` lists the current violations; the test fails on any new one. Developers delete baseline lines as they fix them.
2. **Pagination guard** (`tests/Architecture/ListEndpointPaginationTest.php`). Every GET route that returns a collection must have a `per_page` (or `limit`) rule with a hard `max:200` in its form request, or a fixed LIMIT documented in the route. Same ratchet baseline.
3. **Peak memory telemetry.** Record `memory_get_peak_usage(true)` at HTTP terminate (middleware) and at job completion (`JobProcessed` listener) and command end, emitted through the existing observability stream as `php_peak_memory_bytes{kind, name}`. Also register a shutdown function that emits `php_memory_exhausted_total` when `error_get_last()` contains "Allowed memory size". Alert when p95 peak > 80% of memory limit: 0.8 x 134,217,728 = **107,374,182 bytes** for FPM and Horizon workers (`memory` 128 MB).
4. **Cache and Redis TTL guard** (`tests/Architecture/CacheWriteHasTtlTest.php`). Fail on `Cache::forever(` outside an allow-list; fail on `Cache::put`/`Cache::add` without a TTL argument; fail on Redis `sadd`/`hset`/`zadd` without `expire` in the same method. Same ratchet baseline. Separately (ops decision, needs approval): set a Redis `maxmemory` budget per cache DB after measurement; the 2026-11-05 audit found `maxmemory=0`.
5. **Process rule.** A PR that touches a LARGE table must show, in its diff, a chunk, paginate, or rollup, and a memory or query-count test. Add a checkbox to the PR template.
6. **Retention.** Add chunked prune commands for `crawl_events`, `crawl_runs`, `api_usage_log`, `failed_jobs`, following the `PruneFetchAttemptsCommand` pattern. Retention windows are an owner decision.

## 6. Could not verify

1. **Exact conflict count (M-001).** Only a 1,000-movie probe (768 of 10,000 groups, 7.7%) was run. The full count was not run because it is an aggregate over 4.18M rows. Confirm off-peak on a replica, or with `TABLESAMPLE`, before relying on 240k.
2. **Real peak memory** for M-001, M-002, M-003. The figures use the stated per-object assumptions. No HTTP request was sent to production, so they are not reproduced. H-1 to H-3 tests measure them.
3. **Gallery size distribution.** Only the MCV tail was measured (`pg_stats`). The per-page worst case for M-003 (how many heavy movies share one page) depends on the data and is unknown.
4. **Whether the UI calls `GET /performers/{id}` and the UI-movies list with the heaviest records.** Code paths confirmed; traffic not measured.
5. **Owner library size (M-011, M-026).** `library_files` shows 0 rows in `pg_stat`. Either the table is empty on this database or stats are missing. The library may live elsewhere.
6. **Exact failed count (M-022).** 0.53% from `pg_stats` (last analyze 2026-11-06); the exact count was not computed.
7. **Busiest-month movie count (M-010)** and **api_usage_log per-minute volume (M-019)** were not measured.
8. **Redis memory and key-family sizes.** Not re-measured in this audit. The Redis policy comes from `docs/03-operations/db-tuning-audit.md` (2026-11-05).
9. **Production `CACHE_STORE`.** The template defaults to `database`; the empty `cache` table is consistent with Redis. The server `.env` was not read (it may contain secrets).
10. **Node identity churn (M-004, M-020).** Whether container hostnames change per deploy in production; `app/Support/NodeIdentity.php` falls back to the hostname.
11. **Base caveat: JOOXCR-406.** The heartbeat fix (`d991ae2`, "Bound the worker heartbeat index") is on branch `JOOXCR-406-heartbeat-index`, not in this HEAD (`1b667d9`). Per the brief, `WorkerHeartbeat` and the status summary heartbeat part were not re-audited. Re-run the grep after 406 is merged.
12. **Eloquent and PHP object sizes** (1.3 KB model, 0.3 KB stdClass, 0.38 KB array, 0.5 KB PhotoDto) are assumptions. Measure with `memory_get_peak_usage` in the H-tests before accepting the numbers.
13. **`obs:publish-metrics` overlap.** Run duration is unknown; `withoutOverlapping()` is missing on the schedule (routes/console.php:68).
14. **Raw SQL entry point.** `BusinessQueryRepository::select` (`DB::select($query, $bindings)`) takes a string. Its callers were not traced; a caller with an unbounded query would not be visible in this grep.
15. **Coverage.** Not every filtered hit was read line by line; see section 1.4 for the list. No tests were run (report-only task).
16. **movie_genres count.** The brief's 2,267,554 differs from `n_live_tup` 2,159,825. The class is unchanged; the discrepancy is planner-statistics drift.
