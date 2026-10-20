# JAVMETA telemetry catalogue

This catalogue is derived from `OpenObserveClient`, `PublishMetricsCommand`,
`WatchdogCommand`, and `AlertDispatcher`. Metric names and the `javmeta_logs`
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
| `javmeta_mongo_archive_write_failures` | — | Cumulative count of failed Mongo archive writes since the cache counter was created. |
| `javmeta_elasticsearch_request_failures` | — | Cumulative count of failed Elasticsearch requests since the cache counter was created. |

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

### Telegram alerts

Telegram settings come from `NOTIFICATION_TELEGRAM_*`; with no token nothing is
sent and nothing fails. Every message starts with `[javmeta:<environment>]` and
names the source where one applies. Each cause alerts at most once per
`JAVMETA_ALERT_THROTTLE_SECONDS` (default 3600) and one recovery message follows
when it clears.

| Cause | Rule | Run by |
| --- | --- | --- |
| Dead source | Enabled source without success for `JAVMETA_WATCHDOG_SOURCE_STALE_SECONDS` | `crawler:watchdog` |
| Stalled crawl | Due pending work but no source success within `JAVMETA_WATCHDOG_CRAWL_STALL_SECONDS` | `crawler:watchdog` |
| Stuck queue | Claimed items older than `JAVMETA_WATCHDOG_QUEUE_STUCK_SECONDS` | `crawler:watchdog` |
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
