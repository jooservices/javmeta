# Runtime Dependencies

JAVMeta application containers start independently and remain available while
dependencies recover. Readiness checks do not gate container startup. The API
container healthcheck uses `/api/health/live`, which reports only whether the
HTTP process is serving requests.

## Dependency policy

`JAVMETA_ROLE` identifies each `api`, `mcp`, `scheduler`, or `worker` container.
The current matrix is the same for every role:

| Dependency | Role | Probe |
| --- | --- | --- |
| PostgreSQL | Hard | `SELECT 1` |
| Redis | Hard when configured | `PING` only when a Redis-backed cache, queue, session, or failover connection is configured |
| Elasticsearch | Soft | `/_cluster/health` |
| MongoDB | Soft | `ping` |
| Embedder | Soft | `/healthz` |
| OpenObserve | Soft | `/healthz` when enabled |

Probes are cached in process for 10 seconds. Three consecutive failures open a
circuit for 30 seconds before a half-open retry. Dependency state changes are
written to the application log; OpenObserve state changes go to stderr while
OpenObserve is down.

## API health and route behavior

- `GET /api/health/live` checks process liveness only and is not gated by a
  dependency.
- `GET /api/health` reports each dependency's hard/soft role, state, and
  availability. It returns HTTP 503 when a hard dependency is unavailable and
  HTTP 200 with a degraded status when only soft dependencies are unavailable.
- Movie search requires PostgreSQL and Elasticsearch. PostgreSQL-backed data
  routes, including performer listing/search and movie lookup, require
  PostgreSQL.
- A route blocked by `requires:<dependency>` returns HTTP 503 in the standard
  API error envelope with `Retry-After: 30`.

## Worker behavior

Queue workers keep running while a hard dependency is down, but pause job
reservation through Laravel's queue loop. They resume automatically after hard
dependencies recover. Soft dependency outages do not pause the worker.

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
