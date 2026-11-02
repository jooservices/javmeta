# Notifications and alerts

JAVMETA sends operational alerts, OpenObserve alerts, crawl summaries, follow
matches, and owner-bot replies. This page records each path and its delivery
behavior.

## Database templates

The `notification_templates` table contains one row for each key declared by
`NotificationRegistry`. The registry is the source for safe English defaults,
Vietnamese names and usage descriptions, allowed variables, sample values and
initial enablement. The additive migration seeds missing rows only; later runs
never overwrite edits. `notification_template_revisions` records the previous
text, enabled state, severity, version, editor and timestamp before a change.

The owner-only **Cài đặt → Thông báo** page lists channel, enabled state, last
successful delivery and last edit. It supports editing, sample preview, Telegram
test sends, reset to the registry default and revision restore. All routes are
login-required and listed in the [guest route matrix](guest-mode.md).

Each template row also stores the notification policy: enabled state, tier,
cooldown, escalation delay, periodic/daily report inclusion, and resolved
delivery. The policy page displays these controls in Vietnamese; message
templates remain English. The `NotificationRegistry` is the default source for
both templates and policy rows, and the completeness test fails if a registered
notification has no policy row. Policy changes and restores are revisioned.
The global **Chế độ yên lặng** setting allows only critical notifications and
security events through; other eligible events remain in report digests.

Critical messages send immediately and are limited to one per cause per
configured cooldown. A resolved message is sent only when the same cause had a
critical delivery and its policy enables resolved messages. Warning messages
are digest-only; an open warning escalates once its configured age is reached.
Notice messages send immediately once per event, with a separate event
cooldown. Info and warnings appear in enabled periodic/daily reports only.
The notification page previews the next periodic and daily report.

Run `php artisan notifications:metrics` over SSH to inspect cumulative policy
delivery and suppression counters. `obs:publish-metrics` publishes the same
counters to OpenObserve.

Templates replace only registered `{variable}` placeholders. Saves reject
unknown/malformed variables and token-shaped secrets; Telegram markup is limited
to `b`, `i`, `u`, `s`, `code` and `pre` without attributes. Telegram variables
are HTML-escaped. Missing, disabled or invalid database templates fall back to
the registry text so a template problem cannot stop an alert. Interactive bot
replies remain code-driven.

`php artisan notifications:list` lists registered templates without writing.
`php artisan notifications:render <key> --sample` renders registry sample data;
without `--sample`, it prints the active template text and placeholders.

`observability:import` (alias `obs:import-alerts`) synchronizes the database
warning and critical OpenObserve templates whenever it is run. The OpenObserve
template placeholders are translated to its native `{{.field}}` syntax. Editing
an OpenObserve template therefore takes effect after the next import.

OpenObserve uses the same `[XCrawler][WARNING]` or `[XCrawler][CRITICAL]` prefix,
followed by the rule name, metric stream, and matching rows. Follow
messages, periodic reports, and interactive bot replies keep their concise,
product-specific text.

## Current notification types

| Notification | Trigger and cadence | Delivery |
| --- | --- | --- |
| Worker heartbeat stale/recovered | Heartbeat older than 10 minutes; watchdog every 5 minutes | Telegram + activity log |
| Source stale/recovered | Enabled source exceeded its manifest cycle plus one scheduler sweep, or the 24-hour fallback; watchdog every 5 minutes | Telegram + activity log |
| Crawl stalled/recovered | Due pending work and no source success for 30 minutes | Telegram + activity log |
| Claimed queue stuck/recovered | Claimed crawl item older than 30 minutes | Telegram + activity log |
| Due crawl backlog high/recovered | At least `cleanup.pending_queue_limit` due pending rows; default 1,000 | Telegram + activity log |
| Crawl dispatch delayed | Due crawl rows remain pending without a recent feed-pool dispatch; warning after `alerts.crawl_dispatch_warning_seconds` (60 seconds), critical after `alerts.crawl_dispatch_critical_seconds` (180 seconds); checked every minute | OpenObserve alert rules; separate warning and critical Telegram labels |
| Backup missing/stale/recovered | Manifest missing or older than 26 hours; checked every minute, one message per cause per hour | Telegram + activity log |
| Consumer lookup miss | Query not in the catalogue; per normalized query, one message per hour | Telegram + activity log |
| Source quality alert | Source stale, soft-404, or repeated title-needs-review signals | Telegram + activity log |
| OpenObserve rules | API error rate >=5%, API p95 >1.5s, dependency down, claimed age >30m, >20 source failures/hour, schedule-aware source stale, crawl dispatch warning/critical, PostgreSQL connections >=85% for 5m | Telegram destination; rule-specific silence windows |
| Staging readiness | New or changed failing checks; checked hourly; an explicit READY message follows a failure | Telegram |
| Source health | Newly blocked and recovered sources; checked daily at 04:00 | Telegram |
| Periodic status report | English XCrawler layout with separate movie/performer crawl ratios, catalogue speed, source status, worker and server samples, and service health | Telegram, every two hours by default |
| Follow matches | New movie for a followed performer, maker, or series; honors quiet hours and daily cap | Telegram, every 5 minutes |
| Follow feed | Recent movies matching the owner’s follows, plus new-since-last-visit count | XCrawler UI |
| Owner Telegram bot | Search, movie details, status, follows, and optional downloads | Long polling every minute; owner chat only |

## Crawl safety alerts

`alerts:evaluate` is scheduled every minute and applies the database settings
in Cài đặt → Cảnh báo. `alerts.evaluate_interval_seconds` is at least 60 and
can reduce evaluation frequency without changing the scheduler cadence. The
rolling window defaults to 15 minutes. `crawl_fetch_attempts` rows with
`outcome=skipped` are excluded; HTTP 2xx is success, and 403, 429 or a
Cloudflare mitigation marker counts as blocked. Rate rules require at least
100 attempts by default. Per-source rate checks cover enabled sources.

Rules cover overall and per-source success rate, blocked ratio, PostgreSQL
client connections, dispatch age while due work exists, and due backlog per
hot source. A source is hot when enabled and it has a recent success or fetch
attempt in the evaluation window. Backlog alerts fire below the configured
due-row floor, including zero. When the overall success rule and one or more
source rules fire together, the overall notice lists the three lowest-rate
sources and suppresses duplicate source notices for that evaluation.

Open conditions are stored in `alert_events`; one row represents a firing
episode, with its start, latest value/threshold, channel delivery state and
resolution time. Each channel retries an undelivered open notice after
`alerts.cooldown_minutes` (30 by default), without resending to channels that
already accepted that attempt. A recovery goes only to channels that received
the open notice. Failed recoveries retry at the same cooldown, up to
`alerts.recovery_retry_max_attempts` (3 by default). Resolved history is pruned
after `alerts.history_retention_days` (90 by default); active episodes remain.

Run `php artisan alerts:metrics` over SSH to inspect current firing gauges and
retained event counts. `obs:publish-metrics` exports the same points to the
configured telemetry stream.

Enabled channels default to Telegram. `AlertDispatcher` routes messages to
each selected channel and catches channel failures independently, logging only
the channel name and exception class. The webhook channel posts JSON with
`subject`, `message` and safe scalar `context` to the HTTPS address in
`alerts.webhook_url`; credentials belong in runtime secrets. Telegram bot
tokens and chat IDs also stay in runtime secrets and are not exposed in the
Settings page or logs. Use `php artisan alerts:test` to check enabled channels
and `php artisan alerts:list --hours=24` to inspect history.

## Safety evaluator notification types

| Notification | Trigger | Default |
| --- | --- | --- |
| Crawl success rate | Overall 2xx ratio below configured minimum, with enough attempts | Below 85% in 15 minutes and at least 100 attempts |
| Per-source success rate | Enabled source falls below the same minimum with enough attempts | Below 85% |
| Blocked ratio | 403, 429 or Cloudflare-marked attempts exceed configured maximum | Above 10% |
| PostgreSQL connections | Client connections exceed configured maximum | Above 45 |
| Dispatch age | Due work exists without a recent feed-pool dispatch | Above 60 seconds |
| Due backlog per hot source | Due pending rows fall below the configured source floor | Below 10 rows |

The safety evaluator rules use the same database templates as the operational
alert senders. It keeps the configured evaluation, cooldown and channel retry
behavior while rendering the message text from the registry-backed template.

## Registry-only event types

The rows below have safe English defaults and remain disabled until their
producer is connected. Their stated triggers are the contract for later wiring.

| Key | Trigger contract | Default |
| --- | --- | --- |
| `deploy.success`, `deploy.rolled_back` | Deployment completes on all nodes, or the deployment rollback runs. | Off |
| `node.joined`, `node.lost` | A node reports its first healthy heartbeat, or misses its heartbeat window. | Off |
| `horizon.master_missing`, `horizon.supervisor_missing` | Required Horizon master or supervisor is absent. | Off |
| `queue.failed_jobs_spike`, `database.deadlock_burst`, `queue.retry_permanent_failures` | Failed jobs, deadlocks or permanent retry-channel failures exceed their configured trigger. | Off |
| `network.egress_ip_changed`, `network.egress_ip_shared` | A node's egress address changes, or multiple nodes share one address. | Off |
| `browser.host_saturated`, `browser.old_processes`, `flaresolverr.down` | Browser utilization/process age crosses a limit, or FlareSolverr health fails. | Off |
| `sentry.new_critical_group` | Sentry opens a new critical issue group. | Off |
| `source.exhausted` | Three listing passes produce no new items while due work is nearly empty. | Off |
| `catalog.daily_summary` | Daily catalog, crawl, source and worker totals are ready. | Off |
| `backup.success`, `backup.restore_test` | Backup completes, or a restore verification finishes. | Off |
| `security.api_key_created`, `security.api_key_revoked`, `security.login_failures`, `security.guest_mode_toggled`, `security.settings_changed` | The corresponding owner action or login-failure threshold occurs. | Off |
| `startup_gate.blocked` | A required dependency blocks service startup. | Off |

The Telegram follow feature sends directly so it can attach a cover image and
buttons. The owner bot replies directly so commands remain interactive.

## Periodic and daily status reports

The main-node scheduler runs `report:send` every minute. It sends only on the
configured interval grid (default: even hours in `America/Mexico_City`), skips
quiet-hour slots without a catch-up, and uses a cache lock plus a unique
scheduled-slot key to prevent duplicates. `report:send --now` and the owner-only
**Gửi ngay qua Telegram** action send once without moving the grid. The legacy
`javmeta:hourly-report` command remains a one-time alias; `--dry-run` prints a
live preview. When periodic reports are enabled, the legacy hourly scheduler is
disabled; its separate compatibility switch defaults off.

The daily scheduler runs `report:send-daily` once at `report.daily_time`
(default `08:00`) in `report.daily_timezone` (default
`America/Mexico_City`). It summarizes the preceding 24 hours in English,
including separate movie and performer counts, crawl speed and success, blocked
and exhausted sources, failed jobs after retries, nodes, completed deploys,
backup status, and open warnings. `report:send-daily --now` sends immediately.
Both report schedules and the daily local time/zone are editable in Settings.
The periodic report includes open warnings with their start time, current value,
and threshold.

`report_snapshots` stores rendered text, raw numbers, report type, the slot,
channel, result and timestamp. Retention is controlled by `report.history_days` (default 30
days); the UI shows the latest 20. The report template is editable under
Settings → Báo cáo. Report settings, including the sections, interval, timezone,
quiet hours and top-source limit, are database-backed. See
[`telemetry.md`](telemetry.md) for status, node and worker metrics.

## Queue behavior

Crawl, index, and telemetry jobs use Laravel/Horizon queues. Alert notifications
and other Telegram sends call the Telegram HTTP API synchronously from their
scheduler/listener or polling process. A slow Telegram request can lengthen
that process; the notifier uses a short HTTP timeout and logs skipped or failed
sends. These sends are not queue-backed.

## Capacity visibility

Application metrics include due-source state, PostgreSQL connection
utilization, report delivery, per-node CPU/memory/disk, and per-lane worker
counts. OpenObserve alerts when PostgreSQL connection use stays above 85% for
five minutes. Node samples come from the application every minute and stay in
shared cache for three hours; worker nodes use the installed node-sample timer.
Browser-host details are shown only when its health endpoint or optional stats
endpoint supplies them.
