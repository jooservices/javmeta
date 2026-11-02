# JAVMETA telemetry catalogue

This catalogue is derived from `OpenObserveClient`, `PublishMetricsCommand`,
`BackfillEmbeddingsCommand`, `WatchdogCommand`, and `AlertDispatcher`. Metric names and the `javmeta_logs`
stream are existing contracts; dashboards and alerts must use them as written.

## Metrics

`PublishMetricsCommand` sends OTLP metrics to `/api/{org}/v1/metrics`. OpenObserve
stores the named metric families as metrics streams; each stream below has the
same name as the emitted metric. Metric attributes are stored as labels/fields.
Every metric point and structured log record carries `environment` from
`APP_ENV` and `role` from `JAVMETA_ROLE`; set `APP_ENV=staging` on staging
runtimes to label their telemetry `environment=staging`. OpenObserve credentials
are read only from `OPENOBSERVE_EMAIL` and `OPENOBSERVE_PASSWORD` in the runtime
environment.

| Metric stream | Labels | Emitted data |
| --- | --- | --- |
| `javmeta_source_gap_seconds` | `source_slug`, `gap_source` | Current adaptive source gap in seconds, clamped to active override bounds. `gap_source` is `override` or `definition`. |
| `javmeta_source_gap_effective_seconds` | `source_slug`, `gap_source` | Effective default gap in seconds after applying `crawl.source_gap_overrides`; compare with the adaptive gauge to see recovery or backoff. |
| `javmeta_source_last_success_age_seconds` | `source_slug` | Age in seconds since the source's last successful crawl; `-1` means no success is recorded. |
| `javmeta_source_past_due` | `source_slug` | 1 when an enabled source exceeded its manifest cycle plus one scheduler sweep, or global fallback; otherwise 0. |
| `javmeta_crawl_queue_depth` | `status` | Current queue rows by state. |
| `javmeta_crawl_queue_claimed_age_seconds` | — | Age in seconds of the oldest claimed queue row; `-1` means none is claimed. |
| `javmeta_crawl_dispatch_age_seconds` | — | Seconds since the feed pool last dispatched a crawl job; `-1` means no dispatch is recorded. |
| `javmeta_crawl_dispatch_stale` | `severity` | 1 when due `pending` or `retry_pending` work has exceeded the warning or critical dispatch-age threshold; 0 otherwise. Future retry rows do not count as due. Thresholds come from database settings. |
| `javmeta_crawl_queue_avg_attempts` | — | Average attempt count for `pending`, `retry_pending` and `failed` queue rows. |
| `javmeta_retry_pending` | `source`, `round`, `egress` | Current retry-lane rows grouped by retry round and safe egress label; value is rows, including rows waiting for their next due time. |
| `javmeta_retry_success_total` | `source`, `round`, `egress` | Cumulative completed retry-lane rows, grouped by successful round and egress; value is rows. A counter advances only after the queue row is saved as `done`. |
| `javmeta_retry_failed_permanent_total` | `source`, `round`, `egress` | Cumulative retry-lane rows that exhausted their rounds or reached a terminal error; value is rows. |
| `javmeta_feed_source_budget` | `source_slug` | Per-source budget from the latest feed run in the previous minute: `max(1, floor(horizon / gap))`; the one-second minimum applies when no override is active. |
| `javmeta_feed_source_started` | `source_slug` | Per-source requests started in the pacing horizon, from the latest feed run in the previous minute. |
| `javmeta_feed_source_waiting` | `source_slug` | Per-source claimed rows without a start time, from the latest feed run in the previous minute. |
| `javmeta_feed_source_dispatched_1m` | `source_slug` | Per-source rows dispatched by feed-pool runs in the previous minute. |
| `javmeta_feed_stop_reason_1m` | `queue`, `lane`, `reason` | Number of runs stopped at each queue gate in the previous minute. |
| `javmeta_feed_run_ms` | — | Average feed-pool run duration in milliseconds for the previous minute. |
| `javmeta_feed_claim_rows_examined_1m` | — | Rows found by claim selection or a source-gate check in the previous minute (up to one per `claimNext` call); it does not estimate database engine scans. |
| `javmeta_crawl_events_5m` | `kind` | Crawl events created in the previous five minutes. |
| `javmeta_fetch_method_attempts` | `source`, `method`, `outcome` | Fetch method attempts completed and recorded in the previous minute. |
| `javmeta_fetch_method_preference` | `source`, `method` | Current browser-method preference by source (`1` preferred, `0` otherwise); a source is preferred when any observed host has an active FlareSolverr preference. |
| `javmeta_playwright_slot_busy_ratio` | — | Estimated Playwright-family slot occupancy over the previous minute, capped at 1.0. |
| `javmeta_flaresolverr_slot_busy_ratio` | — | Estimated FlareSolverr slot occupancy over the previous minute, capped at 1.0. |
| `javmeta_stats_movies_per_hour` | `hour` | New plus updated movies stored for that UTC hour (movies/hour). |
| `javmeta_stats_source_success_ratio` | `hour`, `source` | Successful non-skipped fetch attempts divided by all non-skipped attempts for that source and UTC hour (0–1). |
| `javmeta_api_requests_1m` | — | API requests recorded in the previous minute. |
| `javmeta_api_error_rate_1m` | — | Fraction of API requests with a 4xx/5xx response in the previous minute. |
| `javmeta_api_p50_ms_1m` | — | API response latency p50 in milliseconds. |
| `javmeta_api_p95_ms_1m` | — | API response latency p95 in milliseconds. |
| `javmeta_api_429_1m` | — | Rate-limited API requests in the previous minute. |
| `javmeta_api_requests_by_endpoint_1m` | `endpoint` | Request count by endpoint. |
| `javmeta_api_p95_ms_by_endpoint_1m` | `endpoint` | API latency p95 by endpoint in milliseconds. |
| `javmeta_movies_needs_review` | — | Movie records marked for review. |
| `javmeta_review_flags_open` | — | Unresolved review flags. |
| `javmeta_movies_completeness_tier` | `tier` | Movie count by completeness tier. |
| `javmeta_observation_conflict_rate` | `field` | Fraction of observed movie fields with conflicting values. |
| `javmeta_crawl_run_pages_fetched_1h` | `source_slug` | Pages fetched in the previous hour by source. |
| `javmeta_crawl_run_movies_new_1h` | `source_slug` | New movies in the previous hour by source. |
| `javmeta_crawl_run_movies_updated_1h` | `source_slug` | Updated movies in the previous hour by source. |
| `javmeta_crawl_run_failures_1h` | `source_slug` | Crawl failures in the previous hour by source. |
| `javmeta_crawl_run_proxy_requests_1h` | `source_slug` | Proxy requests in the previous hour by source. |
| `javmeta_crawl_run_proxy_bytes_1h` | `source_slug` | Proxy bytes in the previous hour by source. |
| `javmeta_crawl_run_browser_fetches_1h` | `source_slug` | Browser fetches in the previous hour by source. |
| `javmeta_movies_total` | — | Movie records in the catalogue. |
| `javmeta_performers_total` | — | Performer records in the catalogue. |
| `javmeta_observations_1d` | — | Movie observations from the previous day. |
| `javmeta_failed_jobs` | — | Failed queue jobs. |
| `javmeta_failed_jobs_1h` | — | Queue jobs that failed in the previous hour. |
| `javmeta_report_sent_total` | `result` | Cumulative periodic-report sends (`sent` or `failed`) since the shared-cache counter was created. |
| `javmeta_report_last_sent_timestamp_seconds` | — | Unix timestamp, in seconds, of the latest successful Telegram report; `0` means none has succeeded yet. |
| `javmeta_node_cpu_percent` | `node` | Latest per-node CPU utilization in percent, sampled from `/proc/stat` deltas. |
| `javmeta_node_mem_available_bytes` | `node` | Latest per-node available memory in bytes from `/proc/meminfo`. |
| `javmeta_node_disk_used_percent` | `node` | Latest used space percent on the configured application volume. |
| `javmeta_workers` | `node`, `lane`, `state` | Current Horizon worker process count; `lane` is `direct` or `browser`, `state` is `busy`, `idle` or `total`, unit is worker processes. |
| `javmeta_dep_up` | `dependency` | Availability of PostgreSQL, Elasticsearch, OpenObserve, and MongoDB (`1` up, `0` down). |
| `javmeta_postgres_connections_used` | - | Active PostgreSQL sessions across the instance. |
| `javmeta_postgres_connections_max` | - | Configured PostgreSQL max_connections. |
| `javmeta_postgres_connection_utilization` | - | Active sessions divided by max_connections; alert threshold is 85% sustained for five minutes. |
| `javmeta_alert_firing` | `rule` | Unitless 0/1 gauge while a rule is firing. Source rules use `source_success_rate:<slug>` and `backlog_depth:<slug>`. |
| `javmeta_alert_event_count` | `state`, `severity` | Episode count, split into `firing` or `resolved` and `warning`, `critical` or `info`. Retention follows `alerts.history_retention_days`. |
| `javmeta_mongo_archive_write_failures` | — | Cumulative count of failed Mongo archive writes since the cache counter was created. |
| `javmeta_elasticsearch_request_failures` | — | Cumulative count of failed Elasticsearch requests since the cache counter was created. |
| `javmeta_search_embedding_backfill_missing` | `entity` | Movie or performer documents found missing from Elasticsearch or without an embedding during the latest backfill run. |
| `javmeta_search_embedding_backfill_fixed` | `entity` | Movie or performer documents successfully indexed with an embedding during the latest backfill run. |
| `javmeta_notification_sent_total` | `notification_key`, `channel` | Cumulative count of successful sends for each registered template. Unit: messages. Latest gauge is the total since the template row was created; use a difference between samples for sends during an interval. `environment` and `role` are attached by the shared metrics client. |
| `javmeta_notifications_sent_total` | `tier`, `key` | Cumulative policy-approved messages sent, grouped by delivery tier and notification key. Unit: messages. |
| `javmeta_notifications_suppressed_total` | `reason` | Cumulative notifications suppressed by policy, grouped by reason such as `warning_digest`, `cooldown`, `quiet_mode`, `duplicate_event`, or `digest_only`. Unit: messages. |

Policy counters are persisted in `notification_policy_metric_counters` and
published by `obs:publish-metrics`. To inspect current totals over SSH, run
`php artisan notifications:metrics`; compare samples to measure change over a
time window. The policy is working as intended when warning-only causes do not
increase `javmeta_notifications_sent_total` before escalation, a persistent
warning adds one critical send at its escalation age, and cooldown/digest
suppression reasons increase without duplicate sends. `environment` and `role`
are attached by the shared metrics client.

The seven `javmeta_crawl_run_*_1h` streams are emitted from a fixed suffix list
in `PublishMetricsCommand`; no other dynamic metric names are generated.

`javmeta_notification_sent_total` is a cumulative gauge snapshot emitted by
`obs:publish-metrics` from `notification_templates.sent_count`. Each point has
`notification_key` and `channel` labels in addition to the shared `environment`
and `role` labels. Its unit is successfully delivered messages. Group by key and
channel, then compare the latest value with an earlier sample to count sends in
that interval. Failed or skipped Telegram delivery does not increment it; a key
with no point yet has had no successful delivery.

Feed-pool metrics are emitted by `obs:publish-metrics` from the previous
complete minute's Redis bucket. Budget, started and waiting are the latest
per-source snapshot in that minute; dispatched and stop reasons are summed for
the minute. `javmeta_feed_run_ms` is the average duration across runs in that
minute. Buckets use the database-configured diagnostics retention and TTL. The
application has no Prometheus text endpoint; these metrics use the existing
OpenObserve OTLP metrics path.

Retry metrics are emitted by `obs:publish-metrics`. Pending is a point-in-time
gauge; the two `_total` metrics are cumulative counters stored in
`crawl_retry_metrics`. The `egress` label contains only the configured label,
never a proxy URL. A falling `javmeta_retry_pending` gauge with a rising
`javmeta_retry_success_total` is the expected recovery signal; a rising
`javmeta_retry_failed_permanent_total` indicates the configured rounds did not
recover the source pages. Read counters over SSH with
`php artisan crawler:retry-stats [--source=slug]`; inspect row-level state with
`php artisan crawler:retry-list [--source=slug] [--round=1]`.

For a due retry row, `javmeta_crawl_dispatch_stale{severity="warning"}` or
`{severity="critical"}` should become `1` when the feed-pool heartbeat exceeds
that database threshold. `javmeta_crawl_queue_avg_attempts` includes retry-lane
rows so attempt load remains visible while they wait or run.

`javmeta_playwright_slot_busy_ratio` divides the completed Playwright-family
durations recorded in the previous minute by `queue.browser_inflight` slots and
60 seconds. The attempt counter is a one-minute gauge grouped by source, method
and outcome. Preference points use source labels only; per-host state stays in
the shared cache to keep metric cardinality bounded.

`node:sample` samples CPU deltas, load, available memory, application-volume
usage and the local Horizon process list each minute. The primary runs it from
the scheduler; each worker runs the installed node-sample systemd timer. Samples
and busy markers are stored in shared cache with a three-hour sample lifetime
and expiring job markers. `JobProcessing` marks a PID busy; `JobProcessed` and
`JobFailed` clear it. `obs:publish-metrics` reads the shared samples and emits
the latest values. `php artisan node:sample` prints a node's current sample;
`php artisan javmeta:hourly-report --dry-run` renders a live report without
sending or contacting OpenObserve. Browser-host CPU/RAM/disk and Chrome counts
require the optional `report.browser_stats_url`; health probes still report
service availability if those host stats are not configured.

The `kind=queue_reclaimed_without_start` series on `javmeta_crawl_events_5m`
counts stale queue claims returned to pending before a worker started them.

`crawler:stats-rollup` also exports hourly values for the three most recent
hour buckets. The success ratio excludes skipped attempts. The hourly movie
metric counts both new and updated movies. The minute-level FlareSolverr busy
ratio uses the same configured browser slot count as the Playwright estimate.

## Logs and traces

| Signal | OpenObserve endpoint / stream | Source |
| --- | --- | --- |
| Structured application logs | JSON ingestion into `javmeta_logs` (`OPENOBSERVE_STREAM_LOGS`); each record includes `environment` and `role` | `OpenObserveLogHandler`, `ObservabilityEmitter`, and `EmitObservabilityLog`. |
| Application metrics | OTLP `/api/{org}/v1/metrics`; each point includes `environment` and `role` labels | `PublishMetricsCommand`, normally run by the scheduler. |
| HTTP and crawl spans | OTLP `/api/{org}/v1/traces` | `TraceHttpRequest` and `TraceCrawlJob`. |
| Watchdog notifications | Application alert events, not a separate metric stream | `WatchdogCommand` dispatches `WorkerHeartbeatStale` and `CrawlSourceUnhealthy`; listeners use `AlertDispatcher` for Telegram/activity-log fan-out. |

The crawl dispatch age and severity series are exported by the existing
`obs:publish-metrics` command. The stale series is active only while due crawl
rows are pending, so an idle queue does not page. A missing dispatch timestamp
is treated as stale when due work exists. OpenObserve warning and critical
rules evaluate only the newest sample in their bounded five-minute window every
minute, so a stale sample cannot keep an alert active after recovery. The
critical rule uses a separate Telegram destination with a `[CRITICAL]` label.
Configure OpenObserve age thresholds with
`alerts.crawl_dispatch_warning_seconds` and
`alerts.crawl_dispatch_critical_seconds` on the Settings page.

The scheduled safety evaluator publishes alert state and history counts with
`obs:publish-metrics`. `javmeta_alert_firing{rule}` is a 0/1 gauge for overall
success rate, blocked ratio, PostgreSQL connections, dispatch age and each
enabled source's success/backlog rules. `javmeta_alert_event_count{state,
severity}` counts retained event episodes. Safety thresholds are database
settings; `alerts:evaluate` runs every minute and uses the configured rolling
window and cooldown. Inspect current values and labels over SSH with
`php artisan alerts:metrics`, compare them before and after a threshold change,
and use `php artisan alerts:list --hours=24` to confirm episode history and
recovery.

### Natural-language search parse

The api_usage record for endpoint /api/v1/search/parse is emitted through the
existing asynchronous observability queue. Parse events add only bounded
fields: parse_outcome, parse_cache_status, parse_backend,
parse_fallback_reason, parse_duration_ms, ollama_chat_outcome,
ollama_chat_duration_ms, and the Ollama HTTP status when available. The
Ollama outcome is one of not_attempted, success, http_error, timeout,
transport_error, client_error, invalid_json, budget_exhausted, or cache_hit.

The parser event never includes the question, prompt, model output, request or
response body, credentials, cookies, or raw exception text. Cache-hit events
have no Ollama duration because that request did not call Ollama. Existing
javmeta_api_p95_ms_by_endpoint_1m measures the whole endpoint across cache
states; the parse dashboard derives cache-specific percentiles from safe
javmeta_logs events.

deploy/observability/dashboards/search-parse.json is imported by
php artisan observability:import. It shows request rate, cache hit ratio, p95 parse latency
split by cache status, parser backend distribution, Ollama outcomes and p95
latency, and bounded fallback reasons. The
javmeta_search_parse_degraded alert requires at least three slow parse requests
(cached or uncached) or failed Ollama calls within its five-minute evaluation
window, so one-off errors and empty or low-volume windows do not notify.

### Telegram alerts

Telegram settings come from `NOTIFICATION_TELEGRAM_*`; with no token nothing is
sent and nothing fails. Operational alerts use
`[XCrawler][<LEVEL>]` and name the
source where one applies. Each cause alerts at most once per
`JAVMETA_ALERT_THROTTLE_SECONDS` (default 3600) and sends one recovery message
when it clears. Follow messages and bot replies use product-specific templates.


| Cause | Rule | Run by |
| --- | --- | --- |
| Dead source | Enabled source without success past `JAVMETA_WATCHDOG_SOURCE_STALE_SECONDS`; manifests with a `cycle_delay_seconds` get that cycle plus one fresh-listing sweep | `crawler:watchdog` |
| Stalled crawl | Due pending work but no source success within `JAVMETA_WATCHDOG_CRAWL_STALL_SECONDS` | `crawler:watchdog` |
| Stuck queue | Claimed items older than `JAVMETA_WATCHDOG_QUEUE_STUCK_SECONDS` | `crawler:watchdog` |
| Due pending backlog | Due pending items reach `JAVMETA_WATCHDOG_PENDING_QUEUE_LIMIT` (default 1,000); a recovery message follows below the limit | `crawler:watchdog` |
| Old backup | Last backup older than `backup.stale_after_seconds` (26 hours) or missing | `javmeta:backup-status` |

`OPENOBSERVE_STREAM_METRICS` is retained in configuration for compatibility;
the current metrics client sends OTLP metric names and does not route them into
one shared `javmeta_metrics` stream.

## Staging manual check

Configure the API, worker, and scheduler runtimes with `APP_ENV=staging` and
`JAVMETA_ROLE=api`, `JAVMETA_ROLE=worker`, and `JAVMETA_ROLE=scheduler`,
respectively. Set `OPENOBSERVE_ENABLED=true` and `LOG_STACK=single,openobserve`
in each runtime. Use the same environment-provided values for
`OPENOBSERVE_URL`, `OPENOBSERVE_ORG`, `OPENOBSERVE_EMAIL`, and
`OPENOBSERVE_PASSWORD` in all three. Keep credentials in the
deployment secret store; do not copy them into commands, source files, or
verification notes. The scheduler publishes metrics every minute.

After the three runtimes have handled normal requests/jobs and the scheduler
has published at least once, use OpenObserve in the configured organisation:

1. In `javmeta_logs`, query for records with `environment = 'staging'` and
   check that the `role` field includes `api` and `worker`.
2. In the `javmeta_source_last_success_age_seconds` metric stream, check for
   `environment = 'staging'`, `role = 'scheduler'`, and `source_slug` labels.
3. Run `php artisan observability:import` twice in the staging runtime. Confirm
   that the `JAVMETA Operations` dashboard still has queue depth, crawl errors
   by source and code, source freshness, and pages fetched by source in the
   previous hour, and that alert and dashboard counts do not grow on the second
   run.

The importer writes to `OPENOBSERVE_ORG`, so use the approved shared staging
organisation for this manual check. The importer also updates its Telegram
destination from environment-backed notification settings.


## What is sent for debugging (JOOXCR-235)

Everything needed to debug a crawl goes to OpenObserve, stream `javmeta_logs`:

- `crawl_fetch` (every fetch, success or failure): `source_slug`, `crawl_type`, `ok`, `duration_ms`, `error_code`,
  `error_message`, `page_url` (scheme, host and path only), `http_status` and `method` of the winning or last attempt,
  `retryable`, `retry_after_seconds`, `attempt_count`, the attempts list (method, elapsed, status, challenge, ok) and
  `crawlerx_version`.
- `crawl_event` (blocked, challenge, parse drift, soft 404, rate limit, source toggled...): `kind`, `source_slug`, and in
  `detail` the reason, `page_url` and `error_message`.
- Every ERROR/WARNING log record with an exception: `exception_class`, `exception_message`, `exception_file`,
  `exception_line` and a ten-frame `exception_trace`.
- `persist` events carry `movie_code` next to `is_new`, `sharp_drop` and `completeness_tier`.

Never sent: cookies, tokens, passwords, API keys, authorization headers, HTML bodies, query strings and fragments of
page addresses. Free text is masked by `TelemetrySanitizer::sanitizeText` and cut to a safe length.


## Dashboard: JAVMETA Crawl debug

`deploy/observability/dashboards/crawl-debug.json` (imported by `php artisan obs:import`) shows, from the `javmeta_logs`
stream: fetches per minute by source; per source the fetch count, success rate and p95 duration; failed fetches by error
code; the last 20 failed fetches with source, error code, HTTP status, method, page and message; the last 20 errors with
exception class, message, file and line; crawl events by kind; new versus updated movies per minute; and the adaptive
request gap per source (metric `javmeta_source_gap_seconds`).

## Fetch attempts

`crawl_fetch_attempts` stores one row per attempted request or visited skip from
CrawlerX, for listing, detail, performer and gallery jobs. Successful requests
are included. Older CrawlerX releases without `started_at`, `duration_ms` and
`outcome` are accepted but do not create diagnostic rows. The adapter uses the
existing CrawlerX method-chain option to apply the learned order per source and
host; no package dependency bump is required.

Rows contain the source, optional queue ID, SHA-256 URL hash, method, UTC start,
duration, nullable HTTP status and `cf-mitigated`, outcome, error code and a
500-character error. Raw URLs are redacted from error text. Queue IDs intentionally
have no foreign key so diagnostics survive queue cleanup.

Run `php artisan crawler:fetch-report onejav --hours=24` to see counts by method
and outcome and the top five error texts (including skip reasons). The default
window is 24 hours; an empty result is reported explicitly.
`crawler:prune-fetch-attempts` runs daily and removes only rows strictly older
than `observability.fetch_attempts_retention_days` (default 14), editable on
Cài đặt / Giám sát. Indexes cover `(source_slug, created_at)` for reports and
`created_at` for retention. The migration is reversible and creates an empty
table; it does not scan or backfill the crawl queue.

Diagnostic mapping/storage failures emit a warning containing only the exception
class and queue ID; they do not interrupt normal crawl success/failure handling.
Malformed required fields/outcomes are ignored, and invalid optional status/error
fields become null. Raw exception messages are not logged by the recorder.

## Long-term crawl statistics (JOOXCR-305)

`crawl_stats_hourly` keeps the source/lane/method breakdown after
`crawl_fetch_attempts` is pruned. The hourly scheduler runs
`php artisan crawler:stats-rollup`; it recomputes the current and previous two
UTC hour buckets, upserts by `(hour_at, source_slug, lane, method)`, and prunes
hourly rows older than `stats.retention_days` (default 365). The
`stats.enabled` and `stats.retention_days` values are database settings shown
on Cài đặt. Run `php artisan crawler:stats-rollup --since="2026-11-01 00:00:00"`
to backfill from attempt data that still exists; it cannot recreate attempts
already removed by raw-attempt retention.

Each attempt is counted in exactly one outcome bucket. `skipped` takes
precedence, then HTTP 403/429 or a Cloudflare challenge is `blocked`, timeout
status/error codes are `timeout`, successful outcomes are `ok`, and remaining
errors are `other_error`. Average duration includes skipped attempts. P90 uses
the nearest-rank 90th percentile. Legacy attempt rows without a lane are stored
under `unknown`. Per-attempt byte counts are not currently captured; the global
row stores proxy bytes from `crawl_runs` when available.

The global sentinel row stores new/updated movie counters from `crawl_runs`,
new performers by `first_seen_at`, and failed queue jobs by `failed_at`. Queue
ready, delayed and reserved depth averages, browser-family busy ratios, and
PostgreSQL connection averages come from minute samples. Busy ratio is the
non-skipped method duration in the preceding minute divided by configured
browser slots and 60 seconds, capped at 1. Node CPU is null because no existing
node CPU collector is available. A missing average means there were no samples.

Run `php artisan crawler:stats-report --hours=24 --by=source` for source totals.
`--by=method`, `--by=lane` and `--by=hour` change the grouping, and
`--source=onejav` filters to one source. The first table reports attempts,
success ratio, error classes, average duration and the maximum stored p90 among
the underlying source/lane/method slices; it labels this as “Max slice p90”
because p90 values cannot be combined into a new exact percentile. The second
table shows the hourly movie and global operational trend.

### Questions this data can answer

- **What was the best hour and why?** Run
  `crawler:stats-report --hours=168 --by=hour`. Compare new/updated movies,
  success, blocked/timeouts and queue/browser averages. A productive hour with
  low errors and spare browser capacity is a strong candidate; the report shows
  correlation, not a causal explanation.
- **Which source degraded after a deploy?** Run
  `crawler:stats-report --hours=48 --source=onejav --by=hour` for affected
  sources and compare the hours before and after the deployment timestamp.
  Lower success with more blocked/timeouts identifies the changed source; use
  deployment records to establish the exact release boundary.
- **How much does each method cost?** Run
  `crawler:stats-report --hours=168 --by=method --source=onejav`. Compare
  attempts, average and max-slice p90 milliseconds, and error mix. Method-level
byte cost is unavailable until fetch attempts expose response byte counts.

## Egress IP attribution (JOOXCR-314)

Each Horizon node checks its public IPv4 using the ordered
`observability.egress_probe_urls` list (default: ifconfig.me, icanhazip.com,
checkip.amazonaws.com, api.ipify.org). The request timeout is capped at five
seconds and proxy environment variables are ignored. The result lives in the
shared cache at `javmeta:egress:{node}` for twice the configured probe interval;
workers read this cache while recording an attempt and never wait for a probe.
The scheduler checks every two minutes, and the per-node Horizon reconcile
timer also invokes `node:egress-probe`, so worker nodes do not need a scheduler.
Browser-lane probes navigate to the first echo URL through each configured
Playwright or FlareSolverr host and cache the address per browser host.

The Cài đặt / Giám sát page owns the probe URL list, node interval (120 seconds),
browser interval (900 seconds), timeout (5 seconds), `record_egress_ip` switch
(on by default) and `egress_changes_stream` (default `xcrawler_logs`). The
system-level node_exporter probe is independent and is not used by the app.

When an address changes, one structured record is sent to the configured change
stream with `event=egress_ip_changed`, `node`, `host` (browser host when
applicable), `egress_kind`, `old_ip`, `new_ip` and `changed_at`. Fetch-attempt
rows store nullable `egress_ip`, `node` and `egress_kind` (`node` or
`browser_host`). Crawl log records, events and spans include `node` and
`egress_ip`; an unavailable address remains null in logs/rows and is represented
as an empty OTLP string attribute on spans.

Run `php artisan crawler:egress-status` to see each cached node and browser
host address, probe health, last change time and change count. Run
`php artisan crawler:egress-report --hours=24 --by=egress` to sort IP/source
pairs by blocked attempts; `--by=source` groups source/IP pairs and `--by=node`
adds node to the grouping. The report shows attempts, successful outcomes,
HTTP 403/429 or Cloudflare-mitigated attempts, success ratio and average
duration in milliseconds. `--hours` accepts 1 through 87600.

`obs:publish-metrics` publishes these points once per minute:

| Metric | Labels | Unit / meaning |
| --- | --- | --- |
| `javmeta_node_egress_info` | `node`, `ip` | Gauge 1 while the node has a cached public IP |
| `javmeta_node_egress_changes_total` | `node` | Cumulative address changes observed by this node |
| `javmeta_node_egress_probe_ok` | `node` | Gauge 1 for a successful latest probe, else 0 |
| `javmeta_browser_egress_info` | `host`, `ip` | Gauge 1 while the browser host has a cached public IP |
| `javmeta_fetch_attempts_by_egress` | `source`, `method`, `egress_ip`, `outcome` | Attempt count in the previous minute; unavailable IP is `unknown` |
| `javmeta_block_ratio_by_egress` | `egress_ip` | Blocked / non-skipped attempts in the previous minute, from 0 to 1 |

Compare `javmeta_block_ratio_by_egress` and `crawler:egress-report` across
IPs, sources and methods to see whether blocks follow a particular egress
address. A healthy active node should report `node_egress_probe_ok=1` and one
`node_egress_info` point; the browser info point appears after its first
successful browser-host probe. No fetch attempt is delayed when a probe fails.
