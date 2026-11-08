# Runtime Dependencies

`health:startup` gates batch/worker commands and the native systemd worker
units. PHP-FPM requests consult a shared, short-lived health snapshot before
sessions/auth and return 503 while a primary is down. The API container
healthcheck still uses `/api/health/live`, which reports only process liveness.
The complete policy, startup command and operator steps are in
[startup health](startup-health.md).

The application, production, and CI PHP images include the phpredis 6.3.0
extension, which is required by Composer as `ext-redis`. Redis is available to
the application, while the default queue, cache, and session drivers remain
database-backed.

## Dependency policy

`JAVMETA_ROLE` identifies each `api`, `mcp`, `scheduler`, or `worker` container.
Startup health uses a stricter role policy than the dependency status matrix:

| Dependency | Role | Probe |
| --- | --- | --- |
| PostgreSQL | Hard | `SELECT 1`; startup also verifies timezone is UTC |
| Redis | Hard | `PING` and prefixed key write/read/delete on configured cache, queue, session and Horizon connections |
| Elasticsearch | Hard for web/API; soft for worker/scheduler by default | `/_cluster/health` |
| MongoDB | Hard | `ping` and settings-store read |
| Embedder, Sentry, browser gateway, Telegram, Ollama, qBittorrent, OpenObserve | Soft | Bounded availability or read-only API check when configured/enabled |

The web gate caches a shared startup snapshot for a few seconds. Dependency
status probes remain cached in process for 10 seconds. Three consecutive failures open a
circuit for 30 seconds before a half-open retry. Dependency state changes are
written to the application log; OpenObserve state changes go to stderr while
OpenObserve is down.

Elasticsearch clients use `ELASTICSEARCH_USERNAME` and `ELASTICSEARCH_PASSWORD`
for Basic authentication, or `ELASTICSEARCH_API_KEY` for API key authentication.
When both forms are configured, the API key takes precedence. Failed requests
are logged with their operation and HTTP status code, counted by
`javmeta_elasticsearch_request_failures`, and reported in the Elasticsearch
health dependency as `request_failures` and `last_failure`. The health state
recovers after a successful Elasticsearch operation.

## API health and route behavior

- `GET /api/health/live` checks process liveness only and is not gated by a
  dependency.
- `GET /api/health` reports each dependency's hard/soft role, state, and
  availability. It returns HTTP 503 when a hard dependency is unavailable and
  HTTP 200 with a degraded status when only soft dependencies are unavailable.
- The same endpoint reports `archive.status`, the cumulative
  `archive.failed_writes` count, and `archive.last_failure_at`. An archive write
  failure degrades health until a later archive write succeeds.
- If PostgreSQL is down, archive status is `unknown` and its count and timestamp
  are `null`; the endpoint skips the archive cache lookup on that down path.
- Movie search requires PostgreSQL and Elasticsearch. PostgreSQL-backed data
  routes, including performer listing/search and movie lookup, require
  PostgreSQL.
- A route blocked by `requires:<dependency>` returns HTTP 503 in the standard
  API error envelope with `Retry-After: 30`.

## Worker behavior

Queue workers do not start when a primary dependency is down. A running worker
continues to pause job reservation through Laravel's queue loop when a hard
dependency fails later, then resumes automatically after recovery. Soft
dependency outages do not pause the worker.

## OpenObserve behavior

OpenObserve is optional. Log records use a bounded in-process buffer; overflow
increments the client's dropped-record counter. Shipping uses a circuit breaker
and a maximum 200 ms timeout budget per HTTP request, so telemetry failures do
not hold API work for longer. A failed send is retained in the buffer while
capacity remains.

## Compose configuration

The local and production Compose files set `JAVMETA_ROLE` for each application
service and do not use `ready-check` as an entrypoint gate. Application
containers have no startup dependency on one another. The API healthcheck uses
`/api/health/live`; dependency health is reported separately by `/api/health`.
