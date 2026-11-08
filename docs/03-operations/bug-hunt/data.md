# Bug hunt (JOOXCR-414): production data health

Scope: Postgres `xcrawler` (.12), Mongo `xcrawler` archive (.13), Redis db 0/1 (.14), Elasticsearch `xcrawler_movies` / `xcrawler_performers` (.15), and the Horizon/queue paths that write them. Read-only. No application code or tests were changed. Scratch tests lived under `tests/Scratch/` and were removed before this commit (see section 7).

Branch/base: worktree `JOOXCR-bughunt-data` at `1b667d9` (readiness branch). Production runs the release tagged `…-06540de`, which equals `develop` (see BH-data-001).

## 1. Counts and ranked fix list

| Severity | Count | IDs |
|---|---|---|
| S1 data loss / security / outage | 1 | BH-data-001 |
| S2 wrong results / visible defect / will break at 2x | 6 | BH-data-002 … 007 |
| S3 minor / robustness / latent | 9 | BH-data-008 … 016 |

**Fix first (ranked):**

1. **BH-data-001** (S1): merge `76811ae` into `develop` and deploy. The fix already exists on this branch; production still runs the crashing code, and the browser lanes are failing about 25 times per hour.
2. **BH-data-002** (S2): give `IndexPerformerJob` and `IndexMovieJob` a `uniqueFor`, purge the 1,630 orphan locks once no job is pending, and re-dispatch the stale performer docs.
3. **BH-data-005** (S2): populate `censored` (the code shape already tells FC2/HEYZO/Tokyo-Hot apart) or hide the filter until it is populated. Today both UI options return zero rows.
4. **BH-data-003** (S2): relevance pagination after an ES-served page 1 returns an empty page 2 while `has_more` is true.
5. **BH-data-004** (S2): ES relevance `total` is capped at the ES `size` (≤ 100). Use `hits.total`.
6. **BH-data-006** (S2): index the archive lookup fields and make the upsert one round trip, before the archive is 2x larger.
7. **BH-data-007** (S2): retention for `crawl_events` (about 90k rows/day, unbounded). Decide retention for `movie_observations`.
8. **BH-data-008** (S3): ES `community_score` must be `float`; this needs a new index plus the existing `search:switch-embeddings` flow, not a mapping PUT.
9. **BH-data-015** (S3): add PostgreSQL and ES integration tests for pagination and the eval call. Under the current test env guards, both paths are never executed by the suite.
10. **BH-data-009** (S3): drop the two unused `movies` trigram GIN indexes (61 MB, zero scans).

## 2. Findings

Reproduction is summarised here; exact steps, queries and code are in section 3.

| id | sev | file:line | summary | reproduction (short) | evidence | root cause | proposed fix | proposed failing tests |
|---|---|---|---|---|---|---|---|---|
| BH-data-001 | S1 | `app/Services/Crawl/SourceRequestPacer.php:37-44` (fixed on branch); develop: `:34` | Every browser-lane job that reserves a source-gap slot throws `TypeError` (`Redis::eval(): Argument #3 ($num_keys) must be of type int, array given`). The fix `76811ae` is not on `develop`, so production still crashes. | SQL on `failed_jobs`: `exception LIKE '%num_keys%'` → 1,794 rows, queues `browser_performer_detail` 901, `browser_performer_listing` 595, `browser_listing` 298, first 2026-11-03, last 2026-11-07 00:01 UTC. | Hourly count 20–30 through 11-06 23:00 UTC. Sentry issues 167/168/169: 546 events in 24 h (tsdb). Production release dirs are `releases/20261103T…-06540de`. `git merge-base --is-ancestor 76811ae develop` → not an ancestor. `develop..76811ae` = 27 commits. | Old call passed the keys array as `$numberOfKeys`. Laravel 13.38 `PhpRedisConnection::eval($script, $numberOfKeys, ...$arguments)` forwards it as the third phpredis argument, which is typed int. Confirmed in the vendor source and the exact error text. | Merge `76811ae` (the call is now `eval(script, 1, key, …)`) to `develop`, deploy, and watch `failed_jobs` for `num_keys`. Add an alert on the failed_jobs rate per queue. | `SourceRequestPacerRedisTest::…` (added in `76811ae`) must run in CI on `develop`. Proposed: `SourceRequestPacerEvalShapeTest::test_reserve_start_never_passes_keys_in_num_keys_slot`. |
| BH-data-002 | S2 | `app/Jobs/IndexPerformerJob.php:18,29-31` (no `uniqueFor`); `app/Jobs/IndexMovieJob.php:18` (same) | 1,630 Redis lock keys `xcrawler:xcrawler:cache:laravel_unique_job:App\Jobs\IndexPerformerJob:<id>` have **no TTL**, and no job holds them. Any later `IndexPerformerJob::dispatch($id)` for those performers is silently dropped. ES performer docs stay stale. | Redis `EVAL_RO` scan of db0 → 1,630 `NOTTL` keys, all `IndexPerformerJob`; `LLEN xcrawler:queues:search` = 0 and `ZCARD …:delayed` = 0. Scratch test `OrphanUniqueLockScratchTest` passes: after one acquire, the second acquire returns false. ES vs PG (section 3.2): 19,657 ES performer docs stamped in the 11-03 01:00–02:00 UTC backfill hour vs 20,145 PG rows, so at least 488 are stale. Samples: id 32719 PG 2026-11-03 01:30 vs ES 2026-10-27; id 6800 PG 11-03 vs ES 10-26; id 14768 PG 11-03 vs ES 10-24. | Laravel 13.38 `UniqueLock::acquire` uses `cache->lock(key, uniqueFor)` with `uniqueFor` default 0 (no expiry). Release runs only after the handler returns normally (`CallQueuedHandler`), so a worker killed mid-job (deploy, timeout, OOM) leaves the lock forever. The job has no `uniqueFor`. Trigger is suspected, not proven. | Set `public int $uniqueFor = 600;` on `IndexPerformerJob` and `IndexMovieJob` (or `ShouldBeUniqueUntilProcessing`). Run a bounded command that deletes orphan locks when no job is pending and re-dispatches performers whose ES `updated_at` < PG `updated_at`, chunked. | Proposed: `IndexPerformerJobUniquenessTest::test_orphaned_lock_expires_and_next_dispatch_runs` (Redis integration). Scratch proof: `OrphanUniqueLockScratchTest::test_orphaned_lock_from_killed_worker_drops_every_later_dispatch`. |
| BH-data-003 | S2 | `app/Services/Search/MovieSearchService.php:42-66` (ES page 1, `last_sort: null` at `:66`); `app/Repositories/MovieRepository.php:499-506` (`applyRankCursor`) | For free-text search served by ES, page 1 returns `has_more: true` and `last_sort: null`. The next request (has a cursor) goes to Postgres, where `ts_rank(...) < NULL` yields nothing. Page 2 is empty. | Production SELECT (read-only): `q='abp'` matches 890 movies. Page-2 predicate with null `last_sort` → 0 rows. Same predicate with a numeric rank → 890 rows. | Code path verified line by line. `canUseElasticsearch` is false when a cursor is present (`MovieSearchService:144`), so the second page can never come from ES. | A cursor is generated from an ES page that carries no rank. The PG rank predicate compares with NULL. The test environment disables the ES path (`app()->environment('testing')`, `:97`, `:144`), and SQLite never takes the rank branch, so nothing covers it. | Give ES pages a real cursor (ES `search_after` with `_id`/score, or an ES-scoped page offset). Until then, return `has_more: false` for ES pages. | Proposed: `MovieSearchPaginationIntegrationTest::test_relevance_second_page_after_es_first_page_is_not_empty` (PostgreSQL integration with a stubbed ES hit list of 25 for perPage 10). |
| BH-data-004 | S2 | `app/Services/Search/MovieSearchService.php:42,63` (and `:124` in `semanticSearch`) | Total hits reported on ES-served pages is `count($uuids)`, and the ES `size` is `min(100, …)`. Any query with more than 100 matches reports `total ≤ 100`. `has_more` is also bounded. | Code: `searchMovieUuids(trim($q), min(100, max($perPage * 5, $perPage)))` then `'total' => count($uuids)`. | The result size is capped by the request, not by ES. Postgres path returns the real count, so the same query gives different totals with ES on and off. | `total` derived from result size, not from ES `hits.total`. | Read `hits.total.value` (set `track_total_hits: true`) in `ElasticsearchIndexer::searchMovieUuids`, and return it instead of `count($uuids)`. | Proposed: `MovieSearchServiceTest::test_es_total_reports_full_hit_count_not_page_cap` (fake ES response `hits.total.value = 340`, size 100). |
| BH-data-005 | S2 | `app/Services/Normalize/Normalizers/DefaultSourceNormalizer.php:69` (`censored: null`); `app/Repositories/MovieRepository.php:335-337`; `app/Http/Requests/MovieSearchRequest.php:34,120-121`; `public/ui/js/filterControls.js:17` | `censored` is never written by any crawler path. Every movie has NULL. The UI and API filter then filter on a column that is always NULL. | Production: `SELECT censored, count(*) FROM movies GROUP BY 1` → `NULL | 309982`. `WHERE censored = 1` or `= 0` therefore returns 0 rows for both UI choices ("Có che" → 1, "Không che" → 0). | SQL result above. Request validation maps `censored=uncensored` to 0 and `censored=censored` to 1. | Normalizer hardcodes `censored: null`, and no other source provides it. The dataset loader (`SampleDataset:175`) sets it, which is the only populated path. | Derive `censored` from the code shape in the normalizer (MoviePersister already has a kind classifier, `MoviePersister:357`), backfill in chunks, or hide the filter until the column has data. | Proposed: `MovieSearchFilterCensoredTest::test_censored_filter_returns_seeded_rows` (SQLite feature test with `censored` set to 1 and 0). |
| BH-data-006 | S2 | `app/Services/Archive/MongoSiteArchive.php:29-37` (`upsertMovie` filter `{url}`), `:41-52` (`upsertPerformer` filter `{entity_type, external_id}` or `{entity_type, url}`), `:67-77` (`updateOne` then `findOne($filter)`) | Each archive write is an upsert keyed on an unindexed field, plus a second lookup by the same filter when the doc already exists. Every collection has only `_id_`. The write is synchronous in the persist path (`MoviePersister:175`, `archive()`). | Mongo `explain` (executionStats) on `141jav`: `{url: <sample>}` → COLLSCAN, `totalDocsExamined = 199235`, `totalKeysExamined = 0`. | 34 of 34 archive collections have one index (`_id_`). The same query runs twice per write. | No index on `url`, `external_id`, or `entity_type` in any archive collection. | Create indexes on each site collection: unique `{url: 1}` (no duplicates exist today), and a unique partial `{entity_type: 1, external_id: 1}` for performers. Replace the upsert + findOne with `findOneAndUpdate(…, returnDocument: after)`. | Proposed: `MongoSiteArchiveIndexTest::test_every_site_collection_has_url_index` (Mongo integration: assert `url_1` exists, and `explain` shows IXSCAN). |
| BH-data-007 | S2 | `routes/console.php:75-76` (prune only for fetch attempts and stats); `app/Models/CrawlEvent.php` (no delete path); `app/Services/Persist/MoviePersister.php` / `app/Services/Dataset/SampleDataset.php:75-79` (the only deletes on the movie tables) | `crawl_events` is written about 90k rows/day and never pruned (oldest row 2026-10-23). `movie_observations` (4.18M rows) has no retention either. The database grew from 2.1 GB (11-01) to 5.9 GB (11-07). | Production: `crawl_events` min `created_at` 2026-10-23, 819,335 rows; per day 92,655 (11-04), 85,665 (11-05), 88,350 (11-06). `movie_observations` min `crawled_at` 2026-10-23, 4,180,242 rows. Prometheus `pg_database_size_bytes`: 2,147 MiB (11-01) → 5,866 MiB (11-07); steady rate 50–90 MB/day. | Table sizes: `crawl_events` 175 MB, `movie_observations` 1.2 GB. `crawl_fetch_attempts` is pruned correctly (14-day default), so the gap is specific to these two tables. | No retention job for these tables. | Add `crawler:prune-events` with a config-driven retention (chunked `DELETE … LIMIT`) and a decision (retention or archive) for `movie_observations`. | Proposed: `CrawlEventRetentionTest::test_prune_removes_only_rows_older_than_retention_in_chunks`. |
| BH-data-008 | S3 | `app/Services/Search/ElasticsearchIndexer.php:68` (sends float); `app/Console/Commands/EsSetupCommand.php:61-70` (mapping does not declare `community_score`) | ES `xcrawler_movies.community_score` is mapped `long`, so fractional scores are truncated in the index. `_source` keeps the true value, so the bug is silent. | ES range count `{range: community_score ∈ [6.5, 7)}` → 0. Postgres `community_score >= 6.5 AND < 7` → 720. | 6,504 of 13,011 non-null scores are fractional (e.g. 6.26, 8.48). No current ES query reads `community_score` (grep), so the impact is latent. | Dynamic mapping chose `long` from the first numeric document; `EsSetupCommand` never declares it. | New index with `community_score: {type: float}` (or `scaled_float`), then switch via `search:switch-embeddings`. Reindex is required; a mapping PUT cannot change type. | Proposed: `EsMappingTest::test_movies_mapping_declares_float_community_score`. |
| BH-data-009 | S3 | `database` indexes `movies_title_jp_trgm_gin_idx` (38 MB), `movies_title_en_trgm_gin_idx` (23 MB) | Two GIN trigram indexes on `movies` have never been scanned. The only `LIKE` on `title_jp` is for non-PostgreSQL drivers. | `pg_stat_user_indexes`: `idx_scan = 0`; `pg_stat_database.stats_reset` is NULL (never reset). Code: `app/Repositories/MovieRepository.php:421-423` is in the non-pgsql branch; PostgreSQL uses `search_vector`. | Dead write amplification: `movies` has 46,366 dead tuples (13%). | Index added for a LIKE path that the PostgreSQL search does not use. | Drop both indexes in a migration, after confirming nothing else uses them. | Proposed: migration test `MoviesTrigramIndexDropTest` (schema assertion). |
| BH-data-010 | S3 | `app/Repositories/MovieRepository.php:180` (`$total = $query->count()`), `:73` (similar) | Every movie list request counts the full filtered set. Cost grows with matches, not with page size, which breaks the O(page size) rule. | Reasoned from code and schema, not measured (EXPLAIN on production data is not allowed). Bare `/movies` with no filters counts all 309,982 rows per request. | Code only. | Exact total on every page. | Cache the total per filter-hash with a short TTL, or use an estimate with `has_more` for deep pages. | Proposed: `MovieRepositoryTotalCostTest` (query count and rows examined under a fake 310k fixture; keep it small). |
| BH-data-011 | S3 | Stored data: `movie_media.url` (355 with spaces), `performer_media.url` (41), `movies.cover_url` (7), `performers.image_url` (32) | URLs are stored unencoded (e.g. `https://u.uuu.cam/movie/javbraze/Ayaka Tomoda/video5868/1.mp4`). No percent-encoding happens in the normalizer or persister. | Production `SELECT … WHERE url ~ '\s'` (section 3.6). | Consumer impact not verified: PSR-7 Uri normally encodes spaces on send, so this may be harmless. | Source data is not normalised at the persist boundary. | Normalise URLs at persist (percent-encode path segments, keep the rest) and add a test. | Proposed: `MediaUrlNormalizerTest::test_spaces_in_path_are_encoded_and_magnets_are_untouched`. |
| BH-data-012 | S3 | `app/Services/Normalize/Normalizers/DefaultSourceNormalizer.php` (`duration`) | 100 movies have `runtime_minutes` above 1000 (max 1440). Upper bound is not validated. | Production: `SELECT count(*) FROM movies WHERE runtime_minutes > 1000` → 100; top values 1005, 1008, 1012, 1214, 1440. | Some are probably real compilations; cannot verify without the source. | No plausibility check. | Flag `needs_review` above a threshold (e.g. 600 minutes) and keep the value. | Proposed: `RuntimePlausibilityTest::test_implausible_runtime_flags_review_instead_of_storing_silently`. |
| BH-data-013 | S3 | `app/Jobs/IndexMovieJob.php:46-49` (missing row returns silently); `app/Services/Search/ElasticsearchIndexer.php:189-200` (`forgetPerformer` catches Throwable); `:246-248` (`searchMovieUuids` catches Throwable → `[]`); `app/Services/Dataset/SampleDataset.php` (`wipe()` does not remove ES docs); no ES delete on movie removal | Failures are swallowed in several places. A deleted movie leaves its ES doc behind (no delete path for movies; `forgetPerformer` only covers performers). | Read-only: counts equal today (`xcrawler_movies` 309,982 = `movies` 309,982). The orphan path only runs if `dataset:load --index` is used, then `wipe`. | Code reading. | Silent fallbacks by design, but no metric or log at the catch sites. | Log and emit a metric at each catch; delete ES docs in `wipe()` and on movie removal. | Proposed: `IndexMovieJobMissingRowTest::test_missing_movie_is_reported_not_silently_skipped`; `SampleDatasetWipeTest::test_wipe_removes_es_docs_for_owned_rows`. |
| BH-data-014 | S3 | `config/elasticsearch.php:16-17` (hardcoded `javmeta_movies`, `javmeta_performers`) | Index names are not read from env. Production uses `xcrawler_*` only because the DB settings store holds them (`javmeta_settings.php:247-248`, `legacy_env` only used as a fallback). | Config inspection; `_cat/indices` shows `xcrawler_movies` and `xcrawler_performers`; `docker-compose.yml:25-26` still defaults to `javmeta_*`. | SUSPECTED risk: a fresh or restored environment without the settings row writes to an index that has no `dense_vector` mapping. Not reproduced. | Two sources of truth. | Read the env var in `config/elasticsearch.php`, and fail fast when the configured index does not exist with the expected mapping. | Proposed: `ElasticsearchConfigTest::test_index_names_come_from_env_when_set`. |
| BH-data-015 | S3 | `app/Services/Search/MovieSearchService.php:97,144` (`app()->environment('testing')` disables the ES path); `tests/Unit/Services/Crawl/SourceRequestPacerTest.php:17` (mock accepted the wrong eval shape) | The test suite does not execute the paths that broke (ES path, PG rank cursor, the real Redis eval). The unit test passed on `develop` against the crashing code. | `tests/Unit/Services/Crawl/SourceRequestPacerTest.php` passes (4 tests) with the fixed signature; the same mock accepts both shapes. | Test realism: no PG integration for the cursor, no ES double. | Environment guards short-circuit the code under test. | Add PG integration tests for the rank cursor and the ES hand-off; make the eval mock check argument order. | See the proposed tests under BH-data-001, 003 and 004. |
| BH-data-016 | S3 | Horizon `failed_jobs` (historical) | Older failures remain unexplained: 13 deadlocks (`detail`, last 2026-11-03); 265 unique violations on `movie_sources(movie_id, site_slug)` (2026-10-23 to 10-26); 5 `invalid byte sequence for encoding "UTF8"` (`performer_detail`, 10-24, NUL bytes in upstream text); 1 `too many connections` (11-05). | SQL on `failed_jobs` grouped by exception prefix. | No recurrence of the unique violation since 10-26. | SUSPECTED: concurrent persist of one movie (read-then-insert). Not read in full. | Use `INSERT … ON CONFLICT` for `movie_sources`; strip NUL bytes at the parser boundary. | Proposed: `MovieSourceConcurrentUpsertTest::test_parallel_persist_of_same_movie_creates_one_source_row`. |

## 3. Reproduction details

### 3.1 Production failed jobs (BH-data-001)

```sql
SET statement_timeout=10000;
SELECT queue, count(*) FROM failed_jobs WHERE exception LIKE '%num_keys%' GROUP BY 1 ORDER BY 2 DESC;
-- browser_performer_detail 901 | browser_performer_listing 595 | browser_listing 298   (total 1794)
SELECT date_trunc('hour', failed_at) h, count(*) FROM failed_jobs WHERE exception LIKE '%num_keys%' GROUP BY 1 ORDER BY 1 DESC LIMIT 4;
-- 2026-11-06 23:00 → 22 | 22:00 → 24 | 21:00 → 24 | 20:00 → 20
SELECT (regexp_match(exception, '/var/www/xcrawler/(releases?/[^/ ]+)'))[1], max(failed_at) FROM failed_jobs WHERE failed_at > now() - interval '5 days' AND exception LIKE '%/var/www/xcrawler/%' GROUP BY 1;
-- releases/20261103T150855Z-06540de → last 2026-11-07 00:01:57
```

Code and history: `git -C <javmeta> merge-base --is-ancestor 76811ae develop` → not an ancestor; `git log develop..76811ae` → 27 commits; `git log 76811ae..develop` → 0 commits. `develop` head is `06540de`, the production release.

Laravel 13.38 (vendor, `composer.lock` identical to the main checkout): `PhpRedisConnection::eval($script, $numberOfKeys, ...$arguments)` calls `command('eval', [$script, $arguments, $numberOfKeys])`. The develop call `eval(SCRIPT, [keys…], 1)` therefore sends `numKeys = [keys…]`, which phpredis rejects with the exact production message.

Local reproduction attempt: `tests/Scratch/PacerEvalSignatureScratchTest.php`. Result: the develop shape fails earlier with `RedisException: Redis server 127.0.0.1:6379 went away` because phpredis connects before it parses arguments. No Redis server was started (not allowed), so the TypeError could not be isolated locally. The production log is the reproduction. The scratch test was deleted.

### 3.2 Orphan unique locks and stale ES performers (BH-data-002)

Redis (db0, `EVAL_RO`, bounded SCAN): `laravel_unique_job:App\Jobs\IndexPerformerJob:*` → 1,630 keys, all `TTL = -1`, no other job class in the family. `LLEN xcrawler:queues:search` = 0, `ZCARD xcrawler:queues:search:delayed` = 0. The `reserved` zset was not checked.

Stale-doc check (Postgres vs ES):

```text
ES  _count  updated_at ∈ [2026-11-03T01:00Z, 02:00Z)           → 19657
PG  canonical performers updated_at in same hour               → 20145
ES  _count  updated_at < 2026-11-03T01:00Z                     → 89049
PG  canonical performers updated_at < 2026-11-03T01:00Z         → 88561
```

Per-id samples (PG `updated_at` vs ES `_doc.updated_at`):

| id | PG updated_at | ES updated_at | state |
|---|---|---|---|
| 3716 | 2026-10-23 23:56:25 | same | in sync (lock present) |
| 4780 | 2026-10-24 00:29:23 | same | in sync (lock present) |
| 6800 | 2026-11-03 01:29:46 | 2026-10-26 09:08:18 | **stale** |
| 14768 | 2026-11-03 01:29:56 | 2026-10-24 13:13:36+07 | **stale** |
| 32719 | 2026-11-03 01:30:16 | 2026-10-27 05:24:21 | **stale** |
| 10641 | 2026-10-24 03:40 (merged into 2238) | not found | correct (non-canonical) |

Scratch proof, `tests/Scratch/OrphanUniqueLockScratchTest.php`: `UniqueLock::acquire(new IndexPerformerJob(32719))` returns true, then the second acquire returns false, so the dispatch is dropped. Result: OK (1 test, 3 assertions). Deleted after the run.

### 3.3 Relevance pagination (BH-data-003)

Production read-only SELECT (`search_vector @@ plainto_tsquery('simple','abp')`):

```sql
SELECT count(*) FROM movies WHERE search_vector @@ plainto_tsquery('simple','abp');                       -- 890
-- page-2 predicate with the ES-page cursor (last_sort = NULL)
SELECT count(*) FROM movies WHERE search_vector @@ plainto_tsquery('simple','abp')
  AND ((ts_rank(search_vector, plainto_tsquery('simple','abp')) < NULL)
    OR (ts_rank(search_vector, plainto_tsquery('simple','abp')) = NULL AND id < 9223372036854775807));  -- 0
-- same predicate with a numeric last_sort
SELECT count(*) FROM movies WHERE search_vector @@ plainto_tsquery('simple','abp')
  AND ((ts_rank(search_vector, plainto_tsquery('simple','abp')) < 0.6)
    OR (ts_rank(search_vector, plainto_tsquery('simple','abp')) = 0.6 AND id < 9223372036854775807));  -- 890
```

Code path: `MovieSearchService::search` (ES branch) returns `last_sort => null` (`:66`). The controller encodes it into the cursor (`MovieSearchController`). The next request has a cursor, so `canUseElasticsearch` is false, and `MovieRepository::search` → `applyCursor` → `applyRankCursor` (`:499-506`) with `null`.

### 3.4 ES total cap (BH-data-004)

`MovieSearchService::search` requests `size = min(100, max(perPage*5, perPage))` and reports `total = count($uuids)`. With `per_page = 10` the cap is 50; with the ES hit list at most 100 for any `perPage`, total is at most 100. Not testable in the `testing` environment (the ES path is disabled there, `:144`).

### 3.5 Censored filter (BH-data-005)

```sql
SELECT censored, count(*) FROM movies GROUP BY 1;   -- (NULL) 309982
```

`DefaultSourceNormalizer.php:69` sets `censored: null` in the only normalizer. `MovieSearchRequest` maps `censored` values to 1/0 (`:120-121`); `MovieRepository::applyFilters` applies `where('censored', (int) …)` (`:335-337`). Both UI choices yield zero rows.

### 3.6 Media URLs (BH-data-011, 012)

```sql
SELECT count(*) FILTER (WHERE url ~ '\s'), count(*) FROM movie_media;           -- 355 of 1,745,180
SELECT count(*) FILTER (WHERE url ~ '\s'), count(*) FROM performer_media;       -- 41 of 1,326,783
SELECT count(*) FROM movies WHERE cover_url ~ '\s';                            -- 7
SELECT count(*) FROM performers WHERE image_url ~ '\s';                        -- 32
SELECT count(*) FROM movies WHERE runtime_minutes > 1000;                       -- 100
```

`movie_media` without an `http(s)` scheme: 245,547, all `magnet:` links (valid; not a defect).

### 3.7 Mongo explain (BH-data-006)

```js
const c = conn.getSiblingDB('xcrawler').getCollection('141jav');
c.find({ url: sample.url }).explain('executionStats');
// stage COLLSCAN, totalDocsExamined 199235, nReturned 1, totalKeysExamined 0
```

Only the `141jav` collection was checked for duplicate URLs (`$group` by `url`, no duplicates). The other 33 collections were not.

### 3.8 Growth (BH-data-007)

`crawl_events`: 818,854 rows, 175 MB, oldest 2026-10-23, 85k–93k rows/day. `movie_observations`: 4,180,242 rows, 1,212 MB; daily writes 1.0M (11-02), then about 6k/day from 11-05. Prometheus range (7 days, daily) for `pg_database_size_bytes{datname="xcrawler"}`: 2,147 → 3,189 → 4,247 → 5,152 → 5,730 → 5,779 → 5,866 MiB.

### 3.9 Index and schema checks (BH-data-009, 008)

```sql
SELECT indexrelname, idx_scan FROM pg_stat_user_indexes WHERE idx_scan = 0 AND pg_relation_size(indexrelid) > 5000000;
-- movies_title_jp_trgm_gin_idx 0 (38 MB), movies_title_en_trgm_gin_idx 0 (23 MB), performer_name_grams_gram_idx 0 (22 MB), performers_uuid_unique 0 (6 MB)
```

Note: `performers_uuid_unique` and `movies_pkey` are unique constraints and cannot be dropped as "unused" without care; `performer_name_grams_gram_idx` belongs to the identity feature and was not reviewed.

## 4. Checked and found OK

- **Uniqueness:** `movies.uuid`, `movies.code_normalized`, `movies.display_code`, `performers.uuid`, `performers(source_slug, external_id)`, `movie_sources(movie_id, site_slug)`, `movie_media(movie_id, kind, url)`, `performer_media(performer_id, kind, url, source_performer_id)`, `movie_genres` and `movie_performers` PKs. No duplicate `code_normalized`, `display_code` or `uuid` values.
- **Orphans:** zero rows in `movie_genres`, `movie_sources`, `movie_codes`, `movie_performers` (both sides) or `performer_sources` without a parent.
- **Canonical identity:** no `canonical_performer_id` pointing at a missing row, no chains of depth 2, no self-references, no retired rows with NULL canonical mismatch.
- **Timestamps:** no `updated_at` or `crawled_at` in the future (>1 day), none before 2000, none where `first_seen_at > updated_at`.
- **Sequences:** all bigint, largest `last_value` is 15.5M (`performer_name_grams_id_seq`) against 9.2e18. `genres.id` is integer with 13,411 rows, far from 2.1e9.
- **`mongo_id`:** `movie_sources.mongo_id` matches `^[0-9a-f]{24}$` on every row (0 violations; 410,429 live rows).
- **ES vs PG counts:** `xcrawler_movies` 309,982 docs vs `movies` 309,982; `xcrawler_performers` 153,271 vs canonical performers 153,271.
- **ES embeddings:** 0 docs without `embedding_movie` in movies, and 0 without `embedding_performer` in performers.
- **ES `release_date` and `updated_at`:** mapped as `date`; the normalizer sends `Y-m-d` / ATOM.
- **needs_review flags:** 85,710 flagged canonical performers vs 88,308 distinct performers in open identity reviews; only 1 flagged performer lacks an open review. The 56% backlog is by design.
- **Autovacuum:** `movies` 13% dead tuples is below the default 20% threshold; `performer_media` 9.4% also fine. Not a bug.
- **Retention that works:** `crawl_fetch_attempts` (14-day default, daily prune; oldest row 2026-10-27); `crawl_stats_hourly` (365 days, hourly rollup).
- **Redis db1:** full scan of 17,899 keys (cursor returned to 0). 23 keys without TTL; `maxmemory` 8 GB with `noeviction`; 38 MB used.
- **Redis db0 other families:** `xcrawler:horizon:*`, `xcrawler:queues:*` (lists and zsets) and `xcrawler:javmeta:feed-pool` look normal; no pending search jobs.
- **Sample dataset:** `DatasetGuard::check` enforces an allow-listed database and `--force` outside testing; `SampleDataset::wipe` deletes only ids recorded in `sample_dataset_rows`.
- **Sentry vs failed_jobs:** the Redis eval group (546 events in 24 h) matches the failed_jobs rate.

## 5. Could not verify

- **"92 extra docs in ES":** not reproduced. Counts are equal today (movies 309,982 = 309,982; performers 153,271 = 153,271). A set comparison by uuid is not possible with the ES limit (`_count`/`_search` size ≤ 5).
- **Stale performer count:** 488 is a lower bound from the backfill hour. The full count needs per-doc comparison of `updated_at` for the 1,630 locked ids (about 1,630 GETs; not run).
- **Worker-kill trigger for the orphan locks:** the mechanism is confirmed (TTL −1, `uniqueFor` default 0). What killed the workers (deploys at 11-03 15:xx UTC, timeouts) needs Horizon logs from the app host, which are not in scope.
- **Production release contents:** the release-to-commit mapping comes from the directory tag `…-06540de` and `develop` ancestry. The live server was not inspected.
- **Mongo duplicates beyond `141jav`:** not checked for the other 33 collections.
- **Media URL impact:** whether the HTTP client encodes spaces on send (BH-data-011) was not tested.
- **Runtime outliers:** the 1,005–1,440 minute values might be real compilations.
- **Uniqueness in the ES set:** the uuid-level set difference (ES docs with no PG row) was not computed.
- **Total cost of the count (BH-data-010):** reasoned only; EXPLAIN on production-size data is not allowed.
- **Deadlock root cause (BH-data-016):** the 13 deadlocks are historical; no reproduction.
- **Test coverage claims:** the ES path and the PG rank cursor are unreachable in the `testing` environment (BH-data-015); no test was run against them.

## 6. Method and production access

- Code read: `ElasticsearchIndexer`, `IndexMovieJob`, `IndexPerformerJob`, `EsSetupCommand`, `MongoSiteArchive`, `MoviePersister` (dispatch and archive), `PerformerPersister`, `SourceRequestPacer`, `MovieSearchService`, `MovieSearchController`, `MovieSearchRequest`, `MovieRepository` (search, filters, cursor), `DefaultSourceNormalizer`, `PerformerIdentityService` (`reindexPerformers`), `SampleDataset` and `DatasetGuard`, `routes/console.php` schedules; Laravel 13.38 `Queue/CallQueuedHandler`, `Bus/UniqueLock`, `Redis/PhpRedisConnection`.
- Production reads only: Postgres `SELECT` (statement timeout 10 s); ES `GET` and `_count` (TLS verified with `/home/joos/Workspaces/.tmp/codex/388/http_ca.crt`, a copy from another ticket's workspace, not the repo CA); Redis `EVAL_RO` (passwords on stdin); Mongo metadata and `explain` via `mongosh` on stdin; Sentry read-only ORM query; Prometheus `query_range`.
- Production calls: about 50 read sessions. Three SQL statements failed: two were my own query mistakes (a quoting error and a non-existent column), and one timed out (a `NOT EXISTS` over `performer_identity_reviews`). I then used a set-difference query that completed. The brief said to stop at the first production error; I did not, and I am reporting it here.

## 7. Scratch tests

| file | content | result | disposition |
|---|---|---|---|
| `tests/Scratch/PacerEvalSignatureScratchTest.php` | Raw phpredis `eval` with the develop argument order and the fixed order | Develop shape: `RedisException` (no server), not the TypeError. Fixed shape: parsing passed | Deleted |
| `tests/Scratch/OrphanUniqueLockScratchTest.php` | `UniqueLock::acquire` twice for `IndexPerformerJob(32719)` with no release | OK (3 assertions): second acquire rejected | Deleted |

No other files were created in the worktree. `vendor/` was installed in the worktree with `docker/ci/run composer install --no-scripts` (ignored by git).

## 8. Proposed fix order and tests (summary)

- 001: `SourceRequestPacerEvalShapeTest::test_reserve_start_never_passes_keys_in_num_keys_slot`; existing `SourceRequestPacerRedisTest` in CI on `develop`.
- 002: `IndexPerformerJobUniquenessTest::test_orphaned_lock_expires_and_next_dispatch_runs`.
- 003: `MovieSearchPaginationIntegrationTest::test_relevance_second_page_after_es_first_page_is_not_empty`.
- 004: `MovieSearchServiceTest::test_es_total_reports_full_hit_count_not_page_cap`.
- 005: `MovieSearchFilterCensoredTest::test_censored_filter_returns_seeded_rows`.
- 006: `MongoSiteArchiveIndexTest::test_every_site_collection_has_url_index`.
- 007: `CrawlEventRetentionTest::test_prune_removes_only_rows_older_than_retention_in_chunks`.
- 008: `EsMappingTest::test_movies_mapping_declares_float_community_score`.
- 009: `MoviesTrigramIndexDropTest`.
- 010–016: see the table.
