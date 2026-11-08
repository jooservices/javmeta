# Bug hunt: persistence, normalisation, identity and data integrity (JOOXCR-414)

Scope: `app/Services/Persist/**`, `app/Services/Normalize/**`, `app/Services/Identity/**`, models Movie / Performer / Genre / Media, related repositories and migrations, plus read-only production data (PostgreSQL `xcrawler`, Elasticsearch `xcrawler_movies`).

Base commit: `1b667d9` (branch `JOOXCR-bughunt-persist`, from `JOOXCR-373-readiness`).
Report only: no application code or tests were changed.

## Summary

| Severity | Count |
|---|---|
| S1 (data loss / security / outage) | 1 |
| S2 (wrong results / visible defect) | 7 |
| S3 (minor / robustness / capacity) | 10 |

Verification levels: **CONFIRMED** = reproduced by a script or a production query that was actually run. **SUSPECTED** = reasoned from code, not reproduced.

## Findings

| id | sev | file:line | summary | repro | evidence | root cause | verdict |
|---|---|---|---|---|---|---|---|
| BH-persist-001 | S1 | `app/Support/Code/SiteCodeCanonicalizer.php:22-46`, `:110-135`; `app/Services/Normalize/Normalizers/DefaultSourceNormalizer.php:130-160` | Free text (title, URL) is used to mint DVD codes. Distinct works get the same code and are merged into one movie. | Repro A, B below. | Production: 195 movies carry title-minted codes (`VOL`, `IDOL`, `MIX`, `HOURS`, `PART`, `BEST`, …); **27 of them have conflicting observed `title_jp` values**. `VOL002` (movie id looked up by code) has 6 different `title_jp` observations, from ffjav `099MBST006`, `099MBST005`, `484SNO002`, `406MFO036` and 141jav `Vol02`, all `Vol.02` series entries. | `codeCandidates()` passes `$movie->title` and `$codeUrl` as code candidates. `extractDvdLike()` runs a loose `\b([A-Z]{2,12})[\s_-]*(\d{2,6})\b` over them, so "Vol 02" / "Vol02" / "Super Idol 2024" become `VOL-02` / `IDOL-2024`. For path-identity sites (141jav, ffjav, javbtc, tokyohot) the URL-derived `DERIVED` code is never reached because a title match wins first. | **CONFIRMED** |
| BH-persist-002 | S2 | `app/Support/Code/SiteCodeCanonicalizer.php:110-135` | Result depends on punctuation in the title. The same ffjav title yields the correct code with `Vol.02` and a wrong code with `Vol 02`. | Repro C below. | Output: `"099MBST006 … Vol 02"` → `VOL-02` (wrong); `"099MBST006 … Vol.02"` → `099MBST-006` (correct). | Regex precedence: the free-text "standard" pattern runs before the leading digit-led code pattern. | **CONFIRMED** |
| BH-persist-003 | S2 | `app/Support/Code/NormalizedCode.php:26-40`; `app/Support/Code/SiteCodeCanonicalizer.php:22` | Full-width or NFKC-equivalent typed codes are rejected instead of normalised, so the movie is dropped. | Repro B below. | `resolve('javdb', ['ＳＳＩＳ－１２３'])` → `NULL`; `NormalizedCode::from('ＳＳＩＳ－１２３')` throws `Code must contain a prefix and numeric suffix`. Normalisation then raises `NormalizationFailedException`. Ideographic space `"SSIS\u{3000}123"` is accepted (`\s` with `/u` matches U+3000). | No NFKC step before the ASCII-only regex. **Impact on production not measured** (no Sentry query run). | **CONFIRMED** (logic), impact SUSPECTED |
| BH-persist-004 | S2 | `app/Services/Persist/MoviePersister.php:494`; `app/Services/Persist/GalleryPersister.php:136`; `app/Services/Identity/PerformerIdentityService.php:791` (`copyMovieLinks`) | Merged-away performer ids stay in `movie_performers`. Writes use the source row id, and merges copy links instead of moving them. | SQL below. | Production: **3,424 performers have `canonical_performer_id` set; 2,665 of them still have movie links; 110,846 `movie_performers` rows point at a merged-away id** (exact count, under a 200k LIMIT). | The crawl path upserts `$performer->id` (source row) at `MoviePersister:494` and `GalleryPersister:136`. `reconcileMovieCast` (`MoviePersister:558`) inserts the canonical id. `copyMovieLinks` inserts canonical rows and never deletes the source rows. Read paths hide this (`Movie::performers` and `MovieRepository:405` filter `canonical_performer_id IS NULL`), but raw counts and co-credit do not. `PerformerCoCreditRule::movies()` (line 103) counts `DISTINCT p.id` per movie, so a merged duplicate makes the count 2 and rejects valid co-credit evidence (false negative). | **CONFIRMED** (rows); co-credit impact SUSPECTED |
| BH-persist-005 | S2 | `app/Services/Persist/MoviePersister.php:382` | Genre labels are not width-folded, so the same genre is stored twice. | SQL below. | Production: **178 genre rows contain full-width ASCII**, with twins such as `21歳` / `２１歳`, `19歳` / `１９歳`, `50歳` / `５０歳`, `2穴` / `２穴`, `4p` / `４p`, `3p` / `３p`. | Genre keys use `mb_strtolower(trim(preg_replace('/\s+/u', …)))` only. There is no NFKC or `mb_convert_kana` step, so full-width digits and letters are kept as distinct keys. (Whitespace is fine: `\s` with `/u` does match U+3000.) | **CONFIRMED** |
| BH-persist-006 | S2 | `app/Services/Persist/GalleryPersister.php:60-67`, `:113-132`, `:71-94`, `:134-147` | Gallery labels become performers, and every photo of a gallery is attached to every named performer. Lists are unbounded per performer. | SQL below. | Production: performer **1624 "Av Idol"** (source `javphotos`, no movie link check done) has **45,014 `performer_media` gallery rows from 1,270 distinct galleries**. Across all performers: 1,233,544 gallery rows over 884,280 URLs, 6,799 performers. | Each gallery label → `firstOrCreate` performer (no check that the label is a person). Then the photo loop sits inside the performer loop (`O(performers × photos)` writes). Per-performer photo lists are never capped or paginated. | **CONFIRMED** (data); design-level SUSPECTED for the photo fan-out |
| BH-persist-007 | S2 | `app/Services/Persist/MoviePersister.php:98-175` (no validation); `app/Services/Persist/PerformerPersister.php:335` (`JSON_INVALID_UTF8_SUBSTITUTE`) | Titles containing U+FFFD replacement characters are persisted silently. Invalid UTF-8 in `attrs` is replaced without a record. | SQL below; `php` snippet below. | Production: **all 85 movies with U+FFFD titles are CARIBBEANCOM** (e.g. id 268175 `�շ� ������`, id 268320). Repro shows `json_encode(…, JSON_INVALID_UTF8_SUBSTITUTE)` replacing `\xB1\xFE` with `��`. | Persist does not check text for U+FFFD or invalid UTF-8. The byte decoding happens upstream in CrawlerX (outside this repo; origin not verified). | **CONFIRMED** (data); origin upstream SUSPECTED |
| BH-persist-008 | S2 | `app/Services/Persist/MoviePersister.php:87-90`, `:165-167`; `app/Jobs/FetchDetailJob.php:132` | Crawl-run movie counters never move on the production path. | SQL below. | Production, last 500 `crawl_runs` (18 minutes of rows, 2026-11-06 23:53 → 2026-11-07 00:11): **0 with `pages_fetched > 0`, 0 with `movies_new` or `movies_updated > 0`**, all `completed`. Meanwhile 264,348 movies have `crawled_at` in the last 7 days. | `FetchDetailJob` calls `accept($draft, …)`, which calls `persist()` with `crawlRunId = null`, so `incrementMovieCount` (line 166) is never reached. Run rows are also created at a high rate (388k run ids). | **CONFIRMED** |
| BH-persist-009 | S3 | `app/Services/Identity/PerformerIdentityService.php:447-500` (`writeReviewPage`), `:172-190` | The identity review queue grows without bound. | SQL below. | Production: `performer_identity_reviews` = 147,083 rows; **139,575 open `birth_date_missing_or_conflicting`**, 2,715 open `co_credit_ambiguous`. Nothing prunes or expires rows. | Every same-name, different-or-missing-birth-date pair writes a review row; no TTL or closure policy. | **CONFIRMED** (count); impact on operations |
| BH-persist-010 | S3 | `app/Services/Persist/MoviePersister.php:655-668` (`flagSharpDrop`) | Sharp-drop review flags are never resolved, and each recrawl writes a new flag. | SQL below. | Production: `review_flags` `sharp_drop:3` 53 open, `sharp_drop:4` 14 open; `movies.needs_review` = 617. No code path sets `resolved_at` on `review_flags` (grep). | No resolution flow; `flagSharpDrop` is append-only. | **CONFIRMED** (no resolver), impact SUSPECTED |
| BH-persist-011 | S3 | `app/Services/Persist/MoviePersister.php:234-248`, `:257-273` | `movie_observations` grows by about 10 rows per crawl, including identical values, with no dedupe or retention. | SQL below. | Production: 4,181,084 rows (estimate) for ~310k movies (≈13.5 per movie). Movie 110883: every field has 4 rows, often 1 distinct value. | The design is append-only (AD-7), so this is a capacity risk, not a logic bug. | **CONFIRMED** (growth) |
| BH-persist-012 | S3 | `app/Services/Identity/PerformerIdentityService.php:600-615` (`refreshNameKeys`), `:1116` (`insertKeyRows`) | Name keys and trigram rows are only inserted, never deleted, so renamed or stale names keep matching candidates. | Code reading only. | No `DELETE` on `performer_name_keys` / `performer_name_grams` anywhere in `app/` (grep). Production: `performer_name_grams` ≈ 1.73M (estimate) vs `performer_name_keys` ≈ 231k. | Insert-only maintenance. | **SUSPECTED** (impact) |
| BH-persist-013 | S3 | `app/Services/Merge/MovieMerger.php:30-61`; `app/Services/Persist/MoviePersister.php:102` | Two concurrent first-time persists of one code race. The loser hits the unique `movies.code_normalized` violation and the job retries. `is_new` can be wrong. | Code reading only. | Production: `max(movies.id)` = 310,057 while `count(*)` = 309,984, so 73 ids are missing (rolled-back inserts are a likely cause, not proven). | Check-then-insert without a lock or `ON CONFLICT`. | **SUSPECTED** |
| BH-persist-014 | S3 | `app/Services/Persist/MoviePersister.php:98-175` | The archive write runs after the DB transaction. If Mongo throws, the job retries and `persist()` runs again. Observations are appended again and `incrementMovieCount` runs again. | Code reading only. | `archive()` is called at line 175, after the transaction closes at 172. No idempotency key on observations. | Non-atomic side effects. | **SUSPECTED** |
| BH-persist-015 | S3 | `app/Services/Persist/PerformerPersister.php:335` | `mergeAttrs` silently replaces invalid UTF-8 with U+FFFD (see BH-007). | `php` snippet below. | Substitution demonstrated. | `JSON_INVALID_UTF8_SUBSTITUTE` used without logging. | **CONFIRMED** (behaviour) |
| BH-persist-016 | S3 | `app/Support/Code/SiteCodeCanonicalizer.php:43`, `:49-65` (`detailPath`) | `DERIVED` codes are a hash of the raw URL path. A change of path encoding or slug gives a new movie. | Code reading only. | 5,480 derived movies (JAVBTC 2,678, TOKYOHOT 1,255, FFJAV 1,071, 141JAV 476). Duplicate rate not measured. | Identity from raw path string. | **SUSPECTED** |
| BH-persist-017 | S3 | Adapter title output (outside this repo), persisted unchanged by `MoviePersister.php:120-128` (`title_jp` projection) | Titles can embed the code (e.g. `FC2-PPV-3177134 【交流動画】…`). Cosmetic, but it defeats title search and dedupe. | SQL below. | Production sample ids 158268, 172078. | Adapter output is not cleaned before persist. | **CONFIRMED** (sample) |
| BH-persist-018 | S3 | `app/Services/Persist/PerformerPersister.php:57`; `app/Services/Persist/GalleryPersister.php:79-82` | Performer names are not NFKC-normalised at persist. Identity matching normalises at comparison time only. | Code reading only. | `prefer()` / `nameRomaji` stored raw. Duplicates not measured. | Normalisation only in the matcher. | **SUSPECTED** |

## Repro steps

### Repro A (title-minted codes): `scripts/repro_141.php`, run with `php`

Uses only `app/Support/Code/NormalizedCode.php` and `SiteCodeCanonicalizer.php` (no framework dependencies). Output, actual:

```
title=Vol02            => 'VOL-02'
title=Vol 02           => 'VOL-02'
title=Vol-02           => 'VOL-02'
title=(none) only URL  => '141JAV-DERIVED-C98EA203…'
```

The URL-derived `DERIVED` code is only reached when no title candidate exists. Expected: a path-identity site must not mint a code from a generic title.

Title-only minting (same harness): `resolve('javdb', ['Super Idol 2024 Best'])` → `IDOL-2024`; `['Top 10 Girls Special']` → `TOP-10`; `['HD 720P Vol 12']` → `VOL-12`.

### Repro B (full-width typed code): `scripts/repro_codes.php`

```
B  resolve('javdb', ['ＳＳＩＳ－１２３'])        => NULL
B2 NormalizedCode::from('ＳＳＩＳ－１２３')      => InvalidArgumentException: Code must contain a prefix and numeric suffix.
C  NormalizedCode::from('ssis-0123'|'SSIS 123'|'ssis123') => 'SSIS123' ×3   (intended collapse)
```

### Repro C (punctuation changes the result): `scripts/repro_precedence.php`

```
'099MBST006 【配信専用】ヤリマン娘グランプリ Vol 02'   => 'VOL-02'        (wrong)
'099MBST006 【配信専用】ヤリマン娘グランプリ Vol.02'  => '099MBST-006'   (correct)
```

### Production SQL (read-only, `ssh joos@192.168.10.12 'sudo -u postgres psql -X -A -F"|" -t -d xcrawler'`)

BH-001 (merged works), run after `SET statement_timeout=10000`:

```sql
WITH cand AS (SELECT id FROM movies WHERE code_normalized ~ '^(HOURS|VOL|MIX|PART|IDOL|BEST|NEW|SEXY|SPECIAL|SELECTION|HD|TOP|YEAR|MAX|HOURS)[0-9]+$')
SELECT (SELECT count(*) FROM cand) AS title_derived_candidates,
 (SELECT count(*) FROM (SELECT o.movie_id FROM movie_observations o JOIN cand c ON c.id = o.movie_id
   WHERE o.field = 'title_jp' GROUP BY o.movie_id HAVING count(DISTINCT o.value_hash) > 1) s) AS with_conflicting_titles;
-- result: 195 | 27
SELECT o.crawled_at::date, o.source_slug, left(o.value,70) FROM movie_observations o
 WHERE o.movie_id = (SELECT id FROM movies WHERE code_normalized = 'VOL002') AND o.field = 'title_jp' ORDER BY o.crawled_at LIMIT 10;
```

BH-004 (merged-away links):

```sql
SELECT count(*) AS merged_away, count(*) FILTER (WHERE EXISTS (SELECT 1 FROM movie_performers mp WHERE mp.performer_id = p.id LIMIT 1)) AS merged_away_with_links
FROM performers p WHERE p.canonical_performer_id IS NOT NULL;
-- result: 3424 | 2665
SELECT count(*) FROM (SELECT 1 FROM movie_performers mp JOIN performers p ON p.id = mp.performer_id
 WHERE p.canonical_performer_id IS NOT NULL LIMIT 200000) s;
-- result: 110846
```

BH-005 (genre width twins):

```sql
SELECT count(*) FROM genres WHERE label_normalized ~ '[！-～｡-ﾟ　]';   -- 178 (earlier query)
SELECT label_normalized, label_raw FROM genres WHERE label_normalized ~ '[！-～]' LIMIT 8;
```

BH-006 (gallery fan-out):

```sql
SELECT kind, count(*), count(DISTINCT url), count(DISTINCT performer_id) FROM performer_media WHERE kind='gallery' GROUP BY 1;
-- gallery | 1233544 | 884280 | 6799
SELECT performer_id, count(*), count(DISTINCT source_slug) FROM performer_media WHERE kind='gallery' GROUP BY performer_id ORDER BY 2 DESC LIMIT 5;
-- 1624 | 45014 | 1
SELECT count(DISTINCT meta->>'gallery_id') FROM performer_media WHERE performer_id = 1624 AND kind = 'gallery';  -- 1270
SELECT id, name_romaji, source_slug FROM performers WHERE id = 1624;  -- 'Av Idol' | javphotos
```

BH-007 (mojibake):

```sql
SELECT count(*) FROM movies WHERE title_jp LIKE '%'||chr(65533)||'%' OR title_en LIKE '%'||chr(65533)||'%';  -- 85
-- all 85 have code_normalized LIKE 'CARIBBEANCOM%' (grouped by prefix)
```

BH-008 (crawl runs):

```sql
SELECT count(*), count(*) FILTER (WHERE pages_fetched > 0), count(*) FILTER (WHERE movies_new > 0 OR movies_updated > 0), max(started_at), min(started_at)
FROM (SELECT * FROM crawl_runs ORDER BY id DESC LIMIT 500) s;
-- 500 | 0 | 0 | 2026-11-07 00:11:03+00 | 2026-11-06 23:53:03+00
```

## Scratch artefacts

No `tests/Scratch/` files were created and nothing was committed to the repo except this report. The three PHP repro scripts (`repro_141.php`, `repro_codes.php`, `repro_precedence.php`) lived in `/home/joos/Workspaces/.tmp/codex/hunt/scripts/`, outside the repo, and were deleted after this report was written. Their content is reproduced above.

Also run once: an Elasticsearch probe (`_count` and a 5-hit sample) with `VM_ELASTICSEARCH_ADMIN_*` read from the env file inside a script. The LAN endpoint has a self-signed certificate, so verification was disabled for that single read-only request. No credentials were printed.

## Fix first (ranked)

1. **BH-001**: stop minting codes from titles and URLs. Only accept typed or structured code fields. Path-identity sites use `DERIVED` when no typed code exists. Then quarantine the 195 title-minted movies and split the 27 conflicting ones.
2. **BH-004**: write only canonical performer ids in `MoviePersister` and `GalleryPersister`. On merge, move links (not copy) and log them for undo. Then purge the 110,846 stale rows in a chunked job.
3. **BH-008**: wire `crawlRunId` through `FetchDetailJob` → `accept()`, or stop claiming run counters. Add a test that a persisted movie increments the run.
4. **BH-006**: gate `GalleryPersister` performer creation on a person-name check, attach photos to the movie only, and cap or paginate `performer_media` per performer. Then clean up `Av Idol`-type rows.
5. **BH-005**: NFKC and `mb_convert_kana` for genre and performer labels. Merge the 178 width-twin genres (check against JOOXCR-411 first).
6. **BH-002**: remove the regex precedence problem. Pick the leading typed code before free-text patterns, and add a test per adapter title shape.
7. **BH-003**: NFKC before `NormalizedCode::from`, so full-width typed codes normalise instead of dropping the movie.
8. **BH-007**: reject or flag U+FFFD and invalid UTF-8 at the persist boundary (`needs_review` plus reason), and log `mergeAttrs` substitutions. Coordinate the upstream CrawlerX decode fix.
9. **BH-014**: make archive + observation writes idempotent (a key per crawl) or move the archive into an outbox, so retries cannot double-append.
10. **BH-009 / BH-010 / BH-012**: add TTL or closure for identity reviews and sharp-drop flags, and delete or version name keys on rename.

## Proposed tests (names only; to be written in the fix PR)

- `SiteCodeCanonicalizerTest::test_title_text_never_mints_a_dvd_code`
- `SiteCodeCanonicalizerTest::test_path_identity_site_uses_derived_code_when_only_title_is_available`
- `SiteCodeCanonicalizerTest::test_vol_title_with_space_and_dot_resolve_identically`
- `SiteCodeCanonicalizerTest::test_leading_typed_code_beats_free_text_pattern`
- `NormalizedCodeTest::test_full_width_code_is_normalised_via_nfkc`
- `MoviePersisterTest::test_two_unrelated_works_with_same_vol_title_do_not_merge`
- `MoviePersisterTest::test_merged_performer_is_linked_only_by_canonical_id`
- `PerformerIdentityServiceTest::test_merge_moves_movie_links_and_undo_restores_them`
- `GenreNormalizationTest::test_full_width_and_ideographic_space_fold_to_one_genre`
- `GalleryPersisterTest::test_label_is_not_a_performer_without_person_evidence`
- `GalleryPersisterTest::test_photos_attach_to_movie_not_to_every_performer`
- `MoviePersisterTest::test_u_fffd_title_is_flagged_not_silently_stored`
- `MoviePersisterTest::test_accept_increments_crawl_run_counters`
- `MoviePersisterTest::test_retry_after_archive_failure_does_not_double_append_observations`
- `MovieMergerTest::test_concurrent_first_persist_resolves_to_one_movie`

## Checked and found OK

- **Future release dates**: 353 movies have `release_date > current_date + 1`. All fall between 2026-11-10 and 2026-12-03 (none beyond 60 days), so they are scheduled pre-releases, not corruption.
- **Runtime > 1000 minutes**: 100 rows, all plausible compilations (福袋, "Ultimate", 1000+ min). No runtime ≤ 0.
- **Empty-title movies**: 0 (`title_jp` and `title_en` both NULL), including the 5,480 `DERIVED` movies.
- **Impossible release dates**: 0 before 1950; `community_score` within 0–10 for all rows.
- **Elasticsearch vs PostgreSQL count**: the earlier "92 extra" was a timing artefact. Exact PG `count(*)` = 309,984; ES `_count` = 309,984 at the same probe. ES `_id` is the deterministic movie `uuid`, so re-indexing cannot duplicate docs. Set equality was **not** verified (count only).
- **Self-referencing identity reviews**: `performer_identity_reviews` with `source_performer_id = candidate_performer_id`: 0.
- **Name matching rules**: `PerformerIdentityMatcher::classify` requires an exact normalised name plus a single matching birth date (or a shared cross-link) to merge. Token overlap and edit distance only yield reviews. No false-merge path found by reading.
- **Movie upserts**: `movies.code_normalized` unique; `movie_codes` unique on (code, source); `movie_media` unique on (movie, kind, url); `movie_genres` PK (movie, genre, source). Persist writes use these keys correctly.
- **Performer alias unique key** (`performer_aliases_source_unique` on (performer, alias, source_performer_id)) matches the `firstOrCreate` lookups; no collision.
- **Completeness tier** (`CompletenessTierCalculator`): buckets match the docblock; origin-source bump capped at tier 3.
- **Sharp-drop guard** (`MoviePersister::projectCoreFields`): keeps current values and flags when ≥3 core fields would drop; the null-overwrite guard works as documented.
- **Date parsing** (`PerformerAttributeNormalizer::parseDate`): `checkdate` validated; range rules enforced.
- **Movie merge lock order**: `mergeRoots` locks in ascending id order, matching `undoSplit`.
- **`NormalizedCode` zero-padding**: `SSIS-0001` and `SSIS-001` collapse as intended; the FC2-PPV prefix is kept distinct.

## Could not verify

- **Sentry impact** of BH-003 (full-width rejects) and BH-013/BH-014: no Sentry query was run in this pass (the sentry MCP server is not connected, and the budget was spent on PostgreSQL).
- **Set-level ES/PG agreement**: only counts were compared (309,984 each).
- **Upstream origin of U+FFFD** titles (CrawlerX adapter) and the 141jav / ffjav adapter code: outside this repo.
- **Duplicate rate of `DERIVED` codes** (BH-016) and **normalised-name duplicates** of performers beyond the same-romaji count (BH-018).
- **Movie links of performer 1624 "Av Idol"** (how many movies it sits on).
- **Redis / Horizon / Mongo archive counts**: not in scope for this run; Mongo was not queried.
- **Full PHPUnit run**: no test was executed through `docker/ci/run` because the worktree has no `vendor/` and no Docker work was in scope. Repros ran as plain PHP against the two dependency-free classes.
- **Production budget**: about 45 read-only SQL statements were run (limit 60); no error occurred. Planner estimates were used for tables above 1M rows.
