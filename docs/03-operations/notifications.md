# Notifications and alerts

JAVMETA sends operational alerts, OpenObserve alerts, crawl summaries, follow
matches, and owner-bot replies. This page records each path and its delivery
behavior.

## Operational alert template

AlertDispatcher messages use one envelope:

```
[javmeta:<environment>] [ALERT][<severity>][<OPEN|RESOLVED>] <subject>
<body>
<context key>: <value>
```

Severity defaults to WARNING for an opened alert and INFO for a recovery. The
Telegram notifier adds the environment prefix and formats scalar context
fields. The same event and context are written to the application activity log.

OpenObserve uses the same project/environment prefix and an [ALERT][WARNING][OPEN]
marker, followed by the rule name, metric stream, and matching rows. Follow
messages, hourly reports, and interactive bot replies keep their concise,
product-specific text.

## Current notification types

| Notification | Trigger and cadence | Delivery |
| --- | --- | --- |
| Worker heartbeat stale/recovered | Heartbeat older than 10 minutes; watchdog every 5 minutes | Telegram + activity log |
| Source stale/recovered | Enabled source exceeded its manifest cycle plus one scheduler sweep, or the 24-hour fallback; watchdog every 5 minutes | Telegram + activity log |
| Crawl stalled/recovered | Due pending work and no source success for 30 minutes | Telegram + activity log |
| Claimed queue stuck/recovered | Claimed crawl item older than 30 minutes | Telegram + activity log |
| Due crawl backlog high/recovered | At least JAVMETA_WATCHDOG_PENDING_QUEUE_LIMIT due pending rows; default 1,000 | Telegram + activity log |
| Backup missing/stale/recovered | Manifest missing or older than 26 hours; checked every minute, one message per cause per hour | Telegram + activity log |
| Consumer lookup miss | Query not in the catalogue; per normalized query, one message per hour | Telegram + activity log |
| Source quality alert | Source stale, soft-404, or repeated title-needs-review signals | Telegram + activity log |
| OpenObserve rules | API error rate >=5%, API p95 >1.5s, dependency down, claimed age >30m, >20 source failures/hour, schedule-aware source stale, PostgreSQL connections >=85% for 5m | Telegram destination; rule-specific silence windows |
| Staging readiness | New or changed failing checks; checked hourly; an explicit READY message follows a failure | Telegram |
| Source health | Newly blocked and recovered sources; checked daily at 04:00 | Telegram |
| Hourly catalog report | Movie and performer totals and change since the prior successful report | Telegram, hourly |
| Follow matches | New movie for a followed performer, maker, or series; honors quiet hours and daily cap | Telegram, every 5 minutes |
| Follow feed | Recent movies matching the owner’s follows, plus new-since-last-visit count | XCrawler UI |
| Owner Telegram bot | Search, movie details, status, follows, and optional downloads | Long polling every minute; owner chat only |

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
