# Notifications and alerts

JAVMETA sends operational alerts, OpenObserve alerts, crawl summaries, follow
matches, and owner-bot replies. This page records each path and its delivery
behavior.

## Operational alert template

AlertDispatcher messages use one envelope:

```
[XCrawler][<LEVEL>] <title>
<body>
<context key>: <value>
```

The level follows the severity (INFO, NOTICE, WARNING, CRITICAL, default WARNING) and a
recovery is shown as RESOLVED. Plain messages read `[XCrawler] <title>`. The
Telegram notifier adds the `[XCrawler]` prefix and formats scalar context
fields. The same event and context are written to the application activity log.

OpenObserve uses the same `[XCrawler][WARNING]` or `[XCrawler][CRITICAL]` prefix,
followed by the rule name, metric stream, and matching rows. Follow
messages, hourly reports, and interactive bot replies keep their concise,
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
| Hourly catalog report | Movie and performer totals and change since the prior successful report | Telegram, hourly |
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

The Telegram follow feature sends directly so it can attach a cover image and
buttons. The owner bot replies directly so commands remain interactive.

## Queue behavior

Crawl, index, and telemetry jobs use Laravel/Horizon queues. Alert notifications
and other Telegram sends call the Telegram HTTP API synchronously from their
scheduler/listener or polling process. A slow Telegram request can lengthen
that process; the notifier uses a short HTTP timeout and logs skipped or failed
sends. These sends are not queue-backed.

## Capacity visibility

Application metrics now include due-source state and PostgreSQL connection
utilization. OpenObserve alerts when PostgreSQL connection use stays above 85%
for five minutes. WSL host CPU and RAM are not emitted by the application;
host-level alerts need a working Prometheus/node-exporter source or a host
metrics agent before thresholds can be trusted.
