# Node and container monitoring

This runbook describes the standalone `javmeta-monitoring` Compose project. It
collects host and Docker container metrics on each control or crawler node and
ships them to OpenObserve through Prometheus remote write. It does not change
the application Compose project or deploy application services.

## Application errors in Sentry

The application sends errors to the shared Sentry project when
`JAVMETA_SENTRY_DSN` is configured. Keep that value in the private Workspaces
root `.env` or the deployment secret store; do not copy it into this repository,
logs, or Jira. If the DSN is empty, Sentry remains disabled and the application
does not send events.

Errors are sampled at 100%. Trace sampling defaults to zero and can be enabled
with `JAVMETA_SENTRY_TRACES_SAMPLE_RATE`; profiling is disabled. Personal data
collection is disabled. The event scrubber removes request bodies, cookies,
headers, query strings, user context, and arbitrary event extras. Breadcrumbs
retain only allowlisted operational fields.

Set `JAVMETA_ROLE` to `api`, `worker`, `scheduler`, or `mcp` for each runtime and
set `JAVMETA_NODE_NAME` to the unique node name. Sentry events include those
values as tags. Production image builds set `SENTRY_RELEASE` from the Git commit
SHA; `make build` does the same for a local image.

To send a manual test event from a runtime with the shared DSN configured, run:

```bash
php artisan javmeta:sentry-test
```

The command prints the Sentry event ID only. Verify that ID in the `javmeta`
project and filter by `role` and `node`; never include a DSN or event payload in
the verification notes.

## Contract

The monitoring project runs one instance of each service below per node:

| Service | Responsibility | Port | Notes |
|---|---|---:|---|
| `node-exporter` | Host CPU, memory, disk, network, and PSI metrics | `9100/tcp` | Uses the host PID/network view and enables the `pressure` collector. |
| `cadvisor` | Docker container CPU, memory, filesystem, and lifecycle metrics | `8080/tcp` | Requires the documented read-only host mounts. Expose it only for local diagnostics. |
| `prometheus` | Scrapes both exporters every 15 seconds and remote-writes metrics | `9090/tcp` | Agent mode; local storage is not the source of truth. |
| OpenObserve | Stores and queries metrics | `5080/tcp` for the local test service | Production OpenObserve is external and is addressed by `OBS_URL`. |

The exporter and Prometheus ports are node-local operational endpoints. Bind
them to loopback or protect them with the node firewall; they must not be
opened to the public Internet. The local OpenObserve service is only for the
end-to-end test stack.

The stack mounts the host root read-only. Docker Desktop does not expose the
macOS host kernel as a Linux host mount, so node-exporter and cAdvisor report
the Docker Desktop Linux VM; cAdvisor may also have reduced visibility into
host containers. The verification script treats missing PSI as an explicit
kernel limitation.

Prometheus adds these external labels to every series:

```text
node = ${JAVMETA_NODE_NAME}
role = ${JAVMETA_NODE_ROLE}
```

`JAVMETA_NODE_NAME` must be unique per node. `JAVMETA_NODE_ROLE` is either
`control` or `crawler`. Use these labels for all per-node dashboards and
queries; do not infer node identity from a container name or scrape target.
cAdvisor's `name` and Docker Compose container labels identify the container
within that node.

The production remote-write endpoint is:

```text
${OBS_URL}/api/${OBS_ORG}/prometheus/api/v1/write
```

The endpoint and its basic-auth values come from the monitoring environment
file or secret store. Do not put a real URL, username, password, token, or
encoded `Authorization` header in this repository.

## Local start and stop

Run these commands from the repository root. The monitoring files are kept in
`deploy/monitoring/`; the `.env` file is local-only and must remain ignored.

### Start exporters and Prometheus

```bash
cp deploy/monitoring/.env.example deploy/monitoring/.env
# Set local node/role and a test OpenObserve endpoint in deploy/monitoring/.env.
docker compose \
  --project-name javmeta-monitoring \
  --env-file deploy/monitoring/.env \
  --file deploy/monitoring/compose.yml \
  up -d
```

Check the stack without changing it:

```bash
docker compose --project-name javmeta-monitoring \
  --env-file deploy/monitoring/.env \
  --file deploy/monitoring/compose.yml ps
curl -fsS http://127.0.0.1:9090/-/ready
curl -fsS http://127.0.0.1:9100/metrics >/dev/null
# Use the host port published for cAdvisor by the Compose file. It may be
# remapped from container port 8080 when the javmeta API is also local.
docker compose --project-name javmeta-monitoring \
  --env-file deploy/monitoring/.env \
  --file deploy/monitoring/compose.yml \
  port cadvisor 8080
```

For a local end-to-end test, run the repository verification script. The
script owns the local-test Compose lifecycle, including cleanup on failure:

```bash
bash deploy/monitoring/verify.sh
```

The verification script uses host port `15080` for its disposable
OpenObserve instance by default, avoiding the repository's normal local
OpenObserve port `5080`; set `OBS_HOST_PORT` to choose another free port.

Stop the monitoring project without removing its named volumes:

```bash
docker compose --project-name javmeta-monitoring \
  --env-file deploy/monitoring/.env \
  --file deploy/monitoring/compose.yml \
  down
```

Use the same project/env/file options with
`docker compose logs --tail=100 node-exporter cadvisor prometheus` when
diagnosing a scrape or remote-write failure. Do not run `down -v` unless the
local test data is intentionally disposable.

## OpenObserve queries

Use the OpenObserve Metrics view with PromQL and select an appropriate time
range. The examples assume the contract labels are present; replace the
regular expressions with a specific node or role when investigating one
machine.

### CPU PSI

The pressure collector exports the cumulative CPU `some` stall time as
`node_pressure_cpu_waiting_seconds_total`. Convert its five-minute rate to a
percentage of wall-clock time:

```promql
100 * sum by (node, role) (
  rate(node_pressure_cpu_waiting_seconds_total{
    node=~".+",
    role=~"control|crawler"
  }[5m])
)
```

If this query returns no series, check the exporter log and the node kernel.
PSI requires a Linux kernel with PSI enabled; Docker Desktop may expose the
Linux VM kernel differently from the macOS host. The absence of PSI on a test
kernel is a reported limitation, not a reason to invent a replacement metric.

### Memory available per node

Show available host memory in GiB:

```promql
node_memory_MemAvailable_bytes{
  node=~".+",
  role=~"control|crawler"
} / 1024 / 1024 / 1024
```

For one current value per node, use the table view and group by `node` and
`role`. A sustained decrease should be investigated together with
`node_memory_MemTotal_bytes`, container memory usage, and the deployment's
resource limits.

### Container restarts per node

`cAdvisor` exposes each container's start time. Count observed start-time
changes over the previous 24 hours, grouped by node, role, and container:

```promql
sum by (node, role, name) (
  changes(container_start_time_seconds{
    node=~".+",
    role=~"control|crawler",
    name!=""
  }[24h])
)
```

This is a derived restart signal, not a Docker event log. Confirm an incident
with `docker compose ps` and the container logs; a newly discovered series or
a cAdvisor restart can also affect the count. Use the cAdvisor `name` label to
drill into a specific container.

### SQL/API query notes

OpenObserve stores Prometheus remote-write metric families as metric streams.
The stream name is the metric name, and the `node`/`role` labels are fields.
For an API investigation, query the specific metric stream rather than
joining all metrics. Use the metrics search type and a bounded microsecond
time range. OpenObserve 0.92.0 lowercases this stream identifier for the
memory-available example, while the `__name__` field retains the Prometheus
metric name:

```json
{
  "query": {
    "sql": "SELECT _timestamp, value, node, role FROM \"node_memory_memavailable_bytes\" WHERE node = 'NODE_NAME' ORDER BY _timestamp DESC",
    "start_time": 0,
    "end_time": 4102444800000000
  },
  "from": 0,
  "size": 100
}
```

`POST /api/default/_search?type=metrics`

Replace `start_time` and `end_time` with the actual UTC window in
microseconds; OpenObserve rejects an unbounded/future range. Confirm the field names in OpenObserve's stream schema before
automating SQL; PromQL is preferred for dashboards because it preserves the
Prometheus label and range-vector semantics.

## Rollout checklist

### Local verification

- [ ] `docker compose -f deploy/monitoring/compose.yml config -q` passes.
- [ ] The local-test override also passes `docker compose ... config -q`.
- [ ] `verify.sh` confirms node, memory, and cAdvisor series with both `node`
      and `role` labels.
- [ ] PSI is present on Linux, or the verification output explicitly records
      `PSI not available on this kernel`.
- [ ] Prometheus is healthy and its targets are up before checking OpenObserve.
- [ ] The test stack uses local-only placeholder credentials; no production
      endpoint or credential is present in the working tree.

### Production rollout

- [ ] The exact Compose/image revisions and exporter mounts have been reviewed.
- [ ] `JAVMETA_NODE_NAME` is unique and `JAVMETA_NODE_ROLE` is `control` or
      `crawler`.
- [ ] `OBS_URL`, `OBS_ORG`, and the remote-write path have been verified against
      the intended OpenObserve instance.
- [ ] Credentials are injected from the approved environment/secret store and
      are not copied into the repository or shell history.
- [ ] A canary node sends all expected series; CPU PSI may be absent only when
      the kernel limitation is documented.
- [ ] The three queries above return data for the canary with the correct
      `node` and `role` labels.
- [ ] Roll out one node at a time and re-check scrape health, remote-write
      errors, memory, PSI, and restart counts after each node.
- [ ] **The owner has explicitly approved the production rollout before any
      `docker compose up` is run on a production node. Without owner approval,
      stop.**
- [ ] If verification fails, stop the rollout and remove only the monitoring
      project after recording the failing node and query output; do not restart
      or recreate application services as part of this monitoring rollback.

Production rollout is intentionally not performed by this documentation task.

## Versioned dashboards and alert rules

Dashboard and scheduled alert definitions are versioned in
`deploy/observability/`. `php artisan observability:import` creates or updates
the named resources and its Telegram webhook destination; `--dry-run` reads
and validates the files without contacting OpenObserve. The command reads
OpenObserve and Telegram credentials only from environment configuration.

Run the offline plan in the CI/PHP image:

```bash
JAVMETA_CI_SKIP_BUILD=1 docker/ci/run php artisan observability:import --dry-run
```

For live verification, add test-only
`JAVMETA_IT_TELEGRAM_BOT_TOKEN` and `JAVMETA_IT_TELEGRAM_CHAT_ID` values to the
private Workspaces root `.env`, then run the scoped helper twice:

```bash
docker/ci/observability-import-shared
docker/ci/observability-import-shared
```

The helper resolves the `javmeta_it` organization identifier using the
test-only OpenObserve account, runs the command in `javmeta-ci:local`, and
removes its temporary environment file. It does not start a Compose stack.
The second run should update the same named resources without creating
duplicates. Keep Telegram test credentials out of project `.env` files and
source control.

### Failure injection: stale source alert

This injects a single `90,000` second stale-source sample into the
`javmeta_it` organization only. The unique source label makes it easy to
identify in the test organization. Run this after both importer invocations;
the alert checks the metric stream every minute and looks back five minutes.

```bash
python3 - <<'PY'
from datetime import datetime, timezone
from pathlib import Path
from urllib.request import Request, urlopen
from urllib.error import HTTPError
import base64, json, time

values = {}
for line in (Path.home() / 'Workspaces' / '.env').read_text().splitlines():
    if line and not line.lstrip().startswith('#') and '=' in line:
        key, value = line.split('=', 1)
        values[key.strip()] = value.strip().strip('"').strip("'")
email = values['JAVMETA_IT_OPENOBSERVE_EMAIL']
password = values['JAVMETA_IT_OPENOBSERVE_PASSWORD']
auth = 'Basic ' + base64.b64encode(f'{email}:{password}'.encode()).decode()
base = 'http://127.0.0.1:15080'
headers = {'Authorization': auth, 'Accept': 'application/json'}
with urlopen(Request(base + '/api/organizations', headers=headers), timeout=20) as response:
    payload = json.load(response)
organizations = payload if isinstance(payload, list) else payload.get('data', payload.get('organizations', []))
name = values.get('JAVMETA_IT_OPENOBSERVE_ORG', 'javmeta_it')
organization = next(item for item in organizations if item.get('name') == name)
timestamp = time.time_ns()
point = {
    'resourceMetrics': [{
        'resource': {'attributes': [{'key': 'service.name', 'value': {'stringValue': 'javmeta-javm42-test'}}]},
        'scopeMetrics': [{'metrics': [{
            'name': 'javmeta_source_last_success_age_seconds',
            'gauge': {'dataPoints': [{
                'asDouble': 90000,
                'timeUnixNano': str(timestamp),
                'attributes': [{'key': 'source_slug', 'value': {'stringValue': 'javmeta-javm42-failure-test'}}],
            }]},
        }]}],
    }],
}
request = Request(
    base + f"/api/{organization['identifier']}/v1/metrics",
    data=json.dumps(point).encode(), method='POST',
    headers={**headers, 'Content-Type': 'application/json'},
)
try:
    with urlopen(request, timeout=20) as response:
        print('OTLP injection HTTP', response.status)
except HTTPError as error:
    raise SystemExit(f'OTLP injection HTTP {error.code}')
print('Injection UTC', datetime.fromtimestamp(timestamp / 1e9, timezone.utc).isoformat())
PY
```

Wait up to two minutes for `javmeta_source_stale` to fire. Confirm the alert
and delivery in OpenObserve alert history and the dedicated Telegram test
channel, then add the injection and fire UTC timestamps plus the delivery
result to the task record. The injected sample stays inside `javmeta_it`; do
not delete a stream that may contain other integration-test samples.

## Live verification record (2026-10-16, shared OpenObserve org `javmeta_it`)

`JAVMETA_SEED_STREAMS=1 docker/ci/observability-import-shared --strict` published 68 metric points, imported 2 dashboards and
5 of 6 alerts; `javmeta_source_failure_rate` stays pending until a crawl failure creates the
`javmeta_crawl_run_failures_1h` stream (OpenObserve only accepts an alert for an existing stream; the importer reports it and a
rerun completes it). Two further runs created nothing new and updated the same 2 dashboards and 5 alerts (idempotent).
Failure injection: an OTLP point `javmeta_source_last_success_age_seconds{source_slug="qa-injected-source"} = 90000` is returned by the
`javmeta_source_stale` alert query. Delivery to Telegram is not exercised in the test org (placeholder token).
