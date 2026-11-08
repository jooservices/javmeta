# Bug hunt: downloads, library, media, player (JOOXCR-414)

Date: 2026-11-07. Branch `JOOXCR-bughunt-media`, based on `JOOXCR-373-readiness` (1b667d9). Report only: no application code or tests were changed.

Scope: `app/Services/Downloads/**`, `Library*`, `Media*`, `UiPlayerController`, `UiMediaController`, `UiDownloadsController`, `FileController`, `MediaWorkerController`, `SceneSearchController`, `QBittorrentClient`, `MediaQueue`, `SceneSearchService`, `LibraryScanner` and commands, the `download_items` / `library_files` / `media_*` migrations, `config/javmeta_{downloads,library,media,capture}.php`, and the media worker in `tools/media-worker/` (HTTP guard, capture, policy, relay).

## Headline

- **Counts:** S1 = 0, S2 = 9, S3 = 15 (24 findings).
- **Most library, media and download findings are latent.** Production has **0 rows** in `library_files`, `media_jobs`, `media_scenes` and `download_items`, and downloads and files are switched off by default. The exception is BH-media-004: its data (`movie_media`) is live and already wrong for 43,417 movies.
- No S1 (data loss in a live path, security breach, or outage) was confirmed.

## Fix first (ranked)

1. **BH-media-004**: the download button refuses 43,417 movies that have a valid magnet (live data).
2. **BH-media-005**: qBittorrent down makes the downloads endpoints return an unhandled 500, not the 422 the controller promises.
3. **BH-media-003**: one file name in a non-UTF-8 encoding aborts the whole library scan (PostgreSQL rejects the bytes).
4. **BH-media-002**: one unreadable directory silently stops the traversal of its folder (46 of 60 files recorded in the repro).
5. **BH-media-001**: re-running a `scenes` job erases descriptions, tags and confidence written by `describe`.
6. **BH-media-007**: the same info hash for two movies keeps the first movie's code (255 hashes shared in production).
7. **BH-media-008**: `GET /api/v1/scenes/search` returns the owner's private library descriptions to any `movies`-scoped API key. The docs say this is intended, so the owner must decide.
8. **BH-media-009**: `SceneSearchService::indexFile` runs one embedder call per scene inside the worker's HTTP request.
9. **BH-media-006**: the on-disk list is silently truncated at 500 codes, and `codes()` is unbounded.
10. **BH-media-014**: an empty or unmounted library folder marks every file under it as missing.

## Findings

| ID | Sev | Status | File:line | Summary |
|---|---|---|---|---|
| BH-media-001 | S2 | CONFIRMED (test) | `app/Services/Media/MediaQueue.php:151-153` | Re-running `scenes` resets `description`, `tags`, `confidence` to null for every existing scene. |
| BH-media-002 | S2 | CONFIRMED (test) | `app/Services/Library/LibraryScanner.php:56-58, 72-75` | An unreadable entry throws inside the iterator filter; the rest of that folder is never visited. |
| BH-media-003 | S2 | CONFIRMED (codesIn + PG probe); end-to-end on PG not run | `LibraryScanner.php:92`, `:70-71` | A non-UTF-8 name yields no code, and the path insert fails on PostgreSQL; `QueryException` is rethrown and the whole scan dies. |
| BH-media-004 | S2 | CONFIRMED (test) + production | `app/Services/Downloads/DownloadService.php:34-37` | `add()` uses the newest magnet-kind row, which is an `https://` page link for 96,899 movies; a valid older magnet is ignored. |
| BH-media-005 | S2 | CONFIRMED (test) | `app/Http/Controllers/Ui/UiDownloadsController.php:83-90`, `app/Services/Downloads/QBittorrentClient.php:131-141` | `ConnectionException` is not a `RuntimeException`, so `guard()` does not catch it: HTTP 500. |
| BH-media-006 | S2 | CONFIRMED (test: truncation); CODE (unbounded `codes()`) | `app/Repositories/LibraryFileRepository.php:52-63, 76-88` | `onDisk()` stops at 500 rows without a signal; `codes()` loads every distinct code. |
| BH-media-007 | S2 | CONFIRMED (test) + production | `app/Services/Downloads/DownloadService.php:40` | `insertOrIgnore` keeps the first movie's code when a second movie adds the same btih. 255 hashes are shared by 2 movies. |
| BH-media-008 | S2 | CONFIRMED (test); design decision | `routes/api.php:95`, `app/Http/Middleware/AuthenticateApiKey.php` (scope map, `scenes/search` → `movies`) | Any `movies` API key reads private library scene descriptions and `library_file_id`. Documented as intended. |
| BH-media-009 | S2 | CODE; SUSPECTED impact | `app/Http/Controllers/Media/MediaWorkerController.php:56-58`, `app/Services/Media/SceneSearchService.php:18-48` | `complete()` runs one synchronous embedder call per scene, up to 10,000 per request; a failure is not retried by any job. |
| BH-media-010 | S3 | CONFIRMED (test) | `app/Services/Library/LibraryScanner.php:154-159` | Quick hash skips bytes between `hash_kilobytes` and `size` for files up to 2× that size; two such files collide. |
| BH-media-011 | S3 | CONFIRMED (test) | `app/Services/Downloads/DownloadService.php:35`, `QBittorrentClient.php:80` | `magnet:?` prefix is case-sensitive; RFC 3986 makes the scheme case-insensitive. 0 production rows. |
| BH-media-012 | S3 | CONFIRMED (PHP run) | `app/Services/Library/LibraryScanner.php:92` | `codesIn()` is ASCII-only: full-width `ＳＳＩＳ-123` yields no code. |
| BH-media-013 | S3 | CONFIRMED (run) + CODE | `app/Services/Media/MediaPolicy.php:15, 34-39`; `tools/media-worker/jobs/policy.py:6` | PHP and Python content policies disagree (`child_01 clip`: PHP allows, Python refuses); PHP age check passes performers without a birth date. |
| BH-media-014 | S3 | SUSPECTED (code) | `LibraryScanner.php:44, 78-81` | An empty mount point passes `is_dir`, so `presentIdsInFolder` marks the whole folder missing. No safety threshold. |
| BH-media-015 | S3 | SUSPECTED (code) | `app/Services/Media/SceneSearchService.php:45-47, 65-67, 57, 76`; `MediaQueue.php:144` | All `Throwable` swallowed with no log; scene deletes never remove the ES doc, so stale vectors take kNN slots and `search()` silently returns fewer than `limit`. |
| BH-media-016 | S3 | CODE | `LibraryScanner.php:111-125`; `LibraryFileRepository.php:91-98` | N+1 per file (find, then insert or update); an UPDATE for every seen file even when unchanged; `presentIdsInFolder` loads all ids into PHP; first scan reads 2 MiB per file (≈200 GiB for 100k new files at defaults, arithmetic only). |
| BH-media-017 | S3 | SUSPECTED (code) | `app/Services/Media/MediaQueue.php:32-41` | `capture()` has no dedupe or rate limit and no host check; only the worker's `public_http.py` blocks private addresses. |
| BH-media-018 | S3 | CODE | `app/Console/Commands/MediaEnqueueCommand.php:15, 25-34` | `--all` is accepted but does nothing; no cap on the number of enqueued jobs; a non-numeric `--file` raises a PostgreSQL error. |
| BH-media-019 | S3 | CODE | `app/Services/Downloads/DownloadService.php:125-135` | `state()` passes `checkingDL`, `moving`, `checkingResumeData` through raw; `pausedUP` (a finished torrent) is shown as "paused". |
| BH-media-020 | S3 | CODE | `DownloadService.php:30`; `UiDownloadsController.php:29` | `UPPER(display_code) = ?` cannot use the index on `movies` (~310k rows per `pg_stat`); `code` input has no length bound. |
| BH-media-021 | S3 | SUSPECTED (code) | `app/Services/Downloads/FileLinkService.php:26-38` | A root of `/` (or any ancestor folder) is accepted without a check; `files.root_folders` is not validated against sensitive paths. |
| BH-media-022 | S3 | CODE | `QBittorrentClient.php:131-137` | Credentials go over plaintext HTTP when the URL is `http://` (allowed); the SID is cached per instance and there is no re-login on 403 (harmless today: one instance per request). |
| BH-media-023 | S3 | CODE | `tools/media-worker/worker.py:30`; `app/Services/Settings/SettingsService.php` | `job_timeout_sec` is silently clamped to `lease_seconds - 10` (UI allows up to 72,000); no heartbeat, so a job that runs past the lease is reclaimed while still running. |
| BH-media-024 | S3 | SUSPECTED (code) | `LibraryScanner.php` (`scan()`), `app/Console/Commands/LibraryScanCommand.php` | `library:scan --force` takes no lock and can overlap the scheduled scan; a racing `createFile` raises a unique violation on `path`, which is rethrown and aborts the run. |

## Reproductions and evidence

All tests were run in the worktree with `docker/ci/run php vendor/bin/phpunit --testdox tests/Scratch/MediaBugHuntTest.php` (SQLite in-memory, the repository's test default). Results: 11 tests, 10 failing as expected, 1 passing (base32 decoding). The file was deleted afterwards (see "Scratch tests" below).

### BH-media-001 (S2): re-running `scenes` erases descriptions

- **Steps:** enqueue `scenes` for a file, claim and finish it with one scene. Enqueue `describe`, finish it with a description for that scene (`description` = "A desk"). Enqueue `scenes` again for the same file, finish with the same scene.
- **Expected:** description still "A desk".
- **Actual:** `null`. Test: `test_rerunning_scenes_keeps_existing_descriptions`, failing at `assertSame('A desk', …)` with "scenes re-run wiped the description".
- **Root cause (confirmed):** `storeScenes()` calls `updateOrInsert(['library_file_id', 'start_sec'], [... 'description' => null, 'tags' => null, 'confidence' => null, 'embedding_indexed_at' => null])`, so every re-run resets the enrichment that `describe` produced (MediaQueue.php:151-153).
- **Fix:** update only the geometry and thumbnail columns on an existing row (`end_sec`, `thumb_path`, `movie_id`). Clear the enrichment only when the thumbnail file changed. Delete rows whose `start_sec` disappeared, as now.
- **Tests:** `MediaQueueTest::test_rerunning_scenes_keeps_existing_descriptions`; `MediaQueueTest::test_rerunning_scenes_clears_enrichment_only_for_changed_thumbnails`.

### BH-media-002 (S2): unreadable directory halts the folder traversal

- **Steps:** folder with 60 `fN.mp4` files and a subdirectory `locked/` (mode 000) that holds one file. Run `LibraryScanner::scan()`.
- **Expected:** 60 rows.
- **Actual:** 46 rows (the number depends on `readdir` order). Test: `test_unreadable_directory_does_not_hide_the_rest_of_the_folder`.
- **Root cause (confirmed):** the `RecursiveCallbackFilterIterator` callback throws `RuntimeException` on the first unreadable entry (LibraryScanner.php:56-58). The `catch` at :72-75 then ends the folder without visiting the rest, and skips the missing sweep, which is correct but means nothing after that entry is recorded.
- **Fix:** do not throw from the filter. Record the unreadable path as an error and skip that subtree only. Keep traversing.
- **Tests:** `LibraryScanTest::test_unreadable_directory_does_not_hide_the_rest_of_the_folder`; `LibraryScanTest::test_unreadable_subtree_is_reported_not_missing`.

### BH-media-003 (S2): non-UTF-8 file names

- **Steps (a):** `codesIn(mb_convert_encoding('あ SSIS-123 字幕.mp4', 'SJIS', 'UTF-8'))`. Expected `['SSIS123']`, actual `[]`. Test: `test_non_utf8_file_name_still_yields_its_code`. Cause: `preg_match_all(... /u ...)` returns `false` on invalid UTF-8 (LibraryScanner.php:92), so the name is never matched.
- **Steps (b):** PostgreSQL, read-only probe: `SELECT convert_from(decode('8261','hex'), 'UTF8');` returns `ERROR: invalid byte sequence for encoding "UTF8": 0x82`. A file with such a name cannot be inserted into `library_files.path` (`text`), so `createFile()` throws `QueryException`, and LibraryScanner.php:70-71 rethrows it. One such file aborts the whole scan.
- **Status:** (a) confirmed by PHP run; (b) confirmed at the database layer. The full PostgreSQL end-to-end run was not done (tests use SQLite).
- **Fix:** read names as bytes; if the name is not valid UTF-8, try SJIS/EUC-JP/CP936 explicitly; otherwise store a placeholder and record an error. Never rethrow a per-file `QueryException`; record it and continue.
- **Tests:** `LibraryScanTest::test_non_utf8_name_is_matched_via_legacy_encoding`; `LibraryScanTest::test_one_bad_name_does_not_abort_the_scan` (PostgreSQL job).

### BH-media-004 (S2): magnet selection ignores format (live data)

- **Steps:** a movie with an older valid magnet and a newer `https://example.test/page/…` row, both `kind = magnet`. Call `DownloadService::add()` (with the settings enabled and qBittorrent faked).
- **Expected:** the valid magnet is used. **Actual:** `RuntimeException: JavMeta has no magnet for this movie.` Test: `test_newer_https_row_does_not_hide_a_valid_magnet`.
- **Production (read-only SELECT, 2026-11-07):**
  - `movie_media` magnet rows: 404,496. Rows not starting with `magnet:?`: **158,942**, all starting with `https://`. Hosts, top 5: ffjav.com 82,985; dl11.caribbeancompr.com 32,318; u.uuu.cam 29,245; onejav.com 10,137; www.141jav.com 4,092.
  - Movies with at least one magnet-kind row: 249,548. Newest row is `https://`: **96,899**. Of those, a valid magnet exists for **43,417**, and `add()` refuses them. 53,482 movies have no valid magnet at all.
  - Valid-looking rows with a bad btih: 3 (ids 26411, 36329, 36387). They contain 0/1/8/9 and are correctly rejected.
- **Root cause (confirmed):** `add()` orders by `crawled_at` and takes the first `url` without filtering to `magnet:?` (DownloadService.php:34-37). The crawler also stores page links as `kind = magnet` (out of scope; see "Could not verify").
- **Fix:** filter `url LIKE 'magnet:?%'` in SQL before the order and limit. Separately, stop writing `https` page links as `magnet` (classify them as a separate kind or drop them) and backfill.
- **Tests:** `DownloadsTest::test_add_uses_the_newest_valid_magnet`; `CrawlPersistTest::test_page_links_are_not_stored_as_magnets` (crawler side).

### BH-media-005 (S2): qBittorrent down → 500

- **Steps:** downloads enabled, `downloads.qbittorrent_url` = `http://127.0.0.1:9`, owner session. `GET /ui-me/downloads`.
- **Expected:** 422 with a message (the controller's contract). **Actual:** unhandled `Illuminate\Http\Client\ConnectionException: cURL error 7` (test error, which is an HTTP 500 in production). Test: `test_qbittorrent_down_returns_a_422_message`.
- **Root cause (confirmed):** `ConnectionException` extends `HttpClientException extends Exception` (checked in `vendor/`), but `UiDownloadsController::guard()` catches only `RuntimeException`. `QBittorrentClient` does not translate transport errors either.
- **Fix:** translate `HttpClientException` to `RuntimeException('qBittorrent is not reachable.')` in `QBittorrentClient::request()`, or catch it in `guard()`. Add the same to `FileController` and status pages.
- **Tests:** `DownloadsTest::test_qbittorrent_down_returns_a_422_message`; `DownloadsTest::test_qbittorrent_timeout_is_a_422`.

### BH-media-006 (S2): on-disk list truncated; codes unbounded

- **Steps:** insert 510 `library_files` rows with 510 distinct codes; call `UiLibraryService::onDisk()`. Expected 510, actual **500**. Test: `test_on_disk_list_is_not_truncated`. Cause: `->limit(500)` with no total and no pagination (LibraryFileRepository.php:84).
- `codes()` (LibraryFileRepository.php:52-63) returns every distinct code, unbounded (reading only). This breaks "a request costs O(page size)".
- **Fix:** paginate both (keyset on `code`), return a total or a `next` cursor, and keep the UI on pages.
- **Tests:** `LibraryTest::test_on_disk_is_paginated_and_reports_truncation`; `LibraryTest::test_codes_endpoint_is_bounded`.

### BH-media-007 (S2): one btih, two movies

- **Steps:** two movies `AAA-001` and `BBB-002` with the same magnet; `add('AAA-001')`, then `add('BBB-002')`; qBittorrent faked. Expected `download_items.movie_code` = `BBB-002` for the second add; actual `AAA-001`. Test: `test_second_movie_with_same_magnet_keeps_its_own_code`.
- **Production:** 255 info hashes are shared by 2 movies (510 `movie_media` rows).
- **Root cause (confirmed on the DB side):** `insertOrIgnore` on `download_items.hash` (unique) keeps the first code (DownloadService.php:40), so `list()` labels the second download with the first movie. Whether qBittorrent re-tags an existing torrent was not verified against a live instance; the local row is wrong either way.
- **Fix:** key by `(hash, movie_code)` or return a clear conflict to the owner; never silently keep the old label.
- **Tests:** the test above; `DownloadsTest::test_same_hash_for_two_movies_is_listed_under_both`.

### BH-media-008 (S2, decision needed): private scenes to catalog keys

- **Steps:** create an API key with scope `movies`; insert a `library_files` row and a `media_scenes` row with `description = 'private home scene'`; `GET /api/v1/scenes/search?q=private`. **Actual:** 200 with the scene. **Expected (if private):** 403. Test: `test_catalog_api_key_cannot_read_private_library_scenes`.
- **Docs:** `docs/03-operations/media.md:66` and `docs/03-operations/guest-mode.md:49` say the endpoint "requires the movies scope" and "may return local scene and file references". So this is intentional. It is still a privacy risk: the owner's home videos become readable by any holder of a catalog key.
- **Decision needed:** a dedicated `media`/`scenes` scope for the owner, or stripping `library_file_id` and `thumb_url` for catalog keys.
- **Tests:** the test above, once the owner decides.

### BH-media-009 (S2, suspected impact): synchronous per-scene indexing

- **Code:** `MediaWorkerController::complete()` calls `SceneSearchService::indexFile()` after `finish()` (MediaWorkerController.php:56-58). `indexFile()` loops over every scene and calls `embedOne()` (one HTTP call each), with no bound beyond the 10,000-description validation (`result.descriptions` max:10000).
- **Consequence:** a long describe result holds the worker's HTTP request open for N embedder calls. The worker's `urlopen` timeout is 30 s, so the worker sees an error after the job is already committed. Failed indexing is not retried by any job; only the manual `media:index-scenes` command revisits it.
- **Fix:** enqueue a `index_scenes` job (or chunked jobs of 100 scenes) from `finish()`. Return 202 from `complete()` without doing embedding work.
- **Tests:** `MediaWorkerTest::test_completing_describe_does_not_call_the_embedder_inline`; `SceneSearchTest::test_index_job_is_chunked`.

### BH-media-010 (S3): quick hash ignores the middle of small files

- **Steps:** two files of 1,572,864 bytes (1.5 MiB) that differ only at offset 1,048,676. Scan both. Expected different `quick_hash`; actual equal. Test: `test_quick_hash_distinguishes_files_that_differ_past_the_head`.
- **Cause:** `if ($size > $bytes * 2)` (LibraryScanner.php:154): for files up to 2× `hash_kilobytes` the tail is skipped, and the head covers only `hash_kilobytes`.
- **Impact:** low today: `quick_hash` is read only to skip re-hashing an unchanged file (LibraryScanner.php:117), and no duplicate detection uses it yet. It becomes a correctness issue as soon as duplicate detection (JOOXCR-384) relies on it.
- **Fix:** hash the whole file when `size <= 2 × bytes`, or hash `head + middle sample + tail` with the size in the key.

### BH-media-011 (S3): upper-case magnet scheme refused

- **Steps:** `hashOf('MAGNET:?xt=urn:btih:' . HASH)` throws `Not a magnet link.` Expected success. Test: `test_upper_case_magnet_scheme_is_accepted`. Production: 0 rows affected.
- **Fix:** compare the scheme case-insensitively (`strtolower(substr($magnet, 0, 8)) === 'magnet:?'`) in both `add()` and `hashOf()`.

### BH-media-012 (S3): non-ASCII codes

- **Check (PHP run):** the code regex on `'ＳＳＩＳ-123 clip.mp4'` returns 0 matches; on `'SSIS-123 clip.mp4'` 1 match. The lookbehind `(?<![A-Za-z0-9])` and `[A-Za-z]` are ASCII-only. Full-width names from Japanese systems are common.
- **Fix:** NFKC-normalise the file name before matching (`Normalizer::normalize($name, Normalizer::FORM_KC)`), then match.

### BH-media-013 (S3): content policy drift and fail-open age check

- **Run (PHP and Python, same input):**
  - `child_01 clip`: PHP `MediaPolicy` (`\b…child…\b` with `/iu`) → 0 (allowed); Python `jobs/policy.py` → True (refused).
  - `children`, `minor-free title`: both refuse.
  - `minorité`: both allow.
- **Consequence:** the worker is the last gate, so this is not a safety hole. A job passes PHP claim checks and then fails late in the worker.
- **Fail-open:** `MediaPolicy::assertFile()` checks only performers with a `birth_date` (MediaPolicy.php:34). A performer with no birth date passes. The docs (media.md:86) say "known performer age", so this matches the docs; it should be an explicit owner decision.
- **Fix:** one shared policy definition (a JSON fixture both languages test against). Decide whether unknown age should block.

### BH-media-014 (S3, suspected): an empty mount point marks the library missing

- **Code:** `LibraryScanner::scan()` treats `is_dir` as "present"; an empty mount point then yields zero files, and `presentIdsInFolder()` minus `$seen` marks every row missing (LibraryScanner.php:44, 78-81).
- **Consequence:** the library shows everything missing until the next good scan. No row is deleted, so this is recoverable.
- **Fix:** refuse to sweep a folder that yields 0 files while the table holds files for it, or require a sentinel file in the folder configuration.
- **Tests:** `LibraryScanTest::test_empty_folder_does_not_mark_the_library_missing`.

### BH-media-015 (S3, suspected): silent ES failures and stale docs

- **Code:** `indexFile()` ends in `catch (Throwable) {}` (SceneSearchService.php:45-47); `search()` does the same (:65-67) and returns the keyword fallback. A dimension mismatch returns silently (:28-29). Scene deletes in `storeScenes()` (MediaQueue.php:144) do not remove the ES documents, and `search()` drops hits whose rows are gone (:70-76), so `limit` is not reached.
- **Not verified:** Elasticsearch was out of scope and was not queried.
- **Fix:** log at warning with the job and scene ids; delete the ES docs for removed scenes in the same transaction (or a cleanup job); return the number of hits dropped.

### BH-media-016 (S3): library scan cost

- **Code:** per file, `findByPath` (1 query), then `createFile` or `updateFile` (1 query), then `match()` (1 query per candidate code). `updateFile` runs for every seen file even when size, mtime and hash are unchanged (LibraryScanner.php:125). `presentIdsInFolder` loads every id of the folder (LibraryFileRepository.php:91-98).
- **Quick hash I/O:** each new or changed file reads `2 × hash_kilobytes` (default 2 MiB). For 100k new files that is about 200 GiB read on the first scan (arithmetic, not measured).
- **Fix:** batch the lookups by folder (one `whereIn` per 500 paths), update only changed rows, page the id lists, and make the hash size an explicit cost in the UI.

### BH-media-017 (S3, suspected): `capture()` admission

- **Code:** `MediaQueue::capture()` (MediaQueue.php:32-41) validates the scheme, user and content policy, then inserts a job. There is no dedupe on URL, no rate limit, and no host or IP check. The only SSRF gate is the worker (`public_http.py`), which blocks private ranges, pins the resolved address, and re-checks each redirect; reading it, it is sound.
- **Fix:** dedupe on `(source_url, status in pending|running)`; a per-owner rate limit; a server-side host sanity check for defence in depth.

### BH-media-018 (S3): `media:enqueue`

- **Code:** `--all` is declared (MediaEnqueueCommand.php:15) and never read; with no `--file` or `--movie` the command enqueues every library file. A non-numeric `--file` is passed to `where('id', …)` and raises a PostgreSQL error (`:27`).
- **Fix:** require an explicit selector; validate `--file` as an integer; add a cap and a dry-run count.

### BH-media-019 (S3): download state mapping

- **Code:** `state()` (DownloadService.php:125-135): `checkingDL`, `checkingResumeData`, `moving` fall through to the raw value. `pausedUP` (completed and paused) matches `str_contains($raw, 'paused')` and is shown as paused, not completed.
- **Fix:** add explicit cases for the qBittorrent 4.x and 5.x state names (`stoppedUP`, `stoppedDL`, `checking*`, `moving`), and let `completed` win over `paused` when progress is 1.0.

### BH-media-020 (S3): sargable lookup and input bound

- **Code:** `DownloadService::add()` uses `whereRaw('UPPER(display_code) = ?')` (DownloadService.php:30). `movies` has about 310k rows (`pg_stat_user_tables`); the index is on `code_normalized`, so this is a sequential scan per add. `UiDownloadsController::store()` accepts an unbounded `code` (:29).
- **Fix:** look up through `movie_codes.code_normalized` with `NormalizedCode::from($code)`; cap `code` at 64 characters.

### BH-media-021 (S3, suspected): file server roots

- **Code:** `FileLinkService::roots()` accepts any folder that `realpath()` resolves, including `/` (FileLinkService.php:26-38). A `/` root makes every file servable by a super administrator who signs in and opens a link (the signed route still requires `auth`, the super-admin role and the IP allowlist).
- **Fix:** reject roots equal to `/` or to the home directory; warn in the settings UI when a root contains a library folder.

### BH-media-022 (S3): qBittorrent transport and session

- **Code:** credentials are sent with `asForm()` to whatever `downloads.qbittorrent_url` is, including `http://` (QBittorrentClient.php:131-137). The SID is cached on the instance; there is no re-login on 403. The container creates one instance per request, so this is harmless today.
- **Fix:** warn when the URL is `http://` and the host is not loopback; re-login once on 401/403 before failing.

### BH-media-023 (S3): timeout, lease and heartbeat

- **Code:** `worker.py:30` sets the subprocess timeout to `min(job_timeout_sec, lease_seconds - 10)`, silently. `SettingsService` allows `job_timeout_sec` up to 72,000 and `lease_seconds` up to 86,400 (config and settings registry). With the defaults (7,500 and 7,200) the lease is larger than the timeout, so this is fine. There is no heartbeat, so any job that exceeds the lease is reclaimed while still running and processed twice (the first result then gets 409).
- **Fix:** surface the effective timeout in the settings UI; add a lease heartbeat endpoint that the worker calls during long steps.

### BH-media-024 (S3, suspected): concurrent scans

- **Code:** the scheduled `library:scan` uses `withoutOverlapping()` (routes/console.php:80); `library:scan --force` takes no lock. Two runs can both `createFile()` the same path. The second gets a unique violation on `library_files.path`, which LibraryScanner.php:70-71 rethrows.
- **Fix:** take the same cache lock in the command and in `scan()`; treat a unique violation on insert as an update.

## Checked and found OK

- **File serving** (`FileController`, `FileLinkService`): `files.download` requires `auth`, `EnsureSuperAdministrator` and the `signed` middleware; the path is encrypted in the link; `resolve()` applies `realpath()` and a prefix check, so `../` and symlink escapes are refused; the IP allowlist applies; the CSP is `sandbox`. `BinaryFileResponse` streams with Range support, so memory stays flat.
- **Scanner symlinks**: `RecursiveCallbackFilterIterator` drops links at every level, and a link at the root is refused (LibraryScanner.php:44, 53-55).
- **Claim and finish** (`MediaQueue`): claim and finish are token-checked (409 on a stale token), idempotent, and serialised by the `media_worker_lock` row (seeded by the migration). The stale-lease update order is correct: attempts ≥ max go to `failed`, the rest go to `pending`. `retry()` only accepts `failed`.
- **Output containment**: `outputPath()` and `storeScenes()` use `realpath()` with a prefix check against the output folder.
- **Worker HTTP guard** (`public_http.py`): validates every DNS answer, connects to the pinned address, re-checks each redirect, and the relay only maps tokens to the origin that was validated. Reading only; the worker tests were not run.
- **qBittorrent isolation**: every mutation goes through `assertOurs()`, so torrents outside the category are never paused, listed or deleted.
- **Magnet parsing**: `hashOf()` reads `xt=urn:btih:` only; tracker and other parameters are ignored; the base32 decoder matches the vector (32 × `A` → 40 × `0`, 32 × `7` → 40 × `f`; test passes). The 3 production rows with 0/1/8/9 are rejected, which is correct.
- **HLS rows**: all 117,171 `movie_media` `hls` URLs are `https://`; none carry signature or expiry query parameters, so no expired-signature URLs are stored.
- **Scene search input**: `q` is 3–200 characters; `per_page` is capped at 50; the keyword fallback escapes `!`, `%` and `_`.
- **Pagination**: `UiMediaController::index` and `files` are paginated at 50.
- **Player XSS**: `public/ui/js/views/player.js` escapes URLs, descriptions and labels with `esc()`; no unescaped user data found by reading.
- **Config apply**: `UiLibraryService::summary()` reads `config('javmeta_library.folders')`; `SettingsService::apply()` refreshes config on each `booted` and each `JobProcessing`, so the flag is current.
- **Setting-to-config keys**: `files.enabled` maps to `javmeta_downloads.files_enabled`, which exists.
- **Capture duration**: the rule in the docs (media.md:90) is enforced by `SettingsService` (line 227).
- **Timezone**: `app.timezone` is UTC; the scan hour is evaluated in the alerts report timezone, as the setting describes.
- **Sentry, last 24 h**: no error groups mention the library, downloads, qBittorrent or media. Matching groups are crawler issues (out of scope).

## Could not verify

- **PostgreSQL end-to-end for BH-media-003**: tests use SQLite. Only the byte-level probe was run on production.
- **Elasticsearch** (BH-media-015, BH-media-009): not queried; out of the stated scope.
- **qBittorrent real responses**: faked with `Http::fake` in the tests; state names come from the qBittorrent Web API docs, not a live instance.
- **Scale**: the 100k-file scan (BH-media-016) was not measured, only estimated.
- **Worker**: the Python test suite and a live ffmpeg/whisper run were not executed. Only the code was read.
- **Crawler page links stored as magnets** (root of BH-media-004): the writer is in the crawler/normaliser, outside this scope. Not traced.
- **Production library/media/download features**: all tables are empty, so every finding except BH-media-004 and BH-media-011 (case) is latent. Whether the owner plans to enable them was not checked.
- **Prometheus**: not queried.

## Production queries (read-only, about 10 SQL statements, 2 Sentry shell calls)

- Planner estimates from `pg_stat_user_tables` (for tables above 1M rows only, the `movie_media` estimate).
- Row counts of `library_files`, `media_jobs`, `media_scenes`, `download_items` (all 0); `movie_media` magnet 404,496 and HLS 117,171.
- PostgreSQL UTF-8 probe (`decode('8261','hex')`): rejected as expected.
- Magnet classification: prefix counts (158,942 `https://`), btih validity (3 rows), prefix histogram (`left(url, 8)`, not printing URLs), newest-row analysis (96,899 / 43,417 / 53,482), hosts (domain only), shared btih (255).
- HLS scheme and signature parameters (117,171, 0, 0, 0).
- Sentry: `get_sums` on the group TSDB for 24 h matching groups (no library, download or media groups).
- No URL, token, passkey or credential was printed or stored. Magnet rows were only counted or printed as `left(url, 8)` or as the domain.

Error disclosure: the first UTF-8 probe and the first Sentry script failed because of my own query literal and my own script (`tsdb.models`), not because of the systems. The PostgreSQL error came from my literal, and I fixed it and reran. The Sentry error was fixed and reran once.

## Scratch tests (deleted)

File: `tests/Scratch/MediaBugHuntTest.php` (not committed; deleted at the end). Test methods, in order:

1. `test_rerunning_scenes_keeps_existing_descriptions` (BH-media-001)
2. `test_unreadable_directory_does_not_hide_the_rest_of_the_folder` (BH-media-002): 60 `fN.mp4` files plus `locked/` at mode 000, expects 60 rows.
3. `test_non_utf8_file_name_still_yields_its_code` (BH-media-003): `codesIn()` on an SJIS name, expects `['SSIS123']`.
4. `test_quick_hash_distinguishes_files_that_differ_past_the_head` (BH-media-010): two 1.5 MiB files, one byte differs at offset 1,048,676.
5. `test_qbittorrent_down_returns_a_422_message` (BH-media-005): `http://127.0.0.1:9`, expects 422.
6. `test_on_disk_list_is_not_truncated` (BH-media-006): 510 rows, expects 510.
7. `test_upper_case_magnet_scheme_is_accepted` (BH-media-011).
8. `test_second_movie_with_same_magnet_keeps_its_own_code` (BH-media-007): `Http::fake` for login and add.
9. `test_newer_https_row_does_not_hide_a_valid_magnet` (BH-media-004): `Http::fake`.
10. `test_catalog_api_key_cannot_read_private_library_scenes` (BH-media-008): `movies`-scoped key, expects 403.
11. `test_base32_btih_decodes_to_hex` (passing; checked OK).

A test with a wrong expectation (a 32-character all-`a` btih, which is valid base32) was removed; it is not a finding.

## Proposed fix order and tests

Named failing tests to add when the owner approves the fixes are listed in each finding above.
