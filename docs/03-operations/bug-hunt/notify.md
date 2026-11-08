# Bug hunt: notifications, alerts, reports, Telegram bot (JOOXCR-414)

Scope: `app/Services/Notifications/**`, `app/Services/Alerts/**`, `app/Services/Report/**`,
`app/Notifications/**`, `config/javmeta_alerts.php`, `config/javmeta_report.php`, notification
registry/templates, `TelegramBot` (commands and polling), scheduled alert/report commands.

Worktree: `JOOXCR-bughunt-notify` (from `JOOXCR-373-readiness` @ 1b667d9). Report only: no application
code or committed test was changed. Scratch tests were run and deleted afterwards (Appendix A/B).

Legend: **CONFIRMED** = reproduced by a test that was run, or by a production read that was run.
**CODE** = verified by reading the code path, not executed. **SUSPECTED** = plausible, not verified.

## Counts

| Severity | Count | Ids |
|---|---|---|
| S1 data loss / security / outage | 0 | - |
| S2 wrong results / visible defect | 4 | 001, 002, 003, 004 |
| S3 minor / robustness | 10 | 005 to 014 |

No S1 was found. Auth on the bot, template escaping and secret handling held up (see "checked OK").

## Summary table

| id | sev | file:line | summary | evidence level |
|---|---|---|---|---|
| BH-notify-001 | S2 | `app/Services/Alerts/AlertEvaluationService.php:130-135`, `:250-263` | Open source/rate alerts are never resolved while their sample is below the minimum, so they stay open for days | CONFIRMED (test + prod) |
| BH-notify-002 | S2 | `app/Services/Notifications/TelegramBot.php:55-76` | One update the Telegram API refuses blocks every later update forever (poison pill) | CONFIRMED (test) |
| BH-notify-003 | S2 | `app/Services/Notifications/TelegramBot.php:44-46`, `:55-62` | Old updates are executed (no `date` check); a missing offset row replays up to 24 h of queued commands | CONFIRMED (test) |
| BH-notify-004 | S2 | `routes/console.php:69,79,85` and `$scheduleCommand` helper (`:49-58`) | Scheduler mutex (`withoutOverlapping()`) expires after 24 h, so a killed run silences the job for a day | SUSPECTED (framework default verified in vendor) |
| BH-notify-005 | S3 | `app/Services/Notifications/FollowNotifier.php:157-170`, `:64` | Telegram send, bookkeeping and the catch-all share one `try`; a bookkeeping failure after a real send causes a re-send | CODE / SUSPECTED |
| BH-notify-006 | S3 | `app/Services/Notifications/FollowNotifier.php:46`, `:99`; `NotificationDeliveryServiceRepository.php:85` | Each run loads up to 200 candidates and runs one query per candidate, even when quiet hours or the cap hold them all | CODE |
| BH-notify-007 | S3 | `app/Services/Report/PeriodicReportService.php:117-123` | A report delivered after a timeout is stored as `failed` (wrong metrics and history) | CODE |
| BH-notify-008 | S3 | `app/Services/Notifications/TelegramBot.php:326`; `:297-300`, `:294` | Bot replies are cut at 4000 code points mid-line; `/follows` is unbounded; Telegram counts UTF-16 units | CODE |
| BH-notify-009 | S3 | `app/Services/Report/ReportSchedule.php:27-29` | A periodic slot is only taken when the command runs in its exact minute; a missed tick loses the slot with no catch-up | SUSPECTED (prod: no gaps in last 4 days) |
| BH-notify-010 | S3 | `app/Services/Alerts/AlertEvaluationService.php:74` | Per-source alerts are suppressed only when `success_rate` fires, not when `success_rate_critical` fires | CODE |
| BH-notify-011 | S3 | `app/Services/Report/ReportRenderer.php:16`, `:18`; `app/Services/Notifications/NotificationRenderer.php:211-216`; `app/Services/Report/ReportSchedule.php:22`; `config/javmeta_report.php:8`, `:10` | Mexico City is the default timezone and the label is hard-coded, while the owner is ICT (UTC+7) | CODE (prod setting value not verified) |
| BH-notify-012 | S3 | `app/Repositories/TelegramBotServiceRepository.php:45-48`, `:60-65` | Bot lookups cannot use an index: `UPPER(display_code)` on 310k rows has no index; the performer lookup misses its partial index | CONFIRMED (pg_indexes) / plan not run |
| BH-notify-013 | S3 | `app/Services/Notifications/TelegramBot.php:309-321`; `app/Notifications/TelegramAlertNotifier.php:148-151`; `config/javmeta_alerts.php:44-50` | Alerts and the bot read different Telegram config keys. The legacy fallback has no env mapping, so it is dead, and a setup that only fills it sends alerts but the bot silently refuses | CODE |
| BH-notify-014 | S3 | `app/Services/Alerts/AlertOperationsServiceRepository.php:69-77`; `app/Services/Alerts/AlertOperationsServiceRepository.php:35-43` | Unbounded reads on every minute tick (`pendingRecoveries`, `activeEvents`) | CODE |

## Ranked "fix first" list

1. **BH-notify-001**: treat "no sample" as a state, not as "still firing". Resolve a rule when it has had no sample for a full window, or after a grace period. Stops 5 alerts staying open for 2 to 3 days in production.
2. **BH-notify-002**: on a permanent Telegram 4xx for one update, log it, mark the update done and move on. Keep retries only for transport failures, with a cap.
3. **BH-notify-003**: skip updates whose `message.date` is older than about 10 minutes. On the first poll with no offset row, seed the offset to the newest update id instead of 0.
4. **BH-notify-004**: give every `withoutOverlapping()` an explicit expiry of a few minutes, as `crawler:source-health` already does with `withoutOverlapping(120)`.
5. **BH-notify-005**: call `markSent` and `recordSent` outside the network `try`, and log the failure, so a delivered message is never retried.
6. **BH-notify-006**: run the quiet-hours and cap checks before `matches()`. Batch the performer lookup into one query.
7. **BH-notify-008**: cut bot replies at line boundaries under 4096 UTF-16 units. Paginate or cap `/follows`.
8. **BH-notify-010**: suppress per-source alerts when either the global or the critical rate fires. Add a test for both.
9. **BH-notify-012**: add `lower()` and `upper()` expression indexes for the bot lookups, and make the performer lookup use the partial predicate. Needs an EXPLAIN on a staging copy, since EXPLAIN on production was not allowed.
10. **BH-notify-013**: pick one config source for Telegram (`laravel-notifications.channels.telegram.*`). Remove the dead `javmeta_alerts.telegram.bot_token`/`chat_id` fallback or give it env mappings.

## Findings in detail

### BH-notify-001 (S2): open source/rate alerts never resolve when the sample is small

- **Where:** `app/Services/Alerts/AlertEvaluationService.php:130-135` (`resolve()`), `:250-263` (`needsRateSample()`).
- **Summary:** when a rule that needs a sample (`success_rate*`, `blocked_ratio`, `source_success_rate:<enabled source>`) has fewer attempts than `min_attempts_in_window` (100), `measurement()` returns null and `resolve()` returns early. Nothing ever closes the event, so no recovery is sent and the event stays "active" (the `activeEvents()` list keeps it).
- **Reproduction (CONFIRMED):** `tests/Scratch/StuckAlertBugHuntTest.php` (Appendix B), run with
  `export DOCKER_CONFIG=/home/joos/Workspaces/.tmp/javmeta-docker-config-jooxcr-341; docker/ci/run php vendor/bin/phpunit tests/Scratch/StuckAlertBugHuntTest.php`.
  Step 1: `javdb` 50/100 succeed, so an alert opens. Step 2 (30 min later): `javdb` 2/2 succeed. Step 3 (2 h later): `javdb` 0 attempts. Result:
  ```
  OPEN_AFTER_RECOVERY=1 SENT=["open:success_rate_critical"]
  Failed asserting that 1 is identical to 0.   (alert should resolve once the source is healthy)
  ```
- **Evidence (production, read-only):** `alert_events`, last 30 days, Query Q1 (Appendix C):
  `source_success_rate | 131 events | 5 still open | 0 recovery pending`.
  Query Q2 lists the open events. `source_success_rate:javbus` (id 114) and `:xcity` (id 151) have `notified_at` NULL.
  Ages: 3 d 8 h, 3 d 8 h, 3 d 7 h, 2 d 22 h, 2 d 20 h.
  Query Q3/Q4 show that the open sources had 0 to 2 attempts in the last 15 minutes (`moodyz` 2, `premiumbeauty` 1, the other three 0), while the crawler as a whole did 582 attempts in that time. So they are below the 100-attempt minimum and never resolve.
- **Root cause (CONFIRMED):** `resolve()` treats "no sample" as "unknown, keep open". For low-volume enabled sources that is permanent, because the low volume itself is what keeps the sample small.
- **Fix:** when `needsRateSample` is true and the sample has been below the minimum for a full `window_minutes`, resolve the event (with the last value and a "no traffic" context) and send the recovery if the cause was ever notified. Keep waiting only for the case where the source is still enabled and has recent failures.
- **Named failing tests:** `AlertEvaluationServiceTest::test_source_alert_resolves_after_a_full_window_below_the_sample_minimum`, `test_zero_traffic_window_resolves_rate_alerts_instead_of_holding_them_open`.

### BH-notify-002 (S2): one refused Telegram update blocks all later updates (poison pill)

- **Where:** `app/Services/Notifications/TelegramBot.php:55-76` (`poll()`), `:84-100` (`handle()`), `:324-334` (`send()`).
- **Summary:** `handle()` replies with `send()`, which throws `RuntimeException('Telegram send failed.')` on any non-OK response. `poll()` catches `Throwable` generically, returns `telegram update failed` and does not save the offset. The next minute, `getUpdates` returns the same update first, and the same failure repeats. Everything after it is never read.
- **Reproduction (CONFIRMED):** `tests/Scratch/TelegramBotBugHuntTest.php::test_refused_reply_blocks_later_updates` (Appendix A). Telegram is faked: update 10 is `/nosuch` (its reply is refused with HTTP 400), update 11 is `/help` (accepted).
  ```
  POLL1={"handled":0,"ignored":0,"skipped":"telegram update failed"}
  POLL2={"handled":0,"ignored":0,"skipped":"telegram update failed"}
  SENDMESSAGE_ATTEMPTS=2        (both attempts are for update 10)
  ```
  `/help` (update 11) is never answered, and no `tg_offset` row is written.
- **Root cause (CONFIRMED):** no distinction between permanent (4xx) and transient (5xx, timeout) failures, no retry cap, and no skip. The comment "Transport and unexpected failures are retried" is true for everything, which is the problem.
- **Side effect (CODE):** `/download` and `/follow` run before the reply is sent. If the reply then fails, the action repeats on every retry. The "already exists" check is only there for follows (the unique index on `user_marks`), not for downloads.
- **Fix:** on a 4xx from Telegram, log and mark the update done. Retry only on transport errors and 5xx, with a cap (for example 3 attempts) after which the update is skipped. Persist the offset per update.
- **Named failing tests:** `TelegramBotTest::test_permanent_telegram_refusal_does_not_block_later_updates`, `test_transient_failure_retries_at_most_n_times_then_skips`.

### BH-notify-003 (S2): old updates are executed; first run with no offset row replays the queue

- **Where:** `app/Services/Notifications/TelegramBot.php:44-46` (offset and `getUpdates`), `:55-62` (no staleness check); `app/Repositories/TelegramBotServiceRepository.php:31-34` (`offset()` returns 0 when the row is missing).
- **Reproduction (CONFIRMED):** `tests/Scratch/TelegramBotBugHuntTest.php::test_stale_update_is_answered_and_offset_starts_at_zero` (Appendix A). A `/help` update dated 3 days ago is fetched with `offset=1`, answered, and `handled` is 1. The assertions pass, which shows the bug.
- **Evidence (production, read-only):** Query Q7 and Q8: `user_marks` has **0 rows** in total, so there is no `tg_offset` row. Telegram keeps unconfirmed updates for up to 24 h. The first time the bot is enabled (or after the row is lost), it replays up to 24 h of commands, including `/download` and `/follow`. Whether `telegram.bot_enabled` is on in production is not verified (no read of the settings store).
- **Root cause (CONFIRMED):** no `message.date` check, and offset 0 means "from the beginning" when the row is missing.
- **Fix:** ignore updates with `date` older than about 10 minutes (log them). When no offset row exists, seed it from the newest update on the first poll.
- **Named failing tests:** `TelegramBotTest::test_updates_older_than_ten_minutes_are_ignored`, `test_first_poll_without_offset_seeds_to_newest_update`.

### BH-notify-004 (S2, SUSPECTED): scheduler mutex expires after 24 h

- **Where:** `routes/console.php:69` (`alerts:evaluate`), `:79` (`telegram:poll`), `:85` (`follows:notify`), and `:49-58` (`$scheduleCommand` helper, which does not set `onOneServer()`; `withoutOverlapping()` is called without an expiry).
- **Evidence (vendor source, not run):** `vendor/laravel/framework/src/Illuminate/Console/Scheduling/ManagesAttributes.php:86` sets `public $expiresAt = 1440;` (minutes), and `CacheEventMutex` locks with `expiresAt * 60`. The framework releases the lock only on SIGTERM/SIGINT (`releaseOnTerminationSignals`). A run killed by SIGKILL, OOM or a PHP fatal error keeps the lock for 24 h.
- **Impact:** after one killed `alerts:evaluate`, no alert evaluation runs for up to a day. After one killed `telegram:poll`, the bot is silent for a day. `crawler:source-health` already uses `withoutOverlapping(120)`, so the team knows the pattern.
- **Not reproduced:** a real kill of a scheduled run. To verify: run `alerts:evaluate` with a `kill -9` mid-run in a sandbox and check the lock in the cache store (the prod `CACHE_STORE` is not verified; `.env.example` says `database`).
- **Fix:** `->withoutOverlapping(5)` (or similar) on every scheduled command, and add `onOneServer()` to `alerts:evaluate`, `telegram:poll`, `follows:notify` and `crawler:watchdog` if they are meant to run once across nodes.
- **Named test:** `SchedulerConfigTest::test_every_overlap_guard_declares_an_expiry_below_one_hour`.

### BH-notify-005 (S3): a bookkeeping failure after a real send causes a duplicate

- **Where:** `app/Services/Notifications/FollowNotifier.php:157` and `:165` (`markSent` inside the `try`), `:169-171` (catch returns `false`), `:64` (`recordSent` after `send()`).
- **Summary:** if `sendPhoto` or `sendMessage` succeeds and then `markSent()` throws, the catch returns `false`. `run()` then does not call `recordSent`, so the next 5-minute run sends the same movie again. The same happens if `recordSent()` throws at line 64: the exception escapes `run()`, nothing is recorded, and the next run repeats the send. The catch-all also logs nothing, so the failure is invisible.
- **Evidence:** CODE. Not run (no test was written for this one).
- **Fix:** separate the network call from the bookkeeping. Record the send in one place and log bookkeeping failures.
- **Named test:** `FollowNotifierTest::test_send_is_not_repeated_when_marking_the_template_sent_throws`.

### BH-notify-006 (S3): FollowNotifier does work even when it will send nothing

- **Where:** `app/Services/Notifications/FollowNotifier.php:46` (`matches()` runs before the quiet and cap checks), `:99` (`matchingPerformers()` once per candidate row), `app/Repositories/NotificationDeliveryServiceRepository.php:85` (`limit(200)`).
- **Summary:** while quiet hours hold messages back (up to 24 h), or the daily cap is reached, every 5-minute run loads up to 200 candidate movies and issues one `matchingPerformers` query per candidate. That is up to 201 queries every 5 minutes, about 57,000 a day, for nothing.
- **Evidence:** CODE. Production `follow_notifications` and `user_marks` have 0 rows (Q7), so the feature is dormant in production today. This is a latent cost, not a current incident.
- **Fix:** check `quiet()` and the cap before calling `matches()`. Fetch performer matches for all candidates in one `whereIn` query.
- **Named test:** `FollowNotifierTest::test_quiet_hours_do_not_query_candidates_per_movie`.

### BH-notify-007 (S3): a report delivered after a timeout is recorded as failed

- **Where:** `app/Services/Report/PeriodicReportService.php:117-123`.
- **Summary:** the channel call is wrapped in `catch (Throwable) { $sent = false; }`. If Telegram accepted the message but the HTTP call timed out, `$sent` is false, the snapshot is stored with `result = 'failed'`, and the policy reservation is released. Nothing is re-sent (the slot reservation stays, see line 143), but the history and metrics say the report failed.
- **Evidence:** CODE. Production (Q5) shows 48 periodic rows in the last 4 days, all `sent`, none `failed`, so this has not happened in the observed window.
- **Fix:** if the outcome is unknown (timeout), store `result = 'unknown'` and do not release the policy reservation.
- **Named test:** `PeriodicReportServiceTest::test_timeout_after_delivery_is_not_recorded_as_failed`.

### BH-notify-008 (S3): replies are cut mid-line and the size limit is counted in the wrong units

- **Where:** `app/Services/Notifications/TelegramBot.php:326` (`mb_substr($text, 0, 4000)`), `:294` (`follows()` output is not limited; `follows()` in the repository, `TelegramBotServiceRepository.php:83-88`, has no `LIMIT`), `:219-233` (`search()` may build more than 4000 characters: up to 10 movies with titles plus up to 10 each of performers, genres, makers and series).
- **Summary:** the cut is at 4000 code points, so it can land mid-line and drop a whole movie with no marker. Telegram's limit is 4096 UTF-16 code units, so an emoji-heavy reply can still be refused (for example 4000 astral characters is 8000 units). A refused reply then triggers BH-notify-002.
- **Evidence:** CODE for the cut and the missing `LIMIT`. The unit arithmetic is from the Telegram documentation, not tested against the live API.
- **Fix:** cut at a line boundary, add a "... (N more)" marker, count UTF-16 units (`mb_strlen($s, 'UTF-16')`/2 approximation, or a byte budget), and cap `/follows` to a page.
- **Named test:** `TelegramBotTest::test_long_reply_is_cut_at_a_line_with_a_marker_under_4096_utf16_units`.

### BH-notify-009 (S3, SUSPECTED): a missed scheduler tick loses a periodic report slot

- **Where:** `app/Services/Report/ReportSchedule.php:27-29`. A slot is returned only if `now` is exactly the start of the slot's minute. There is no catch-up, and `report:send` runs `everyMinute()`.
- **Evidence:** the production history (Q5) shows no gaps over 125 minutes in the last 4 days, 48 of 48 sent. So the scheduler has been reliable recently. A killed or delayed tick (see BH-notify-004) would lose that slot silently.
- **Fix:** accept the newest slot that has not been taken and is at most one interval old, not only the exact minute.
- **Named test:** `ReportScheduleTest::test_a_late_tick_still_takes_the_current_slot`.

### BH-notify-010 (S3): critical rate alerts do not suppress per-source notices

- **Where:** `app/Services/Alerts/AlertEvaluationService.php:74`.
- **Summary:** `if (! str_starts_with($rule, 'source_success_rate:') || ! array_key_exists('success_rate', $firing))` suppresses source notices only when the warning-level `success_rate` fires. When `success_rate_critical` fires (below 70%), the same notices go through, one per source. During a global outage that is noise.
- **Evidence:** CODE. Not run.
- **Fix:** suppress when either `success_rate` or `success_rate_critical` is firing.
- **Named test:** `AlertEvaluationServiceTest::test_per_source_notices_are_suppressed_while_the_critical_rate_fires`.

### BH-notify-011 (S3): Mexico City defaults and hard-coded labels, owner is ICT

- **Where:** `app/Services/Report/ReportRenderer.php:16,18` (`'America/Mexico_City'` default at `:16`, `$zoneLabel` `'Mexico'` at `:18`), `app/Services/Notifications/NotificationRenderer.php:211-216` (default and `(Mexico)` label), `app/Services/Report/ReportSchedule.php:22`, `config/javmeta_report.php:8,10`.
- **Summary:** the defaults and labels assume `America/Mexico_City`. The owner's timezone is ICT (`Asia/Ho_Chi_Minh`, as `config/javmeta_alerts.php` already uses). Settings override the config values (`config/javmeta_settings.php:219,234`), so the wrong default only shows when the setting is missing. Production value not checked.
- **Evidence:** CODE. The DB settings value was not read.
- **Fix:** use one default (ICT) from one config key, and derive the label from the zone name, not from a special case.
- **Named test:** `ReportRendererTest::test_zone_label_follows_the_configured_timezone`.

### BH-notify-012 (S3): bot lookups do not use indexes

- **Where:** `app/Repositories/TelegramBotServiceRepository.php:45-48` (`WHERE UPPER(display_code) = ?`, `movies`), `:60-65` (`LOWER(name_romaji) = ? OR LOWER(name_kanji) = ?`, `performers`, with no `ORDER BY`).
- **Evidence (production, read-only, Q6):** `movies` has 309,986 live rows. No index in `pg_indexes` mentions `display_code`, so `/movie` sequential-scans the table. `performers` (156,702 rows) has `lower(name_romaji)` and `lower(name_kanji)` indexes, but only `WHERE canonical_performer_id IS NULL`. The query has no such predicate, so the partial index cannot be used. `value('id')` without an order also means an arbitrary performer when names repeat.
- **Not run:** `EXPLAIN` on production (not allowed). The plan claim comes from the index definitions and the query shape.
- **Fix:** add `movies ((upper(display_code)))` (or store the normalised code) and add `canonical_performer_id IS NULL` plus an `ORDER BY` to the performer lookup. Verify with EXPLAIN on a staging copy.
- **Named test:** `TelegramBotServiceRepositoryTest::test_performer_lookup_is_deterministic_and_uses_the_canonical_rows`.

### BH-notify-013 (S3): alerts and the bot read different Telegram config

- **Where:** `app/Services/Notifications/TelegramBot.php:309-321` (reads only `laravel-notifications.channels.telegram.*`), `app/Services/Notifications/FollowNotifier.php:121-133` (same), `app/Notifications/TelegramAlertNotifier.php:148-151` (falls back to `javmeta_alerts.telegram.bot_token` and `.chat_id`), `config/javmeta_alerts.php:44-50` (those two keys default to `''` and have no env mapping).
- **Summary:** the fallback in the alert path can never be filled by an env variable, so it is dead in practice. If someone configures only the `javmeta_alerts.telegram` keys, alerts are sent, but the bot and follow notifications log "telegram is not configured" and do nothing.
- **Evidence:** CODE.
- **Fix:** remove the dead fallback, or make all three readers use one accessor.
- **Named test:** `TelegramConfigTest::test_bot_and_alerts_read_the_same_credentials`.

### BH-notify-014 (S3): unbounded reads on every minute tick

- **Where:** `app/Services/Alerts/AlertOperationsServiceRepository.php:69-77` (`pendingRecoveries()`, no limit, called every minute by `retryPendingRecoveries()`), `:35-43` (`activeEvents()`, no limit; bounded by the number of rules today, so low).
- **Summary:** `pendingRecoveries()` loads every resolved event whose recovery is not complete. Events that never get `recovery_completed_at` (for example, the rule is still open again) are re-read every minute with no upper bound.
- **Evidence:** CODE. Production has 0 pending recoveries now (Q1), so the cost is latent.
- **Fix:** add `->limit(100)` with an order by `resolved_at`, and mark events with no open channel as complete in one bulk update.
- **Named test:** `AlertOperationsServiceRepositoryTest::test_pending_recoveries_are_bounded`.

## Checked and found OK (do not repeat)

- **Bot authorisation:** `TelegramBot::authorized()` (`:124-137`) requires the configured chat id, and in groups also the owner user id. Callbacks use the same check. Unauthorised updates are ignored and the offset still moves forward.
- **Bot output format:** replies are sent without `parse_mode`, so there is no HTML injection in bot text.
- **Template escaping:** `NotificationRenderer::replace()` escapes every value for the `telegram` and `openobserve` channels. `TelegramAlertNotifier::prepareMessage()` escapes plain text (`:129-139`), so the report path, which goes through `plain_report`, is escaped too.
- **Template validator:** unknown placeholders, unbalanced or non-allowed tags, and token-like text are all rejected (`NotificationTemplateValidator`).
- **Secrets in logs:** `TelegramAlertNotifier`, `TelegramBot` and `AlertChannelDispatcher` log a reason or an exception class, never the URL (the token is in the URL path) or the message.
- **Rendering fallback:** `NotificationRenderer::render()` falls back to the built-in template when the stored one is missing or broken (`:34-43`), so a DB problem does not stop an alert.
- **Alert dedupe:** `AlertEvaluationService` takes a cache lock, uses a minimum interval, and keeps a per-channel cooldown. The lock lease is computed from the work size (`lockLeaseSeconds`).
- **Report slot dedupe:** `PeriodicReportService::send()` takes a lock per slot and a reserved-slot key, and checks `snapshotExists()`. Production (Q5) shows 48 of 48 periodic reports sent in 4 days, no duplicates, no gaps.
- **Division by zero:** `AlertRuleEvaluator::percentage()` uses `max(1, $whole)`. Empty datasets yield 0%, not an error.
- **Backlog rule direction (intentional):** `backlog_depth` fires when a hot source has fewer than N due items. The template says so ("running out of due work"). The name `crawl.backlog_by_source_high` is misleading but the behaviour is intended. Production has 16 such events in one 10-minute window on 2026-11-02, all resolved.
- **Warning digest (intentional):** source-level warnings with no `escalation_minutes` go to the digest and are never sent live (`NotificationPolicyDispatcher::prepare()` `:65-70`). Production has two such alerts open with `notified_at` NULL. They are visible in the periodic report's `warnings` block (`ReportDataBuilder`).
- **Quiet mode and security:** `NotificationPolicyDispatcher` lets security events through quiet mode (`:49`, `:62`).
- **Config mapping for the report and alerts timezones:** settings keys map to config keys (`config/javmeta_settings.php`), so DB overrides reach `ReportRenderer` and `ReportSchedule`.

## Could not verify

- **Sentry:** not queried. The `sentry` MCP server failed to start in this session, and the Django-shell route was not used. Symptoms of 002, 003 and 004 may already be in Sentry as "telegram update failed" or scheduler issues.
- **Redis and the cache store in production:** `CACHE_STORE` in production is unknown (`.env.example` says `database`). This decides whether `withoutOverlapping()` locks are shared across the three nodes (BH-notify-004).
- **Production settings values:** `telegram.bot_enabled`, `report.timezone`, `report.daily_timezone`, `follow_notifications.*`. Not read (no ConfigStore access in this hunt). This decides how far BH-notify-003 and BH-notify-011 apply.
- **Live Telegram API:** the reproductions fake Telegram with `Http::fake`. The 400 for a refused message and the 4096-unit limit come from the API documentation, not from a live call.
- **EXPLAIN plans** for BH-notify-012 (production EXPLAIN not allowed; staging not available in this hunt).
- **Behaviour under three real nodes:** no concurrent run was tested.
- **Multi-node duplicate sends** for `telegram:poll` and `follows:notify` (both lack `onOneServer()`): reasoned, not tested.

## Production reads used (read-only, 8 of 60 allowed)

All queries ran with `SET statement_timeout=10000;`, on `xcrawler` at 192.168.10.12, through `ssh` and `sudo -u postgres psql -X -A -F'|' -t`. No errors were returned.

- Q1: `alert_events` grouped by rule kind (30 days): `source_success_rate 131/5 open/0 pending`, `backlog_depth 16/0/0`, `postgres_connections 15/1/0`, `success_rate 4/0/0`.
- Q2: open alerts list (ids 111, 114, 124, 151, 156, 166; ages up to 3 d 8 h; ids 114 and 151 never notified).
- Q3: last-15-min fetch attempts for the open sources: `moodyz 2 (2 ok)`, `premiumbeauty 1 (1 ok)`, the others 0.
- Q4: total fetch attempts in the last 15 min: 582.
- Q5: `report_snapshots` periodic rows (14-day window): 48 rows, 48 sent, 0 failed, 0 gaps over 125 min, first 2026-11-03 01:00 UTC, last 2026-11-06 23:00 UTC.
- Q6: row counts and index definitions: `movies` 309,986 rows (no index mentioning `display_code`); `performers` 156,702 rows (partial `lower()` indexes); `user_marks` and `follow_notifications` stats 0 rows; `alert_events` 166 rows.
- Q7: `user_marks` grouped by kind/subject/user: no rows.
- Q8: `user_marks` where `kind = 'tg_offset'`: 0 rows.

## Appendix A: scratch test `tests/Scratch/TelegramBotBugHuntTest.php` (deleted after the run)

Run: `docker/ci/run php vendor/bin/phpunit tests/Scratch/TelegramBotBugHuntTest.php`. Result: OK (2 tests, 5 assertions), with the output shown in BH-notify-002 and -003. The scratch test checks the bug, so it passes; the failing check is the poll result and the recorded requests.

```php
<?php

declare(strict_types=1);

namespace Tests\Scratch;

use App\Services\Notifications\TelegramBot;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** In-memory ConfigStore so SettingsService does not need the MongoDB config store (scratch only). */
final class MemoryConfigStore implements \JOOservices\LaravelConfig\Contracts\ConfigStore
{
    /** @var array<string, mixed> */
    public array $data = [];

    public function get(string $path, mixed $default = null): mixed { return $this->data[$path] ?? $default; }
    public function getString(string $path, ?string $default = null): ?string { return isset($this->data[$path]) ? (string) $this->data[$path] : $default; }
    public function getInt(string $path, ?int $default = null): ?int { return isset($this->data[$path]) ? (int) $this->data[$path] : $default; }
    public function getFloat(string $path, ?float $default = null): ?float { return isset($this->data[$path]) ? (float) $this->data[$path] : $default; }
    public function getBool(string $path, ?bool $default = null): ?bool { return isset($this->data[$path]) ? (bool) $this->data[$path] : $default; }
    public function getArray(string $path, ?array $default = null): ?array { return $this->data[$path] ?? $default; }
    public function fresh(string $path, mixed $default = null): mixed { return $this->get($path, $default); }
    public function set(string $path, mixed $value, ?string $type = null): void { $this->data[$path] = $value; }
    public function setMany(array $entries): void { foreach ($entries as $k => $v) { $this->data[$k] = $v; } }
    public function remember(string $path, mixed $default, ?string $type = null): mixed { return $this->data[$path] ??= $default; }
    public function has(string $path): bool { return array_key_exists($path, $this->data); }
    public function forget(string $path): bool { $had = $this->has($path); unset($this->data[$path]); return $had; }
    public function forgetMany(array $paths): int { $n = 0; foreach ($paths as $p) { $n += $this->forget($p) ? 1 : 0; } return $n; }
    public function clear(): int { $n = count($this->data); $this->data = []; return $n; }
    public function listPaths(): \Illuminate\Support\Collection { return collect(array_keys($this->data)); }
    public function listOrdered(): \Illuminate\Support\Collection { return collect($this->data); }
    public function group(string $group): array { return []; }
    public function all(): array { return $this->data; }
    public function refresh(): void {}
    public function ensureIndexes(): string { return 'memory'; }
}

/** SCRATCH bug-hunt reproduction (JOOXCR-414). Not for commit. */
final class TelegramBotBugHuntTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'laravel-notifications.channels.telegram.bot_token' => '<fake-token>',
            'laravel-notifications.channels.telegram.chat_id' => '42',
        ]);
        $this->app->instance(\JOOservices\LaravelConfig\Contracts\ConfigStore::class, new MemoryConfigStore());
        app(SettingsService::class)->set('telegram.bot_enabled', true);
    }

    /** BH-notify-003a: one update the Telegram API refuses blocks every later update (poison pill). */
    public function test_refused_reply_blocks_later_updates(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/getUpdates')) {
                return Http::response(['ok' => true, 'result' => [
                    ['update_id' => 10, 'message' => ['chat' => ['id' => 42, 'type' => 'private'], 'from' => ['id' => 42], 'text' => '/nosuch', 'date' => time()]],
                    ['update_id' => 11, 'message' => ['chat' => ['id' => 42, 'type' => 'private'], 'from' => ['id' => 42], 'text' => '/help', 'date' => time()]],
                ]]);
            }
            if (str_contains($request->url(), '/sendMessage')) {
                $text = (string) $request['text'];

                return str_contains($text, 'Lệnh không có')
                    ? Http::response(['ok' => false, 'description' => 'Bad Request'], 400)
                    : Http::response(['ok' => true, 'result' => []]);
            }

            return Http::response([], 404);
        });

        $first = app(TelegramBot::class)->poll();
        $second = app(TelegramBot::class)->poll();

        $answered = Http::recorded(fn(Request $r) => str_contains($r->url(), '/sendMessage') && str_contains((string) $r['text'], 'Gõ tên') || str_contains($r->url(), '/sendMessage') && str_contains((string) $r['text'], 'Lệnh không có'));
        fwrite(STDERR, 'SENDMESSAGE_ATTEMPTS=' . count($answered) . "\n");

        self::assertSame('telegram update failed', $first['skipped']);
        self::assertSame('telegram update failed', $second['skipped']);
        // Update 11 (/help) is never answered and the offset never moves: the bot is stuck on update 10.
        self::assertDatabaseMissing('user_marks', ['kind' => 'tg_offset']);
    }

    /** BH-notify-003b: no staleness check and no offset row: a 3-day-old command is still executed. */
    public function test_stale_update_is_answered_and_offset_starts_at_zero(): void
    {
        $old = time() - 3 * 86400;
        Http::fake([
            '*/getUpdates*' => Http::response(['ok' => true, 'result' => [
                ['update_id' => 500, 'message' => ['chat' => ['id' => 42, 'type' => 'private'], 'from' => ['id' => 42], 'text' => '/help', 'date' => $old]],
            ]]),
            '*/sendMessage*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $result = app(TelegramBot::class)->poll();

        Http::assertSent(fn(Request $r) => str_contains($r->url(), '/getUpdates') && (string) $r['offset'] === '1');
        self::assertSame(1, $result['handled'], 'a message dated 3 days ago was acted on');
    }
}
```

## Appendix B: scratch test `tests/Scratch/StuckAlertBugHuntTest.php` (deleted after the run)

Run: `docker/ci/run php vendor/bin/phpunit tests/Scratch/StuckAlertBugHuntTest.php`. Result: 1 failure (the bug), output in BH-notify-001.

```php
<?php

declare(strict_types=1);

namespace Tests\Scratch;

use App\Models\AlertEvent;
use App\Services\Alerts\AlertChannel;
use App\Services\Alerts\AlertChannelDispatcher;
use App\Services\Alerts\AlertDispatcher;
use App\Services\Alerts\AlertSnapshotReader;
use App\Contracts\AlertNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** SCRATCH bug-hunt reproduction (JOOXCR-414). Not for commit. */
final class StuckAlertBugHuntTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-02 10:00:00 UTC');
        Cache::flush();
        $sent = &$this->sent;
        $channel = new class ($sent) implements AlertChannel {
            /** @param list<string> $sent */
            public function __construct(private array &$sent) {}

            public function send(string $subject, string $body, array $context = []): bool
            {
                $this->sent[] = ($context['alert_state'] ?? '') . ':' . ($context['rule'] ?? '');

                return true;
            }
        };
        $this->app->instance(AlertChannelDispatcher::class, new AlertChannelDispatcher(['telegram' => $channel]));
        $this->app->instance(AlertDispatcher::class, new AlertDispatcher(
            $this->app->make(AlertNotifier::class),
            $this->app->make(AlertChannelDispatcher::class),
        ));
        config()->set([
            'javmeta_alerts.enabled' => true,
            'javmeta_alerts.channels' => ['telegram'],
            'javmeta_alerts.evaluate_interval_seconds' => 60,
            'javmeta_alerts.window_minutes' => 15,
            'javmeta_alerts.success_rate_min_percent' => 85,
            'javmeta_alerts.success_rate_critical_percent' => 70,
            'javmeta_alerts.blocked_ratio_max_percent' => 10,
            'javmeta_alerts.min_attempts_in_window' => 100,
            'javmeta_alerts.cooldown_minutes' => 30,
            'javmeta_alerts.recovery_notice' => true,
            'javmeta_alerts.recovery_retry_max_attempts' => 3,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** BH-notify-001: a source alert whose sample drops below the minimum is never resolved. */
    public function test_source_alert_stays_open_when_traffic_drops_below_minimum(): void
    {
        $this->useSnapshot(['javdb' => ['attempts' => 100, 'successes' => 50, 'blocked' => 0]]);
        Artisan::call('alerts:evaluate');
        self::assertSame(1, AlertEvent::query()->where('rule', 'source_success_rate:javdb')->whereNull('resolved_at')->count());

        // The source recovers: 2 of 2 attempts succeed, far below the 100-attempt minimum.
        CarbonImmutable::setTestNow('2026-11-02 10:30:00 UTC');
        $this->useSnapshot(['javdb' => ['attempts' => 2, 'successes' => 2, 'blocked' => 0]]);
        Artisan::call('alerts:evaluate');

        // Same for a source with no traffic at all.
        CarbonImmutable::setTestNow('2026-11-02 12:00:00 UTC');
        $this->useSnapshot(['javdb' => ['attempts' => 0, 'successes' => 0, 'blocked' => 0]]);
        Artisan::call('alerts:evaluate');

        $open = AlertEvent::query()->where('rule', 'source_success_rate:javdb')->whereNull('resolved_at')->count();
        fwrite(STDERR, "\nOPEN_AFTER_RECOVERY=" . $open . ' SENT=' . json_encode($this->sent) . "\n");
        self::assertSame(0, $open, 'alert should resolve once the source is healthy');
    }

    /** @param array<string, array{attempts: int, successes: int, blocked: int}> $attempts */
    private function useSnapshot(array $attempts): void
    {
        $snapshot = [
            'attempts_by_source' => $attempts,
            'enabled_sources' => array_keys($attempts),
            'hot_sources' => [],
            'due_by_source' => [],
            'postgres_connections' => null,
            'dispatch_age_seconds' => 0,
            'has_due_work' => false,
        ];
        $this->app->instance(AlertSnapshotReader::class, new class ($snapshot) implements AlertSnapshotReader {
            /** @param array<string, mixed> $snapshot */
            public function __construct(private array $snapshot) {}

            public function snapshot(CarbonImmutable $cutoff): array
            {
                return $this->snapshot;
            }
        });
    }
}
```

## Appendix C: production SQL (read-only, verbatim)

```sql
-- Q1
SET statement_timeout=10000; SELECT split_part(rule,':',1) AS rule_kind, count(*) AS events, count(*) FILTER (WHERE resolved_at IS NULL) AS still_open, count(*) FILTER (WHERE resolved_at IS NOT NULL AND recovery_completed_at IS NULL) AS recovery_pending, min(started_at) AS first_seen, max(started_at) AS last_seen FROM alert_events WHERE started_at > now() - interval '30 days' GROUP BY 1 ORDER BY 2 DESC LIMIT 50;
-- Q2
SET statement_timeout=10000; SELECT id, rule, severity, value, threshold, started_at, notified_at, resolved_at, (now()-started_at) AS age FROM alert_events WHERE resolved_at IS NULL ORDER BY started_at LIMIT 20;
-- Q3
SET statement_timeout=10000; SELECT source_slug, count(*) AS attempts_15m, count(*) FILTER (WHERE http_status BETWEEN 200 AND 299) AS ok_15m FROM crawl_fetch_attempts WHERE started_at > now() - interval '15 minutes' AND source_slug IN ('moodyz','javbus','fitch','xcity','premiumbeauty') AND outcome <> 'skipped' GROUP BY 1 ORDER BY 1 LIMIT 10;
-- Q4
SET statement_timeout=10000; SELECT count(*) AS attempts_15m_all FROM crawl_fetch_attempts WHERE started_at > now() - interval '15 minutes';
-- Q5
SET statement_timeout=10000; SELECT count(*) AS periodic_rows, count(*) FILTER (WHERE result='sent') AS sent, count(*) FILTER (WHERE result='failed') AS failed, count(*) FILTER (WHERE result='suppressed') AS suppressed, count(*) FILTER (WHERE scheduled_for IS NULL) AS manual, count(*) FILTER (WHERE gap > interval '125 minutes') AS gaps_over_125m, min(scheduled_for) AS first_at, max(scheduled_for) AS last_at FROM (SELECT scheduled_for, result, scheduled_for - lag(scheduled_for) OVER (ORDER BY scheduled_for) AS gap FROM report_snapshots WHERE report_type='periodic' AND created_at > now() - interval '14 days') t;
-- Q6
SET statement_timeout=10000; SELECT relname, n_live_tup, (SELECT string_agg(indexdef, ' ;; ') FROM pg_indexes i WHERE i.tablename = s.relname AND (indexdef ILIKE '%upper%' OR indexdef ILIKE '%lower%' OR indexdef ILIKE '%display_code%' OR indexdef ILIKE '%name_romaji%')) AS relevant_idx FROM pg_stat_user_tables s WHERE relname IN ('movies','performers','user_marks','follow_notifications','alert_events') LIMIT 10;
-- Q7
SET statement_timeout=10000; SELECT kind, subject, user_id, count(*) AS n, max(created_at) AS last_at FROM user_marks GROUP BY 1,2,3 ORDER BY 4 DESC LIMIT 20;
-- Q8
SET statement_timeout=10000; SELECT count(*) AS tg_offset_rows, max(key) AS offset_value FROM user_marks WHERE kind='tg_offset';
```

Results are summarised under "Production reads used" above.
