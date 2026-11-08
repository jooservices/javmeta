# PostgreSQL application connection budget

The application bounds the maximum PostgreSQL connections reserved for the
Horizon fleet. This is an application safeguard; PostgreSQL server settings are
unchanged.

## Evidence and process inventory

The JOOXCR-389 read-only audit dated 2026-11-05 measured 107 `xcrawler` role
sessions against a role limit of 120. Its separate socket snapshot found 110
connections from three crawler-node addresses (`.20`, `.21`, `.22`). Those
snapshots were taken at different times, and the read-only role could not see
other sessions' state, client address, or application name. The ticket's newer
incident evidence reports 130 idle sessions out of 133 total, 47 "too many
connections" errors, and only nine slots of `xcrawler` role headroom. That
headroom implies 111 sessions for the role, 45 above the 66-worker default
estimate. These observations do not identify the exact owning processes.

The production node templates run Horizon on the primary and worker nodes. The
primary also runs one `schedule:work` process and PHP-FPM (`pm.max_children=12`);
two worker nodes run Horizon without another scheduler. The reconcile timer
launches a short-lived `horizon:reconcile-workers` command once per minute.
There is no Reverb service or Reverb configuration in this repository. FPM
requests and scheduled/control commands can use PostgreSQL, but are not
long-lived Horizon workers; the worker budget reserves capacity for them and
other role clients.

## Configured demand

`config/javmeta_queues.php` and `config/horizon.php` define 17 queue supervisors:
six standard/search queues at one process each, five browser queues at two
processes each, five retry queues at one process each, and one observability
queue at one process. That is `6×1 + 5×2 + 5×1 + 1×1 = 22` processes per
Horizon node. The fleet includes two worker nodes and one primary node, so the
code-default maximum is `22×3 = 66` simultaneous worker PostgreSQL slots if
every process has opened its connection. This is 41 slots below the 107
`xcrawler` sessions in the JOOXCR-389 snapshot and 45 slots below the 111
implied by newer role-headroom evidence. Those are separate, unsynchronized
observations. The excess cannot be attributed from the available session
metadata.

The current database configuration defines one PostgreSQL connection, `pgsql`,
with no `read`/`write` pools and no `pgsql_read` alias. The current maximum is
therefore one PostgreSQL slot per worker process. `PostgresConnectionBudget`
counts every configured named PostgreSQL connection and one additional slot
when a connection has a Laravel `read` or `write` pool. This keeps the estimate
conservative if another alias or pool is introduced later.

Worker settings can raise each of the 17 queues to 32 processes. Unbounded by a
fleet budget, that is up to `17×32×3 = 1,632` worker slots. The configured role
limit is 120 and the non-worker reserve is 30, leaving a hard worker budget of
90. Defaults use 66 slots. `HorizonWorkerLayout::apply()` falls back to the
code-default layout if saved settings exceed this budget; the reconcile loop
refuses a scale whose fleet demand exceeds it. These checks cover the primary
and two worker-node profiles, including role-wide and host-specific
overrides. Do not reduce the reserve without a new connection inventory.

The warning threshold is 75% of the `xcrawler` role limit (90 slots by default),
configured by `javmeta_alerts.postgres_connection_budget_warning_percent`.
`GET /metrics` exposes Prometheus text gauges for configured demand, role limit,
safe worker budget, warning threshold, and warning state. `obs:publish-metrics`
publishes the same values to OpenObserve. Demand is a configured fleet maximum,
not a live PostgreSQL session count or process attribution. The versioned
OpenObserve alert `javmeta_postgres_connection_demand_warning` fires at the
warning threshold.

Set these bootstrap values in the private application environment:

```dotenv
JAVMETA_POSTGRES_ROLE_CONNECTION_LIMIT=120
JAVMETA_POSTGRES_CONNECTION_RESERVE=30
JAVMETA_POSTGRES_PRIMARY_NODE_COUNT=1
JAVMETA_POSTGRES_WORKER_NODE_COUNT=2
```

The role limit must match the PostgreSQL role's configured limit. The node
counts must match the deployed Horizon topology; set primary node count to zero
only when the primary does not run Horizon. Invalid values fail closed.

## Releasing idle connections

Laravel's queue `Looping` event runs between completed jobs and before the next
job is reserved. After all other queue-loop listeners run, the application
disconnects every configured PostgreSQL connection with no active transaction.
An active transaction is kept open and logged. This releases a worker's idle
PDO between jobs while allowing Laravel to reconnect lazily for its next job.
The scheduler also disconnects idle PostgreSQL connections after each
scheduled task succeeds or fails. A job that never uses PostgreSQL does not
open a connection merely because the cleanup listener runs.

Horizon master and supervisor processes also open PostgreSQL at boot when
`SettingsService::apply()` runs, then never query again during steady-state
loops. The same disconnect listener runs on Horizon's `SupervisorLooped` and
`MasterSupervisorLooped` events so those long-lived processes release the idle
PDO between loops and reconnect lazily if a later restart or terminate path
needs settings. The long-lived `schedule:work` parent (which only spawns
`schedule:run` children) disconnects on `CommandStarting` after settings are
applied at command start, so it does not hold a boot-time idle connection for
its whole lifetime.

The local integration test uses real PostgreSQL connections to verify that an
idle default and named connection are released while a connection with an
active transaction remains open. The test also exercises successful and
failed scheduled-task events, Horizon loop events, schedule:work startup, and
lazy reconnect after a supervisor loop release.

No production database was changed for this application fix. PostgreSQL
`max_connections` and the role limit were not changed, and no server restart
was performed.
