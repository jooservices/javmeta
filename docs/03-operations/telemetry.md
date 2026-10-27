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
| `javmeta_source_gap_seconds` | `source_slug` | Current configured source gap. |
| `javmeta_source_last_success_age_seconds` | `source_slug` | Age in seconds since the source's last successful crawl; `-1` means no success is recorded. |
| `javmeta_source_past_due` | `source_slug` | 1 when an enabled source exceeded its manifest cycle plus one scheduler sweep, or global fallback; otherwise 0. |
| `javmeta_crawl_queue_depth` | `status` | Current queue rows by state. |
| `javmeta_crawl_queue_claimed_age_seconds` | — | Age in seconds of the oldest claimed queue row; `-1` means none is claimed. |
| `javmeta_crawl_queue_avg_attempts` | — | Average attempts for pending and failed queue rows. |
| `javmeta_crawl_events_5m` | `kind` | Crawl events created in the previous five minutes. |
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
| `javmeta_dep_up` | `dependency` | Availability of PostgreSQL, Elasticsearch, OpenObserve, and MongoDB (`1` up, `0` down). |
| `javmeta_postgres_connections_used` | - | Active PostgreSQL sessions across the instance. |
| `javmeta_postgres_connections_max` | - | Configured PostgreSQL max_connections. |
| `javmeta_postgres_connection_utilization` | - | Active sessions divided by max_connections; alert threshold is 85% sustained for five minutes. |
| `javmeta_mongo_archive_write_failures` | — | Cumulative count of failed Mongo archive writes since the cache counter was created. |
| `javmeta_elasticsearch_request_failures` | — | Cumulative count of failed Elasticsearch requests since the cache counter was created. |
| `javmeta_search_embedding_backfill_missing` | `entity` | Movie or performer documents found missing from Elasticsearch or without an embedding during the latest backfill run. |
| `javmeta_search_embedding_backfill_fixed` | `entity` | Movie or performer documents successfully indexed with an embedding during the latest backfill run. |

The seven `javmeta_crawl_run_*_1h` streams are emitted from a fixed suffix list
in `PublishMetricsCommand`; no other dynamic metric names are generated.

The `kind=queue_reclaimed_without_start` series on `javmeta_crawl_events_5m`
counts stale queue claims returned to pending before a worker started them.

## Logs and traces

| Signal | OpenObserve endpoint / stream | Source |
| --- | --- | --- |
| Structured application logs | JSON ingestion into `javmeta_logs` (`OPENOBSERVE_STREAM_LOGS`); each record includes `environment` and `role` | `OpenObserveLogHandler`, `ObservabilityEmitter`, and `EmitObservabilityLog`. |
| Application metrics | OTLP `/api/{org}/v1/metrics`; each point includes `environment` and `role` labels | `PublishMetricsCommand`, normally run by the scheduler. |
| HTTP and crawl spans | OTLP `/api/{org}/v1/traces` | `TraceHttpRequest` and `TraceCrawlJob`. |
| Watchdog notifications | Application alert events, not a separate metric stream | `WatchdogCommand` dispatches `WorkerHeartbeatStale` and `CrawlSourceUnhealthy`; listeners use `AlertDispatcher` for Telegram/activity-log fan-out. |

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
`[javmeta:<environment>] [ALERT][<severity>][<OPEN|RESOLVED>]` and name the
source where one applies. Each cause alerts at most once per
`JAVMETA_ALERT_THROTTLE_SECONDS` (default 3600) and sends one recovery message
when it clears. Follow messages and bot replies use product-specific templates.


| Cause | Rule | Run by |
| --- | --- | --- |
| Dead source | Enabled source without success past `JAVMETA_WATCHDOG_SOURCE_STALE_SECONDS`; manifests with a `cycle_delay_seconds` get that cycle plus one fresh-listing sweep | `crawler:watchdog` |
| Stalled crawl | Due pending work but no source success within `JAVMETA_WATCHDOG_CRAWL_STALL_SECONDS` | `crawler:watchdog` |
| Stuck queue | Claimed items older than `JAVMETA_WATCHDOG_QUEUE_STUCK_SECONDS` | `crawler:watchdog` |
| Due pending backlog | Due pending items reach `JAVMETA_WATCHDOG_PENDING_QUEUE_LIMIT` (default 1,000); a recovery message follows below the limit | `crawler:watchdog` |
| Old backup | Last backup older than `JAVMETA_BACKUP_STALE_AFTER_SECONDS` (26 hours) or missing | `javmeta:backup-status` |

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
`outcome` are accepted but do not create diagnostic rows. Deploy this consumer
independently, then upgrade CrawlerX after its diagnostics release is available;
no dependency bump or fallback selection change is included here.

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
