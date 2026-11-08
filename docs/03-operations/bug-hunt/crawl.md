# Bug hunt: crawl pipeline (queue, claims, fetch methods, retries)

Ticket: JOOXCR-414. Base: `JOOXCR-373-readiness` (1b667d9). Read-only hunt: no application code or test was changed.

Scope: `app/Services/Crawl/**`, `app/Jobs/**`, `app/Console/Commands/Crawler/**`, `CrawlTickCommand`, `routes/console.php`, `config/javmeta_queues.php`, `config/javmeta_sources.php`, `app/Models/CrawlQueue.php`, `app/Repositories/CrawlQueueRepository.php`, `app/Services/Crawler/CrawlerxClient.php`, and the CrawlerX fallback chain in `vendor/jooservices/crawlerx`.

Note on the branch under test: `develop` and `main` do **not** contain commit `76811ae` (JOOXCR-390). Line numbers in the table refer to `JOOXCR-373-readiness` unless marked `develop`. Several findings are only present on `develop`/`main`.

## Counts by severity

| Severity | Count | Ids |
|---|---|---|
| S1 (data loss / security / outage) | 2 | 001, 002 |
| S2 (wrong results / visible defect) | 7 | 003, 004, 005, 006, 007, 008, 009 |
| S3 (minor / robustness) | 6 | 010, 011, 012, 013, 014, 015 |

Verification legend: **VERIFIED** = a scratch test or production/Sentry query was run and the result is quoted. **CODE** = proven by reading the code path, no runtime run. **SUSPECTED** = plausible, not proven.

## Findings

| id | sev | file:line | summary | status |
|---|---|---|---|---|
| BH-crawl-001 | S1 | `develop`: app/Services/Crawl/SourceRequestPacer.php:34-43 (fixed on readiness: :37-44) | `reserveStart` on `develop`/`main` passes the key array as Laravel's `$numberOfKeys`; every paced claim start throws a TypeError, strands the row, and the fix is not on `develop`/`main` | VERIFIED (Sentry + code diff), local repro needs live Redis |
| BH-crawl-002 | S1 | app/Jobs/Concerns/HandlesCrawlQueueRow.php:109-117, app/Services/Crawl/CrawlQueueService.php:310-321, app/Repositories/CrawlQueueRepository.php:528-537 | A claim that never starts keeps its per-source inflight slot for `max(900, 100 × 180) = 18000 s` (5 h with repo defaults); 4 dead claims block a source completely | VERIFIED (scratch tests 004, 004b) |
| BH-crawl-003 | S2 | app/Console/Commands/Crawler/FeedPoolCommand.php:308, 333 | `claimNext` commits the claim before `dispatchJob`; an exception on dispatch (Redis push) strands the committed claim for the full unstarted TTL | CODE (mechanism), SUSPECTED for the pre-2026-11-03 claim-expired events |
| BH-crawl-004 | S2 | app/Services/Crawl/SourceThrottle.php:77-85; callers app/Jobs/FetchDetailJob.php:89,107,115, app/Jobs/FetchPerformerDetailJob.php:83 | Normalizer/parse failures call `onFailure($source)` with no code; the `default` branch doubles the gap, contrary to the docblock | VERIFIED (scratch test 001: gap 20 → 40) |
| BH-crawl-005 | S2 | app/Services/Crawl/CrawlQueueService.php:340-342 | Upstream `Retry-After` is trusted with no cap; a garbage value parks a row for decades | VERIFIED (scratch test 003: next_attempt_at 2036-11-04) |
| BH-crawl-006 | S2 | vendor/jooservices/crawlerx/src/Fetch/FetchFallbackChain.php:198-204; app/Services/Crawl/CrawlRetryPolicy.php:191-199 | `ssrf_blocked` is permanent and never retried; jable 1,383 failed rows, missav 57, 141jav 26, ffjav 20 | VERIFIED (prod counts + Sentry 24h); root cause SUSPECTED |
| BH-crawl-007 | S2 | app/Services/Crawl/FetchAttemptRecorder.php:58, 67-76 | Attempt errors are persisted with only HTML and URL stripping; `Authorization: Bearer <token>` survives into `crawl_fetch_attempts.error` | VERIFIED (scratch test 006); exposure SUSPECTED |
| BH-crawl-008 | S2 | app/Console/Commands/Crawler/SeedCommand.php:79-90, 126-129; routes/console.php:62-64 | `crawl_runs` rows opened per source per minute are closed only on success; 7 rows stuck in `running` since 2026-10-27; `crawler:seed`, `crawler:reclaim`, `crawler:sync-sources` have no `withoutOverlapping()` | VERIFIED (prod) |
| BH-crawl-009 | S2 | app/Jobs/FetchDetailJob.php:120-125 | The draft is delivered before `markDone`; a failure after delivery re-marks the row and a later crawl delivers the draft again | CODE, SUSPECTED (sink idempotency not checked) |
| BH-crawl-010 | S3 | app/Services/Crawl/CrawlQueueService.php:251-256 | `sanitizeErrorMessage` does not redact `Cookie:`, `Set-Cookie:`, or cookie names such as `cf_clearance` / `sid` | VERIFIED (scratch test 005) |
| BH-crawl-011 | S3 | app/Repositories/CrawlQueueRepository.php:390-414; app/Services/Crawl/CrawlQueueService.php:225-235 | The pacing sleep runs inside the `FOR UPDATE` transaction of `startClaimedRow`: up to ~134 s holding a row lock and a PG connection | CODE |
| BH-crawl-012 | S3 | app/Services/Crawl/FetchMethodPreference.php:58-101, 104-128, 160-179, 206-208 | Lock wait of 1 s silently drops samples; the host index per source grows without a cap and `isFlaresolverrPreferredForSource` reads one cache key per host | CODE |
| BH-crawl-013 | S3 | app/Repositories/CrawlQueueRepository.php:297-303 | `restartListingLoop` checks `exists()` outside its transaction; a concurrent seed run can reset the loop in between | SUSPECTED |
| BH-crawl-014 | S3 | app/Repositories/CrawlQueueRepository.php:452-461; database/migrations/2026_11_02_000002_add_crawl_queue_pending_kind_index.php | `sourcesHeldByBlockDelay()` scans all pending blocked rows on every claim; the pending partial index is `(kind, id)` with no `next_attempt_at`, so the claim scan is O(pending) when most due rows belong to excluded sources | SUSPECTED (no EXPLAIN on production allowed) |
| BH-crawl-015 | S3 | app/Services/Crawl/CrawlQueueService.php:183-193 | In `markFailed` the `elseif` retry-round branch is overridden for the non-retry lane (lines 189-193 always reset to round 1), so the branch is dead for that lane and the docblock describes behaviour that does not happen | CODE |

## Detail

### BH-crawl-001 (S1) `Redis::eval` argument order on develop/main

- **Where:** `develop:app/Services/Crawl/SourceRequestPacer.php:34-43` (the develop call). Fixed on `JOOXCR-373-readiness` in commit `76811ae`, `app/Services/Crawl/SourceRequestPacer.php:37-44`.
- **Reproduction (code):** develop calls `->eval(self::RESERVE_START, [key, gap, ttl, max], 1)`. Laravel's `PhpRedisConnection::eval($script, $numberOfKeys, ...$arguments)` (`vendor/laravel/framework/src/Illuminate/Redis/Connections/PhpRedisConnection.php:545-547`) therefore receives the array as `$numberOfKeys`, and forwards `command('eval', [$script, [1], array])`. phpredis then rejects argument #3.
- **Evidence (Sentry, read-only, project 2):**
  - `TypeError: Redis::eval(): Argument #3 ($num_keys) must be of type int, array given` at `SourceRequestPacer::reserveStart`: group 167 = 271 events/24 h, group 168 = 181, group 169 = 90. Total **542/24 h**. Last seen 2026-11-07 00:01.
  - `RuntimeException: Worker claim expired before the crawl row started.` (group 50): **542/24 h**, identical to the eval total.
- **Root cause (confirmed):** wrong argument shape on develop/main. The fix exists only on `JOOXCR-373-readiness` (commit `76811ae`, 2026-11-05), so `develop`/`main` still contain it. **Which build production runs is not verified.** Sentry shows the error still firing after the fix commit date.
- **Local repro:** not run. phpredis connects before it checks arguments, so a runtime without Redis reports `went away` instead of the TypeError (scratch test 002 was dropped for that reason).
- **Proposed fix:** merge or cherry-pick `76811ae` into `develop`/`main` and deploy. Add a regression test that runs the pacer against a real Redis.
- **Named failing test:** `Tests\Integration\SourceRequestPacerTest::test_reserve_start_passes_numkeys_as_int` (requires Redis in the integration suite).

### BH-crawl-002 (S1) Unstarted claims hold the source for 5 hours

- **Where:** `HandlesCrawlQueueRow::failed()` returns early when `started_at === null` (`HandlesCrawlQueueRow.php:109-117`). So a job that fails before `startClaimedRow` commits (BH-001 throws inside the transaction, so `started_at` stays null) never releases its claim. `CrawlQueueService::unstartedClaimTimeoutSeconds()` (`:310-321`) returns `max(900, max(100,100,10) × 180) = 18000 s`. `sourcesAtInflightLimit()` (`CrawlQueueRepository.php:528-537`) counts every `claimed` row per source, started or not.
- **Reproduction:** scratch test `BhCrawlScratchTest::test_bh_crawl_004_stranded_unstarted_claims_block_the_source`: 4 claimed unstarted rows for `javdb`, `claimed_at = now()-1h`, plus one pending `javdb` detail row. `claimNext('worker-b', 'detail')` returns `null`.
- **Evidence:** test result `Failed asserting that null is not null` (the pending row cannot be claimed).
- **Companion check:** `test_bh_crawl_004b_unstarted_claim_is_not_reclaimed_after_an_hour`: `reclaimStaleClaimed(900)` returns 0 for a 1-hour-old unstarted claim.
- **Production snapshot (read-only):** `status` counts: done 613,492, pending 45,738, failed 4,081, claimed 12, retry_pending 9. The 12 unstarted claims are all younger than 1 h (6 direct, 6 browser). **This does not match the 5 h default cutoff**, which would leave roughly 100+ rows at ~540/day. The effective `depth_limit` in production is likely lower. This is **not verified**: the settings-table query failed (`relation "settings" does not exist`) and I stopped production queries per the rule.
- **Root cause (confirmed by test):** the reclaim TTL for unstarted claims is designed to wait out the queue backlog, but the same claims count toward the source's inflight cap, so dead claims starve the source for the whole TTL.
- **Proposed fix:** (a) in `failed()` and in the `startClaimedRow` failure path, release a claim that never started (back to `pending`/`retry_pending`, `error_code = worker_failed`); (b) measure the unstarted TTL from dispatch time (store `dispatched_at`) and keep it short; (c) exclude unstarted claims older than the stale timeout from `sourcesAtInflightLimit`.
- **Named failing tests:** `CrawlQueueServiceClaimTest::test_unstarted_claims_do_not_hold_source_inflight_slots`, `QueueAttemptLifecycleTest::test_failure_before_start_releases_the_claim`.

### BH-crawl-003 (S2) Dispatch failure strands a committed claim

- **Where:** `FeedPoolCommand::dispatchQueue()` calls `claimNext()` (`:308`, its own transaction, committed) and then `dispatchJob()` (`:333`). There is no try/catch, and the exception ends `feed()` and the command.
- **Mechanism:** if the Redis push fails, the claimed row stays `claimed` with `started_at = null` until reclaim (BH-002). While Redis is down, one row is stranded per 5-second feed run.
- **Evidence:** code only. The Sentry group `claim expired` first appeared on 2026-10-31 22:00, before the eval TypeError (2026-11-03 16:29). **So at least one other cause existed before 11-03.** Dispatch failure is the most likely candidate, but I did not confirm it. The pre-11-03 daily volume was not queried.
- **Proposed fix:** wrap `dispatchJob` in try/catch; on failure, release the row (`status` back to `pending`/`retry_pending`, `claimed_at`/`locked_by` null) and re-throw or emit an ops event.
- **Named failing test:** `FeedPoolCommandTest::test_dispatch_failure_releases_the_claimed_row` (queue connection that throws on push).

### BH-crawl-004 (S2) Parse and normalization failures double the gap

- **Where:** `SourceThrottle::onFailure()` `match` (`SourceThrottle.php:77-85`): codes `parse_failed`, `normalization_failed`, `not_found`, `soft404`, `gone`, … leave the gap alone, but `null` falls into `default => min($maximum, $current * 2)`. Callers that pass no code: `FetchDetailJob.php:89` (no normalizer), `:107` (`JavbtcCodeMissingException`), `:115` (`NormalizationFailedException`), and `FetchPerformerDetailJob.php:83`.
- **Reproduction:** scratch test `test_bh_crawl_001_normalizer_failure_without_code_doubles_gap`: source with `gap_seconds_current = 20`, min 20, max 60; `onFailure($source)` (no code).
- **Evidence:** `Failed asserting that 40.0 is identical to 20.0`.
- **Root cause (confirmed):** the call sites do not pass the error code that the docblock (`SourceThrottle.php:58-60`) relies on.
- **Impact:** a source whose normalizer drifts slows toward its maximum gap (60–120 s) and recovers only 10 % per success.
- **Proposed fix:** pass `normalization_failed` / `parse_failed` at those call sites; make the `default` branch explicit and log unknown codes.
- **Named failing test:** `SourceThrottleTest::test_normalization_failure_leaves_gap_alone`.

### BH-crawl-005 (S2) Upstream `Retry-After` is unbounded

- **Where:** `CrawlQueueService::retryBackoffSeconds()` returns the raw value (`:340-342`), and `markFailed` adds it to `now()` (`:182-186`).
- **Reproduction:** scratch test `test_bh_crawl_003_retry_after_is_capped`: `markFailed($row, RateLimited, ..., retryAfter = 315360000)`.
- **Evidence:** `next_attempt_at=2036-11-04 00:13:14`; `Failed asserting that false is true`.
- **Root cause (confirmed):** no upper bound on a value that comes from the upstream site.
- **Proposed fix:** clamp to the largest configured delay (`retry.delays_seconds` max, or a new `retry_after_max_seconds`).
- **Named failing test:** `CrawlQueueServiceTest::test_retry_after_is_capped`.

### BH-crawl-006 (S2) `ssrf_blocked` is permanent for several sources

- **Where:** CrawlerX `FetchFallbackChain.php:198-204` throws `SsrfBlocked` with `retryable: false`. `CrawlRetryPolicy::RETRYABLE_CODES` (`:191-199`) does not include `ssrf_blocked`, and `importHistoricalFailures` only imports the retryable codes.
- **Evidence (production, `crawl_queue` status `failed`, read-only):**

  | source | error_code | rows |
  |---|---|---|
  | jable | ssrf_blocked | 1,383 |
  | missav | ssrf_blocked | 57 |
  | 141jav | ssrf_blocked | 26 |
  | ffjav | ssrf_blocked | 20 |
  | tokyohot | ssrf_blocked | 10 |

  Sentry 24 h: `Fetch blocked by SSRF policy for URL [https://en.jable.tv/...]` group 58 = 516 events; missav group 62 = 195.
- **Root cause:** **SUSPECTED.** Either the SSRF guard sees a redirect or proxy egress address as private, or the URLs are classified wrongly. The guard's input was not traced; the rows fail on the first attempt with no retry.
- **Proposed fix:** determine why these public hosts resolve to a blocked address (capture the redirect chain and resolved IP for one URL); decide whether `ssrf_blocked` should be retried on another egress; add a test per decision.
- **Named test:** `FetchDetailJobTest::test_ssrf_block_is_not_permanent_for_public_hosts` (after the root cause is known).

### BH-crawl-007 (S2) Attempt errors keep bearer tokens

- **Where:** `FetchAttemptRecorder::sanitize()` (`:67-76`) strips HTML and URLs only, and it is used for `crawl_fetch_attempts.error` (`:58`). `CrawlQueueService::sanitizeErrorMessage()` redacts Bearer and Basic, but the recorder does not use it.
- **Reproduction:** scratch test `test_bh_crawl_006_attempt_error_sanitizer_keeps_bearer_token` (reflection on the private method): input `Authorization: Bearer eyJsecrettoken123`.
- **Evidence:** `'proxy said Authorization: Bearer eyJsecrettoken123' does not contain "eyJsecrettoken123"` fails: the token is kept.
- **Exposure:** **SUSPECTED.** Requires the upstream or proxy error text to contain the header. Not observed in production (not searched).
- **Proposed fix:** route the recorder through the same redaction as `sanitizeErrorMessage` (one shared redactor).
- **Named test:** `FetchAttemptRecorderTest::test_error_text_is_redacted_before_persist`.

### BH-crawl-008 (S2) `crawl_runs` left `running`; seed not overlap-guarded

- **Where:** `SeedCommand::enqueueSourceSeeds()` creates a `CrawlRun` (`:79-90`), and it is completed only at the end (`:126-129`), with no `try/finally`. `routes/console.php:64` schedules `crawler:seed` every minute with no `withoutOverlapping()`; likewise `crawler:reclaim` (`:63`) and `crawler:sync-sources` (`:62`).
- **Evidence (production, read-only):** `SELECT status, count(*), min(started_at) FROM crawl_runs WHERE status='running' GROUP BY 1` → `running | 7 | 2026-10-27 03:08:31+00`.
- **Root cause (confirmed for the leak; overlap is CODE):** an exception or kill between create and finish leaves the row open.
- **Proposed fix:** `try/finally` that marks the run `failed`; add `withoutOverlapping()` to seed, reclaim, and sync-sources.
- **Named test:** `SeedCommandTest::test_run_is_closed_when_enqueue_fails`.

### BH-crawl-009 (S2) Draft delivered before the row is marked done

- **Where:** `FetchDetailJob::handle()` calls `deliverDraft()` (`:120`) and then `markDone()` (`:125`). `tries = 1`, but `HandlesCrawlQueueRow::failed()` re-marks the row retryable on any failure after the claim started.
- **Evidence:** code only. No runtime run.
- **Root cause:** SUSPECTED. The draft delivery is not idempotent with respect to a retry of the same row, and the sink's idempotency was not checked.
- **Proposed fix:** make delivery idempotent per `(source, url, is_recrawl)` or move it into the same transaction as `markDone`.
- **Named test:** `FetchDetailJobTest::test_retry_after_failed_mark_done_does_not_duplicate_the_draft`.

### BH-crawl-010 (S3) Cookie values survive error sanitizing

- **Where:** `CrawlQueueService::sanitizeErrorMessage()` (`:251-256`).
- **Reproduction:** scratch test `test_bh_crawl_005_sanitizer_redacts_cookie_values`: `Cookie: cf_clearance=abc123secretvalue; sid=s3cr3tsession`.
- **Evidence:** the output keeps both values (`Failed asserting that '...' does not contain "abc123secretvalue"`).
- **Proposed fix:** add `Cookie`/`Set-Cookie` header redaction and a `cookie-name=value` pattern.
- **Named test:** `CrawlQueueServiceTest::test_error_message_redacts_cookies`.

### BH-crawl-011 (S3) Pacing sleep inside a row-locking transaction

- **Where:** `CrawlQueueRepository::startClaimedRow()` runs `$prepare` inside `DB::transaction` (`:390-414`); `$prepare` calls `reserveStart` and then `wait()` (`CrawlQueueService.php:225-235`), with a budget of `(180 − 45 − 1) s` (`:322-328`).
- **Evidence:** code only.
- **Proposed fix:** reserve inside the transaction, sleep after commit.
- **Named test:** `CrawlQueueServiceClaimTest::test_pacing_wait_runs_outside_the_row_transaction`.

### BH-crawl-012 (S3) Fetch-method preference loses samples and grows without bound

- **Where:** `FetchMethodPreference::recordAttempts()` runs `withLock(…)->block(1)` and swallows the timeout (`:58-101`, `:206-208`). `rememberHost()` appends hosts without a cap (`:160-179`); `isFlaresolverrPreferredForSource()` reads one cache key per host (`:104-128`).
- **Evidence:** code only.
- **Proposed fix:** retry the lock with a longer wait, cap the host list (most recent N), and store a per-source preferred flag.
- **Named test:** `FetchMethodPreferenceTest::test_host_index_is_capped`.

### BH-crawl-013 (S3) `restartListingLoop` check outside the transaction

- **Where:** `CrawlQueueRepository::restartListingLoop()` evaluates `exists()` at `:300-301`, before `DB::transaction` at `:305`.
- **Evidence:** code only. Concurrent seed runs (BH-008) make it reachable.
- **Proposed fix:** move the check inside the transaction with a `lockForUpdate` on the source's listing rows.
- **Named test:** `CrawlQueueRepositoryTest::test_restart_check_is_inside_the_transaction`.

### BH-crawl-014 (S3) Claim hot path scans pending rows

- **Where:** `sourcesHeldByBlockDelay()` (`:452-461`) runs on every claim. The pending partial index is `(kind, id) WHERE status = 'pending'` (`database/migrations/2026_11_02_000002_…php`), with no `next_attempt_at`.
- **Evidence:** schema reading only; `EXPLAIN` on production was not allowed. Production has 45,738 pending rows.
- **Proposed fix:** index `(kind, next_attempt_at, id) WHERE status = 'pending'`; cache the held-source set for a few seconds.
- **Named test:** none (performance check on a production-sized fixture).

### BH-crawl-015 (S3) Dead branch in `markFailed`

- **Where:** `CrawlQueueService::markFailed()` lines 183-187 set `retry_pending` with round + 1, but lines 189-193 overwrite it for the non-retry lane with round 1.
- **Evidence:** code only.
- **Proposed fix:** delete the dead branch or make the docblock match.
- **Named test:** `CrawlQueueServiceTest::test_non_retry_exhaustion_sets_round_one`.

## Fix first (ranked)

1. **BH-001**: merge `76811ae` into `develop`/`main` and deploy; this single change stops 542 strands/day.
2. **BH-002**: release claims that never started (`failed()` and the `startClaimedRow` failure path).
3. **BH-002**: exclude dead unstarted claims from `sourcesAtInflightLimit`.
4. **BH-003**: compensate a failed dispatch by releasing the row.
5. **BH-004**: pass `normalization_failed` / `parse_failed` in the throttle call sites.
6. **BH-005**: cap upstream `Retry-After`.
7. **BH-006**: root-cause the `ssrf_blocked` false positives (jable, missav).
8. **BH-007**: one shared redactor for attempt errors.
9. **BH-008**: close `crawl_runs` in `finally`; `withoutOverlapping()` on seed, reclaim, sync-sources.
10. **BH-009**: make draft delivery idempotent or move it into the `markDone` transaction.

## Checked and found OK

- `claimNext`: advisory xact lock 281, `FOR UPDATE` on the eligible query, and the status and `next_attempt_at` filters; the diagnostics path locks the candidate row. No double claim found in the code.
- `startClaimedRow`: checks status, `locked_by` token, expected kind, and `started_at`, so a second start of the same row returns null.
- `forEachStaleClaimed`: runs in a transaction with `FOR UPDATE` and `chunkById(100)`; no skipped rows from the id cursor.
- Feed pool: advisory lock released in `finally`; interval lock released in `finally`.
- `SourceRequestPacer` Lua (the logic, not the call shape): correct start-time arithmetic, `-1` sentinel for over-budget waits, TTL ≥ 60 s.
- `markFailed` block delays are indexed by attempt and clamp to the configured block delays.
- `CrawlRetryPolicy` and the historical import predicate (`whereHistoricallyRetryable`) agree on which codes are retryable and which need the explicit flag.
- `CrawlerxFetchMethodPlan::orderedChain`: both probe directions produce the intended order, including the no-op cases.
- `CrawlerxFetchMethodPlan::retryOptionsFor`: the `fetch?->chain->methods` expression does not dereference null, because `??` supplies the manifest chain.
- `restartListingLoop`: the delete is limited to the same listing kind, matching its docblock.
- `importHistoricalFailures`: `chunkById` with a fresh `whereIn` per chunk; no unbounded collection.
- `FetchAttemptRecorder`: a persistence failure is logged and does not block the crawl row (by design).
- Timezone: `app.timezone = UTC`; every gap and TTL comparison is an absolute timestamp difference, so no DST or offset defect was found.
- `SourceThrottle::onSuccess`: recovery step is bounded below by the minimum, and clamped for overrides.

## Could not verify

- **Production `depth_limit` overrides** (the effective unstarted TTL). The settings-table query failed and production queries were stopped per the rule.
- **Which build production runs** (`develop` vs `JOOXCR-373-readiness`). Sentry shows the eval TypeError still firing on 2026-11-07.
- **Local reproduction of BH-001**: needs a live Redis in the test runtime.
- **Root cause of `ssrf_blocked`** for jable and missav: the redirect chain and resolved addresses were not captured.
- **The pre-2026-11-03 "claim expired" volume and cause** (BH-003 is the leading hypothesis only).
- **Sentry classification of `Fetch budget exhausted`** (whether javmeta treats it as retryable).
- **Redis, Postgres, or Mongo down mid-job** beyond the Redis push path reasoned in BH-003.
- **Downstream sinks** (`MovieDraftSink`, Elasticsearch indexing) for the idempotency question in BH-009.
- **Dependency drift**: the worktree's `vendor/` is hard-linked from `JAVM-100-deploy` because the main checkout has no PHPUnit. `composer.lock` differs from `JAVM-100-deploy`, so the test run used a near-identical vendor.

## Scratch tests (not committed; deleted at the end)

`tests/Scratch/BhCrawlScratchTest.php`. The test-name numbers are scratch labels; the mapping to report ids is in brackets.

- `test_bh_crawl_001_…` [BH-crawl-004]: fails, 40 vs 20.
- `test_bh_crawl_002_…` [BH-crawl-001]: dropped (needs live Redis; its assertion is quoted in the BH-crawl-001 section).
- `test_bh_crawl_003_…` [BH-crawl-005]: fails, next_attempt_at 2036.
- `test_bh_crawl_004_…` [BH-crawl-002]: fails, claimNext returns null.
- `test_bh_crawl_004b_…` [BH-crawl-002]: fails, reclaimed 0.
- `test_bh_crawl_005_…` [BH-crawl-010]: fails, cookie value kept.
- `test_bh_crawl_006_…` [BH-crawl-007]: fails, token kept.
