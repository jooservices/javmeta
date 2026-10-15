# Node and container monitoring

This runbook describes the standalone `javmeta-monitoring` Compose project. It
collects host and Docker container metrics on each control or crawler node and
ships them to OpenObserve through Prometheus remote write. It does not change
the application Compose project or deploy application services.

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
