# Search quality and speed investigation (JOOXCR-413)

Date of measurement: 2026-11-07. Branch: `JOOXCR-413-search-investigation`.
Scope: report only. No source file was changed. Production was probed with the
limited read-only budget (23 requests in total, all HTTP 200, no 5xx observed).

## 1. Summary

1. **The time budget does not bound the database.** `SearchBudget` (default 300 ms)
   only stops optional HTTP calls. Every Postgres statement in the keyword path runs
   with no timeout. A single measured predicate costs 328 ms.
2. **Semantic search is usually skipped in production.** The keyword step consumes the
   budget first, so `HybridSearchService` never reads the vectors. This explains the
   zero results for the Vietnamese query. It is inferred from code and timings; there
   are no request logs to confirm it (see §7).
3. **Semantic hits are post-filtered on a tiny pool.** When filters are present the
   nearest 9 to 36 semantic hits are filtered in PHP, not the whole kNN result. Most
   filtered semantic queries therefore return nothing from the vector side.
4. **The rules read filters from substrings.** `sweet` (one movie), `test` (inside
   "latest"), `KAM` (inside "Mikami") are catalog genres, and the substring match
   turns them into filters. The maker `S1` is never matched because catalog names
   shorter than 3 characters are ignored.
5. **Cross-language queries work only through the genre fallback.** For the English
   and Japanese translations of the Vietnamese question, the result comes from the
   relaxed "filters only" step. The meaning is dropped. Vietnamese has no genre
   aliases, so it returns nothing.
6. **The e5 query/passage prefixes are correct end to end.** The server-side mapping
   has no test, so it is easy to break unnoticed.
7. **"Empty titles" are mostly by design.** About 75% of movies have an empty
   `title_en`; `title_jp` is always present. The API returns `""` instead of falling
   back to the Japanese title.
8. **The `FFJAV-DERIVED-*` codes are a deliberate fallback** (docs/03-operations/queue.md:381),
   but 5,480 of them were all created on 2026-11-01 to 2026-11-06 and they are indexed
   and returned by search with raw keys.
9. **The 504 is not explained by any single step.** The measured SQL for the 504 query
   sums to a few seconds at most. The likely cause is queueing (12 PHP-FPM workers, no
   request timeout) plus database contention. The proxy timeout that produced exactly
   50 s is not in this repository. See §4.1.

## 2. Method and limits

- **Production public endpoint** (`GET /ui-api/search`, browser User-Agent, `per_page=3`).
  Nine requests: the nurse query twice, the Vietnamese query, `sweet idol…`, the
  Japanese query, `sweet idol…` with `understand=0`, a 400 baseline (`q=a`), and the
  English and Japanese translations of the Vietnamese question. The 504 query was
  **not** re-sent (stop rule and it is already known).
- **Elasticsearch** (read-only, admin credentials read inside a script, never printed):
  cluster health, `_cat/indices`, two mappings, two `_count`s, node stats, one vector
  read, three profiled kNN queries (`size=5`), a `_count` on `DERIVED`, and one search
  on `title_en`. Fourteen requests.
- **Postgres** (`ssh joos@192.168.10.12`, `sudo -u postgres psql`, read-only session
  with `default_transaction_read_only = on`, `statement_timeout` 20–120 s). Only bounded
  `count` subqueries (`LIMIT 500000`), `EXPLAIN` without `ANALYZE`, catalog dumps
  (genres, makers, series, performer romaji names, facets) and `LIMIT`ed samples.
- **Prometheus** (read-only, `192.168.10.10:9090`): about 15 instant queries.
- **Embedder**: not reachable. In production it binds to `127.0.0.1:8000` on the node
  (`deploy/production/node/xcrawler-embedder.service`, `compose.production.yml:74`).
  I did not install the model locally.
- **Interpreter harness** (unit level): the real `app/Services/Search/QueryInterpreter.php`
  loaded in PHP 8.5 with stubbed `Cache`, `config`, Ollama and repository classes. The
  repository reads the production catalog snapshot (13,413 genre labels, 1,614 makers,
  5,300 series, 153,272 performer romaji names). Ollama was **not** called.
  Scratch files live in `/home/joos/Workspaces/.tmp/codex/413/` and are not committed.

Latencies are single samples per query, not percentiles.

## 3. Request path and bounds

| Step | Location | Bound today |
| --- | --- | --- |
| Validation (`q` 2–300 chars, `per_page` 1–50) | `app/Http/Controllers/SearchController.php:21-24` | none needed |
| Result cache, 60 s | `SearchController.php:29` | same key within 60 s; see §4.1 (no visible gain) |
| Budget wrapper, default 300 ms | `HybridSearchService.php:36`, `SearchBudget.php:29`, `config/javmeta_search.php:5` | cooperative only: checked before each optional HTTP call; never interrupts a query |
| Budget description and range | `config/javmeta_settings.php:74` | UI range 25–1500 ms; the text says it "does not limit database queries" |
| Rules interpreter | `QueryInterpreter.php:116-206` | CPU only; catalog lookups cached 120 s (`:402`, `:408`) |
| Catalog lists on a cache miss | `QueryInterpreterServiceRepository.php:99-110` | `catalogNames('genres')` loads all 13k labels; makers and series are `DISTINCT` over movies |
| Ollama chat (only if `nl_enabled` and the question has ≥3 words) | `QueryInterpreter.php:74-92`, `OllamaClient.php:90-101` | `min(2 s, remaining budget)`; production telemetry shows `not_attempted` |
| Keyword movies: up to 3 attempts, each a search plus a count | `HybridSearchService.php:96-118` | **not bounded** (Postgres) |
| Performer keyword search | `HybridSearchService.php:51`, `PerformerRepository.php:73-110` | **not bounded** |
| Dependency checks (`isAvailable`) | `HybridSearchService.php:57-58`, `DependencyMonitor.php:38-80` | cached; a stale check makes a 2 s HTTP probe (`EmbedderProbe.php:29-34`) outside the budget |
| Embedder call | `EmbedderClient.php:36-41` | `min(15 s, remaining budget)`, so effectively under 300 ms |
| Movie and performer kNN | `ElasticsearchIndexer.php:267-295`, `ElasticsearchHttpClient.php:134-140` | HTTP timeout = remaining budget; `k` = `min(100, 5×pool)`, `num_candidates` = `max(10k, 100)` |
| Semantic hydration from Postgres | `MovieSearchService.php:93-134`, `PerformerSearchService.php:28-64` | **not bounded**; skipped when the budget is expired (`HybridSearchService.php:62`, `:69`) |
| Totals (`count`, repeats the text filter) | `HybridSearchService.php:71-75`, `:83` | **not bounded** |

## 4. Findings

### 4.1 Why the 504 after 50 s?

**Facts from the code**

- The budget stops optional HTTP work only. `SearchBudget.php:19-45` is a monotonic
  deadline checked by `expired()` and `seconds()`. No Postgres statement reads it.
- `HybridSearchService.php:55` skips vectors when the budget is spent. The keyword
  step runs first and has no limit, so a slow keyword step turns off the semantic step
  as well.
- `MovieRepository::applyTextSearch` (`MovieRepository.php:411-431`) joins five
  unanchored `LIKE '%…%'` lookups (performers, aliases, performer sources) and runs
  again inside `count()`.

**Measured Postgres timings** (psql `\timing`, read-only, one execution each unless noted)

| Predicate | Time | Note |
| --- | --- | --- |
| `performer_sources` with three ORed `ILIKE` (Vietnamese phrase, no match) | **328 ms** | planner chooses `Seq Scan` over 155k rows (`EXPLAIN`, no execution) |
| `performers.name_romaji ILIKE` (same phrase) | 8 ms | |
| `performer_aliases.alias ILIKE` (same phrase) | 2 ms | |
| `movies.search_vector @@ plainto_tsquery` (Vietnamese) | 4 ms | |
| 504 query: performer match for `%big tits%` (all three sources) | 14 ms cold, 4.5 ms warm | |
| 504 query: genre `Mature Woman` join (39,196 rows) | 27 ms | |
| 504 query: tsvector `mature woman big tits 2022` | 4 ms | |

Summing the statements of one search (3 keyword attempts × search and count, performer
search, totals) gives roughly 1–3 s in the worst case. That does not reach 50 s.

**Other measured components**

- Elasticsearch kNN: 206 ms cold (first run), 20 ms warm; the cluster was idle at
  sampling (CPU 0%, heap 6%, 1.0 GB of 15.7 GB). The 7-day heap peak was about 9.6 GiB
  (`elasticsearch_jvm_memory_used_bytes`), so memory pressure is possible, but I have
  no sample from the 504 window.
- Embedder and Ollama: bounded by the budget, so they cannot account for 50 s.

**Latency seen from this client** (cold, one sample each): a 400 validation error with no
database work takes 0.74 s, so about 0.7 s is fixed overhead. Search adds about 0.4–1.4 s:
nurse uniform 1.28 s; Vietnamese 1.11 s; `sweet idol…` 1.33 s; `ハイビジョン 人妻` 2.13 s;
`sweet idol…` with `understand=0` 0.97 s; English translation 1.75 s; Japanese translation
1.23 s. Repeating the nurse query inside the 60 s cache window took 1.42 s, so the cache
gave no visible gain in this sample.

**Most likely causes (not proven):**

1. **Worker queueing.** `deploy/production/node/xcrawler-fpm.conf:9` sets
   `pm.max_children = 12`. A slow search holds a worker. `xcrawler.nginx.conf` has no
   `fastcgi_read_timeout` and FPM has no `request_terminate_timeout`, so slow requests
   pile up and the proxy gives up.
2. **Database contention.** Prometheus shows active `xcrawler` transactions up to 286 s
   and up to 16 active sessions in the last 7 days (`pg_stat_activity_max_tx_duration`,
   `pg_stat_activity_count`). A read that waits on a crawl write would show up as a long
   search.
3. **A gateway timeout near 50 s.** `deploy/production/haproxy/xcrawler.cfg` has no
   timeout lines in the snippet; the global and defaults sections are not in this repo.

**Root cause:** the deadline does not cover the database, so one slow search has no
ceiling. The 504 is the proxy giving up. I could not prove which of the three causes
produced it. The fix is the same for all three: a real deadline with statement timeouts
(§6, P0-1) and explicit FPM and nginx timeouts (§6, P2-13).

**Cold vs warm:** Embedder cold start is not reached in most requests because the
budget is spent first. When it is reached, the first call loads the model lazily
(`embedder/app.py:13-17`, `:49`). Whether deploy runs a warm-up call is not verified.
Elasticsearch kNN warms within one call (206 → 20 ms).

### 4.2 Why 0 results for the Vietnamese query?

Trace (`người phụ nữ trung niên mặc đồng phục y tá`):

1. Rules: no filter; the whole sentence becomes the keyword (confirmed in production
   telemetry and in the harness).
2. Keyword: `MovieSearchService::search` tries Elasticsearch `multi_match` first
   (`MovieSearchService.php:35-42`), gets no hits, then falls back to Postgres.
   `applyTextSearch` uses `plainto_tsquery('simple', <sentence>)`, which ANDs every word,
   and a performer `LIKE` (§4.1 timings). Zero hits.
3. Vectors: `semanticCatalogUuids` runs only when the budget is not expired
   (`HybridSearchService.php:55`). Step 2 already spends about 330 ms on one predicate, so
   the budget is gone: no embedding, no kNN, no semantic search (`:62`, `:69`).
4. `fuse([[], []])` is empty, and the response is 0 results.

Conclusion: the empty result is **not** a ranking or threshold problem. The kNN has no
minimum score (`ElasticsearchIndexer.php:276-281`, `:318-325`), so once the semantic
branch runs it always returns its top-k. Zero can only come from the branch not running,
the embedder returning null, or the Postgres hydration missing the hits. The first is the
one the code and timings point to.

**Prefix check (passed).** `EmbedderClient.php:43` sends `javmeta-query` for queries and
`javmeta-passage` for indexing. The embedder maps `query` in the model name to `"query: "`
and everything else to `"passage: "` (`embedder/app.py:38-39`). Indexing uses the default
passage (`ElasticsearchIndexer.php:81`, `:173`). Searching uses query
(`:270`, `:312`). The client model names are tested
(`tests/Unit/Services/Embedding/EmbedderClientTest.php:49,65`). **The server-side mapping
is not tested anywhere**, which is a regression risk.

**Cross-language comparison (production, one sample each):**

- English translation `middle-aged woman in a nurse uniform`: genre `Uniform` and
  keyword `middle-aged woman in a nurse`. Relaxed to "filters only"; three Caribbeancom
  titles returned. The results come from the genre.
- Japanese translation `中年の女性 ナース服`: genre `ナース` and keyword `中年の女性 服`.
  Relaxed to "filters only"; three titles returned. The match comes from the genre
  `ナース`; the keyword `中年の女性` (middle-aged woman) matches nothing.
- Vietnamese has no genre alias, so no filter is found and the query returns nothing.

**Second root cause: post-filter on a tiny pool.** `HybridSearchService.php:62-65` filters
the semantic hits with `passes()` after `semanticSearch(..., $pool)` has cut them to
`$pool` items (`MovieSearchService.php:106`). `$pool` is 9 for `per_page=3` and 36 for
`per_page=12`. The kNN query has no filter clause (`ElasticsearchIndexer.php:276-281`).
A question with any filter (year, genre, maker) therefore takes the nearest 9 to 36
movies and keeps only those that match. The semantic side contributes almost nothing.

Quality of the multilingual model on Vietnamese and Japanese is **not measured** (no
embedding was possible from here, §7).

### 4.3 Interpretation quality (12 test queries + translations)

Output of `QueryInterpreter::interpretWithTelemetry` (rules only, catalog snapshot of
2026-11-07). "Verdict" is my judgment against the intent of each question.

| # | Question | Keywords | Filters | Verdict |
| --- | --- | --- | --- | --- |
| 1 | mature woman with big tits from 2022 | `2022` | genre `Mature Woman`; actress `Big Tits` | **wrong**: "from 2022" is not read as a year (`releaseDateFilters`, `:226-249`, has no "from"); `big tits` is a real genre (76,520 movies) but only one genre per question is allowed (`:186-195`), so the phrase falls through to the performer `Big Tits` (`performerIn`, `:197-201`) |
| 2 | sweet idol first time on camera | `idol first time on camera` | genre `sweet` | **wrong**: the label exists but on one movie; it is a catalog junk label, and the substring match accepts it |
| 3 | người phụ nữ trung niên mặc đồng phục y tá | the whole sentence | none | **missed**: no alias for `đồng phục` (uniform) or `y tá` (nurse); the genre `Nurse` exists (`genres.txt`) |
| 4 | ハイビジョン 人妻 | `人妻` | genre `ハイビジョン` (29,735 movies) | correct |
| 5 | nurse uniform | `nurse` | genre `Uniform` (7,293) | correct |
| 6 | phim của Yua Mikami năm 2019 | `Yua Mi i` | released 2019; genre `KAM` | **wrong**: `KAM` (134 movies) matches inside "Mikami" (`mb_stripos`, `:188`), breaking the name before `performerIn` runs (genre is checked first, `:186` then `:197`); the year is right |
| 7 | latest movies from S1 | `la S1` | genre `test` | **wrong**: `test` (1 movie) matches inside "latest"; the maker `S1` is never tried because names under 3 characters are skipped (`:189`) |
| 8 | creampie amateur 4 hours | `amateur 4 hours` | genre `Creampie` (104,491) | partly: `Amateur` (60,000) is also a genre but only one genre applies |
| 9 | vợ hàng xóm | the whole sentence | none | **missed**: no alias for the Vietnamese phrase |
| 10 | office lady threesome | `threesome` | genre `Office Lady` (883) | partly: `Threesome` (4,101) is dropped |
| 11 | new releases this month | the whole sentence | none | **missed**: "this month" is not turned into a date range |
| 12 | something relaxing with a beautiful girl | `something relaxing a` | genre `Beautiful Girl` (28,870) | partly: stray `a` stays in keywords |

**Validation.** The rules path has no validation. `knownMovieValue` for genres is an
exact, case-insensitive match against the catalog (`QueryInterpreterServiceRepository.php:31-52`).
It proves the label exists, not that it is meaningful. `sweet` and `test` pass for that
reason. The Ollama path validates more (`QueryInterpreter.php:268-349`: `mentioned()` and
the `known*()` lookups), but it is not reached in production.

**Ollama.** Production telemetry shows `ollama_chat_outcome: not_attempted`. The chat
call gets `min(2 s, remaining budget)` (`OllamaClient.php:97-101`). With the default
300 ms budget the model has at most about 300 ms, which a 7B model cannot meet on a CPU
(estimate, §5). In practice the Ollama path cannot succeed with the current budget. When
Ollama is unreachable the cost is a connection failure or the timeout above, and the
rules answer.

### 4.4 Empty titles and `DERIVED` codes

**Empty titles** (`both_titles_empty = 0`, `title_en_empty = 230,739` of about 309k,
`title_jp_empty = 55,969`):

- The data is correct: every movie has a Japanese or English title. About 75% have no
  English title, which is normal for Japanese-only releases.
- `MovieSummaryResource.php:30-31` and `:55-56` return the raw `title_en`, so the API
  sends `""`. The UI falls back to the Japanese title (per `docs/ui/README.md:204`), but
  API clients see an empty title. Fix at the resource.

**`FFJAV-DERIVED-*` codes:**

- Source: `SiteCodeCanonicalizer.php:43`. When no product code is found for
  `javbtc`, `tokyohot`, `ffjav` or `141jav`, the code becomes `<SLUG>-DERIVED-<SHA256 of the
  detail path>`. This is documented as intended (`docs/03-operations/queue.md:381-383`).
- Count: 5,480 movies (JAVBTC 2,678; TOKYOHOT 1,255; FFJAV 1,071; 141JAV 476). All of
  them were first seen between 2026-11-01 and 2026-11-06 (3,815 on 11-01 alone). This is
  a new burst from a recent change, not legacy data.
- They are indexed (`xcrawler_movies` `_count` on `*DERIVED*`: 5,480) and returned by
  search with the raw key. The UI short label (`docs/ui/README.md:204`) is not applied
  in the API.
- Some of them are recoverable. Example: `1Pondo 110526_001` (in the title) yields a
  derived code because the FFJAV pattern did not match. That is a crawler extraction
  issue, tracked as JOOXCR-282/283.

Whether derived movies should appear in search at all is a product decision (§6, P2-10).

### 4.5 Index and data quality

**What is embedded** (`ElasticsearchIndexer.php:73-80` for movies, `:159-170` for performers):

- Movie: `title_en`, `title_jp`, genre labels (`label_normalized`), maker, series,
  description. **Not** the performer names, not the code, not an English genre label
  set. Performer names are indexed for keyword search only (`performer_names`).
- Performer: romaji, kanji, kana, source names, aliases, bio, birth and debut dates,
  location, cup, blood type.
- Genre labels in several languages are embedded together, because `genres` is the
  `label_normalized` list. Multiple labels for one concept are kept apart:
  `Creampie`/`creampie` (104,491 movies) and `中出し` (27,424) are distinct normalized
  labels; `HD`/`Hd`/`hd` and `ハイビジョン` are also separate. The genre unification work
  (JOOXCR-411 branch) is the place to fix this.

**Coverage** (Postgres, bounded counts): descriptions are present for 228,979 movies
(about 74%); 6,197 movies have no genre link (2%); bios are present for 36,534 of
153,271 canonical performers (about 24%).

**Elasticsearch**: `xcrawler_movies` has 309,979 documents, all with `embedding_movie`
(384 dims, cosine, `bbq_hnsw`). `xcrawler_performers` has 153,268, all with
`embedding_performer`. The cluster also holds the legacy `jvmeta_movies` (4,506) and
`jvmeta_performers` (10,407). There is no `javmeta_*` index, although
`config/elasticsearch.php:16` defaults to `javmeta_movies`. Production must override this
through the settings row `search.movies_index` (`config/javmeta_settings.php:247`); I did
not read that row, but the public results match `xcrawler_movies`.

**Natural-language recall gap.** Keyword search uses `plainto_tsquery`, which ANDs all
words (`MovieRepository.php:420`). A natural-language question with extra words
(`middle-aged woman in a nurse`) matches nothing unless the title contains every word.
That is why the translated questions depend on the genre fallback.

## 5. LLM interpretation: cost and latency

Nothing in this section was measured. Ollama and the workstation are unreachable from
here. The numbers are estimates for CPU inference.

**Option A (recommended now): rules plus embeddings, no LLM in the request path.** This
keeps the 8 s deadline and has no extra host.

**Option B: a small local model for cache-miss enrichment, off the request path.** Run it
as a queued job that refines the interpretation of a question and stores the result for
7 days (keyed by normalized question). The request uses the rules result and the job
updates later. Estimates for 16 vCPU, no GPU:

- `qwen2.5:7b` (Q4): about 4–8 tokens/s; a 60-token JSON answer takes 10–20 s. Too slow
  for any synchronous path.
- `qwen2.5:1.5b`: about 15–25 tokens/s; 3–5 s per answer.
- `qwen2.5:0.5b`: about 1–2 s per answer, with lower accuracy.

The 24/7 Dev Workspace VM (192.168.10.100, 16 vCPU, 62 GB RAM, no GPU) is a development
machine with no production SLA. I would not make production depend on it. If an LLM is
needed in production, it needs capacity planned on a production host.

**Option C: keep the workstation Ollama for ad hoc tuning only.** This is what
`nl_enabled` already does, and production never uses it.

## 6. Recommendations: task specs (prioritized)

Each spec names the files, the change, the tests to add (they do not exist yet unless
stated), and the acceptance criteria. Tests are named for the behaviour they check.

### P0-1 Hard deadline with graceful degradation (JOOXCR-413-01)

- **Files:** `app/Services/Search/SearchBudget.php`, `app/Services/Search/HybridSearchService.php`,
  `config/javmeta_search.php`, `config/javmeta_settings.php` (line 74: raise the UI
  maximum from 1500 to 10000), `app/Http/Controllers/SearchController.php`.
- **Change:**
  1. Default the budget to 8000 ms.
  2. Wrap each search's Postgres work in a transaction with
     `SET LOCAL statement_timeout = min(remaining, 2000)` ms.
  3. Start the embedding (`semanticCatalogUuids`) before the keyword step, with its own cap
     of 1500 ms, so keyword work cannot starve it. kNN gets 2000 ms.
  4. When the budget ends, return what is ready and set
     `interpretation.degraded` to a list such as `["semantic_timeout"]`.
- **Tests:** `tests/Feature/Search/HybridSearchServiceTest.php`:
  `test_semantic_results_survive_a_slow_keyword_step`,
  `test_keyword_results_return_when_the_embedder_times_out`;
  `tests/Unit/Services/Search/SearchBudgetTest.php`:
  `test_statement_timeout_never_exceeds_the_remaining_budget`.
- **Acceptance:** production p95 under 8 s; no 504 from `/ui-api/search` over 7 days;
  `degraded` is set whenever a stage is cut; the Vietnamese query returns semantic hits
  when the embedder is up.

### P0-2 Filter the kNN, do not post-filter the top 9 (JOOXCR-413-02)

- **Files:** `app/Services/Search/ElasticsearchIndexer.php` (`semanticCatalogUuids`,
  `semanticUuids`), `app/Services/Search/MovieSearchService.php` (`semanticSearch`),
  `app/Services/Search/HybridSearchService.php:62-65`.
- **Change:** build an ES `knn.filter` from the same filters `passes()` checks:
  `release_date` range, `runtime_minutes`, `code_normalized` term, `maker.keyword`,
  `series.keyword`, `label.keyword`, `genres.keyword`. Remove the post-filter once the
  pre-filter is in place.
- **Tests:** `tests/Unit/Services/Search/ElasticsearchIndexerTest.php`:
  `test_knn_request_carries_the_release_date_range`;
  `tests/Feature/Search/HybridSearchServiceTest.php`:
  `test_filtered_semantic_search_finds_matches_beyond_the_first_nine_candidates`.
- **Acceptance:** the eval set (P2-11) shows semantic contributions for filtered questions.

### P0-3 Stop wrong filters in the rules (JOOXCR-413-03)

- **Files:** `app/Services/Search/QueryInterpreter.php` (`rules()`, `:116-206`, lines
  `:186-201`), `app/Repositories/QueryInterpreterServiceRepository.php` (`:31-52`,
  `:99-110`).
- **Change:**
  1. Match Latin catalog names on token boundaries
     (`(?<![\p{L}\p{N}])name(?![\p{L}\p{N}])`). This fixes `KAM`/`Mikami` and `test`/`latest`.
  2. Allow 2-character names only when the catalog value has at least 20 movies; this
     admits `S1`.
  3. Check performer names before genre labels for multi-word spans, or drop performer
     candidates that equal a genre label (`Big Tits`).
  4. A genre becomes a filter from rules only when it has at least 20 movies; `sweet`
     (1) and `test` (1) never filter.
  5. Allow more than one genre per question; keep the rest as keywords.
  6. Read "from YYYY" as `released_from` and "this month" as the current month range.
- **Tests:** `tests/Feature/Search/QueryInterpreterRulesTest.php` data provider, new cases:
  `latest movies from S1` (no genre, maker `S1`; fails today),
  `phim của Yua Mikami năm 2019` (actress `Yua Mikami`, 2019; fails today),
  `sweet idol first time on camera` (no genre; fails today),
  `mature woman with big tits from 2022` (2022 range; fails today),
  `new releases this month` (current month range; fails today).
- **Acceptance:** the 12-question table in §4.3 gives the expected verdicts; the opt-in NL
  suite (`tests/Nl/NaturalLanguageEvaluationTest.php`) shows no regression.

### P1-4 Keyword matching for natural-language questions (JOOXCR-413-04)

- **Files:** `app/Repositories/MovieRepository.php` (`applyTextSearch`, `:411-431`).
- **Change:** for questions with more than two content words, match any content word
  (OR of tokens with a stopword list for en, vi and ja), not `plainto_tsquery` (AND).
- **Tests:** `tests/Feature/Search/MovieTextSearchTest.php`:
  `test_multi_word_question_matches_movies_with_some_of_the_words`.
- **Acceptance:** the English translation returns results without the genre filter.

### P1-5 Genre aliases for Vietnamese and Japanese (JOOXCR-413-05)

- **Files:** a new migration for a `genre_aliases` table (`alias`, `lang`, `genre_id`),
  `app/Services/Search/QueryInterpreter.php` (look up aliases before keywords).
- **Change:** seed from the genre unification (JOOXCR-411) and a curated Vietnamese list
  (`đồng phục` → Uniform, `y tá` → Nurse, and the others from the eval set). **Needs owner
  review of the list.**
- **Tests:** `tests/Feature/Search/QueryInterpreterRulesTest.php`:
  `test_vietnamese_uniform_word_maps_to_the_uniform_genre`.
- **Acceptance:** the Vietnamese nurse query gives a genre filter and at least one hit.

### P1-6 Richer embedding text and a safe reindex (JOOXCR-413-06)

- **Files:** `app/Services/Search/ElasticsearchIndexer.php` (`indexMovie` `:73-80`,
  `indexPerformer` `:159-170`), `search:switch-embeddings` command.
- **Change:** add performer names, the English genre labels only (one per concept, not
  every language), and cleaned description text. Roll out with the existing
  `search:switch-embeddings` flow so the old index stays available.
- **Tests:** `tests/Unit/Services/Search/ElasticsearchIndexerTest.php`:
  `test_movie_embedding_text_includes_performer_names_and_english_genres`.
- **Acceptance:** recall@10 on the eval set (P2-11) improves against the baseline, and the
  swap can be reverted.

### P1-7 Lock the e5 prefix mapping (JOOXCR-413-07)

- **Files:** a new test under `embedder/` (for example `embedder/tests/test_prefix.py`)
  and the existing `tests/Unit/Services/Embedding/EmbedderClientTest.php`.
- **Change:** test `_prefix` for `javmeta-query` (`query: `) and `javmeta-passage`
  (`passage: `). No behaviour change; the mapping is already correct.
- **Acceptance:** the test fails if the mapping is swapped.

### P1-8 Do not embed twice on failure (JOOXCR-413-08)

- **Files:** `app/Services/Search/MovieSearchService.php:104`.
- **Change:** when `$prefetchedUuids` is null because the vector step failed, the code
  embeds again. Pass an explicit "already tried" marker so the second embedding is skipped.
- **Tests:** `tests/Unit/Services/Search/MovieSearchServiceTest.php`:
  `test_failed_vector_lookup_is_not_retried`.

### P1-9 Keep the LLM off the request path (JOOXCR-413-09)

- **Files:** `app/Services/Search/QueryInterpreter.php:74-92`,
  `app/Services/Embedding/OllamaClient.php:90-101`.
- **Change:** remove the synchronous Ollama call from `search`. Keep the telemetry. Option B
  (§5) can run later as a queued job that writes to a 7-day cache.
- **Tests:** `tests/Feature/Search/SearchTest.php`:
  `test_search_does_not_call_ollama_synchronously` (`Http::fake` and assert nothing was sent).

### P2-10 Titles and `DERIVED` codes (JOOXCR-413-10)

- **Files:** `app/Http/Resources/MovieSummaryResource.php:30-31`, `:55-56`;
  `app/Services/Search/ElasticsearchIndexer.php` (`searchMovieUuids`,
  `semanticUuids`) if derived movies are to be excluded.
- **Change:** `title_en ?: title_jp` (never `""`). Show the short label from
  `docs/ui/README.md:204` in the API. **Decision needed:** hide `*-DERIVED-*` from default
  search (add a `must_not` on `code_normalized`) or keep them with the label.
- **Tests:** `tests/Feature/Api/MovieSearchControllerTest.php`:
  `test_title_falls_back_to_the_japanese_title`,
  `test_derived_code_is_shown_as_a_short_label`.
- **Acceptance:** no raw `DERIVED` string in search responses unless the caller asks for it.
- **Out of scope here:** crawler extraction (JOOXCR-282/283) for recoverable codes.

### P2-11 Search-quality evaluation set (JOOXCR-413-11)

- **Files:** `tests/Fixtures/search-eval.json` (new) and a script or command (for example
  `app/Console/Commands/SearchEvaluateCommand.php`, or `tests/Nl/SearchRecallTest.php`).
  Reuse the 40 existing filter examples in `tests/Fixtures/nl-query-examples.json`.
- **Content:** 50 queries: 20 English, 15 Vietnamese, 15 Japanese. Each has `q`, `lang`,
  and expected `genres[]`, `performers[]`, optional `codes[]`.
- **Metric:** `recall@10 = |top10 ∩ relevant| / min(10, |relevant|)`, where a movie is
  relevant when it has every expected genre or performer (computed from Postgres for the
  QA dataset). Report per language.
- **Acceptance:** the script runs against the QA dataset in under 60 s; a baseline is
  committed; the target (for example ≥ 0.6 for English, ≥ 0.5 for Vietnamese and
  Japanese) is **proposed and needs owner agreement**.

### P2-12 Stage timings (JOOXCR-413-12)

- **Files:** `app/Services/Search/HybridSearchService.php`, `app/Http/Controllers/SearchController.php`,
  `app/Observability/MetricStreams.php` (OpenObserve, not Prometheus).
- **Change:** record per-stage milliseconds (interpret, keyword, embed, kNN, hydrate,
  totals), `budget_expired`, and `degraded`. Prometheus has no application route metrics
  today (the `http_request_duration_seconds` series belong to a speedtest job), so search
  latency cannot be read from Prometheus at all.
- **Tests:** `tests/Feature/Search/SearchTest.php`: `test_search_reports_stage_timings`.

### P2-13 Gateway and worker timeouts (JOOXCR-413-13, infrastructure)

- **Files:** `deploy/production/node/xcrawler.nginx.conf` (add `fastcgi_read_timeout` for the
  `ui-api/search` location), `deploy/production/node/xcrawler-fpm.conf` (add
  `request_terminate_timeout`; review `pm.max_children = 12` under search load), and the
  HAProxy defaults (not in this repo).
- **Acceptance:** a stalled search fails in about 10 s, not 50 s, and does not hold a worker.
  **Outward-facing ops change: confirm with the owner before rollout.**

### P2-14 Dependency probes outside the budget (JOOXCR-413-14)

- **Files:** `app/Services/Dependencies/DependencyMonitor.php:38-80`.
- **Change:** when the cached state is stale, use the last known state and refresh in the
  background, instead of a synchronous probe in the request.
- **Tests:** `tests/Unit/Services/Dependencies/DependencyMonitorTest.php` (if present):
  `test_stale_check_does_not_block_search`.

## 7. Could not verify

- **The 504 itself.** Not re-sent (stop rule). Its 50 s is not in this repo. No request log,
  no trace, and no per-route metric. Causes in §4.1 are ranked by inference.
- **That production skips the semantic branch.** Inferred from the code path and the
  measured 328 ms predicate. Confirming needs a log line for `budget.expired()` or a
  timing per stage (P2-12).
- **Embedder timing and the e5 quality for Vietnamese and Japanese.** The embedder is not
  reachable from here (bound to 127.0.0.1 on the node).
- **Ollama timing and accuracy.** The workstation is not reachable. All LLM numbers in §5
  are estimates.
- **Cache behaviour across nodes.** One sample; the cache gave no visible gain.
- **Production index setting.** I did not read the `search.movies_index` row. The public
  results match `xcrawler_movies`.
- **Interpreter results in production.** The harness used a catalog snapshot and did not
  run the database-backed `applyFilters`. Behaviour should match, but I have not run it
  against the application.
- **Latency distribution.** Every latency is a single sample.
- **Warm-up at deploy** for the embedder model. Not checked.
